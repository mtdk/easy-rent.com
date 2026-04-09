# Day 1 代码改造任务单（认证与权限收口）

> 文档状态：历史（归档参考）
> 口径说明：以 docs/主任务看板.md 与 docs/任务清单.md 为准。


## 目标

在不扩展业务范围的前提下，完成“可登录、可鉴权、可路由访问”的最小闭环，覆盖以下能力：

- 应用启动初始化生效
- 默认路由注册并可分发
- auth 中间件真正拦截未登录访问
- 登录/登出链路闭环
- 基础回归验证可执行

## 关键现状与问题定位

1. 入口未调用应用初始化
- 现状：入口仅 `new Application()` + `run()`，没有执行 `init()`。
- 影响：Session/Auth/Router/Database 等核心服务未初始化。
- 位置：public/app.php

2. 路由未见注册调用
- 现状：Router 提供 `registerDefaultRoutes()`，但未确认在启动时调用。
- 影响：存在“路由未找到”风险。
- 位置：app/Core/Router.php, app/Core/Application.php

3. auth 中间件为空实现
- 现状：`resolveMiddleware()` 当前返回空函数。
- 影响：受保护路由不会真正鉴权。
- 位置：app/Core/Router.php

4. 部分路由依赖的控制器可能缺失
- 现状：默认路由里包含 UserController / Api\UserController。
- 影响：访问相关路由可能报“控制器未找到”。
- 位置：app/Core/Router.php

## 文件级改造清单

### A. 应用启动链路

1. 文件：public/app.php
- [ ] 在 `run()` 前补充 `init()` 调用
- [ ] 读取配置文件（app/config）并传入 `init()`
- [ ] 启动失败时返回统一错误响应

2. 文件：app/Core/Application.php
- [ ] 在 `init()` 中调用 `$this->router->registerDefaultRoutes()`
- [ ] 确保 `init()` 幂等（避免重复注册路由）
- [ ] 数据库连接失败时改为可控降级（仅记录日志，不直接 `die`）

### B. 路由与中间件

3. 文件：app/Core/Router.php
- [ ] 实现 `auth` 中间件：未登录重定向 `/auth/login`
- [ ] 实现 `guest` 中间件：已登录访问登录页时重定向 `/dashboard`
- [ ] `api` 中间件至少返回 JSON 401（未登录）
- [ ] 控制器不存在时返回 404/500 友好错误，避免裸异常

4. 文件：app/Core/helpers.php（如已有 auth/session 助手）
- [ ] 校验 `auth()`、`session()` 在未初始化时的行为（抛清晰异常或返回安全默认值）
- [ ] 统一跳转与 JSON 错误输出助手，减少控制器重复逻辑

### C. 认证控制器收口

5. 文件：app/Controllers/AuthController.php
- [ ] 登录成功返回统一结构：`success/message/redirect`
- [ ] 登录失败区分：账号不存在、密码错误、账户锁定
- [ ] 登出后固定跳转 `/auth/login`
- [ ] 所有 POST 动作确保 CSRF 校验失败返回 403

### D. 回归验证与最小测试

6. 文件：tests/unit（新增）
- [ ] Auth 会话状态测试：登录后 `user_logged_in=true`
- [ ] 未登录访问受保护路由应被拦截

7. 文件：tests/integration（新增）
- [ ] `/auth/login` GET 可访问
- [ ] `/dashboard` 未登录重定向
- [ ] 登录成功后访问 `/dashboard` 返回 200

## 执行顺序（建议）

1. 先修 `public/app.php` + `Application::init()` + 默认路由注册
2. 再修 `Router::resolveMiddleware()` 的 auth/guest/api
3. 然后收口 `AuthController`
4. 最后补最小测试并回归

## 验收标准（Day 1 完成定义）

- [ ] 访问 `/auth/login` 页面正常
- [ ] 未登录访问 `/dashboard` 自动跳转 `/auth/login`
- [ ] 使用有效账号登录后可进入 `/dashboard`
- [ ] 登出后访问 `/dashboard` 再次被拦截
- [ ] 至少 3 条自动化测试通过（unit/integration）

## 风险与规避

1. 控制器缺失导致 500
- 规避：Day 1 仅验证 auth/dashboard/properties/contracts 主链路，UserController/API 路由先不访问或临时降级。

2. 数据库连接不稳定影响登录
- 规避：保留日志并给出友好错误，不在启动阶段直接中断整个应用。

3. 前端 fetch 与后端返回格式不一致
- 规避：统一 JSON 响应结构，固定 `success/message/redirect` 字段。

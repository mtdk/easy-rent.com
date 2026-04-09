# 收租管理系统项目方案（PHP版）

## 项目简介

收租管理系统是一个专为房东和物业管理公司设计的数字化租赁管理平台，基于PHP 8.2 + MariaDB + Bootstrap 5.3技术栈开发。系统旨在解决传统租赁管理中的效率低下、易出错、信息不透明等问题，通过自动化、智能化的方式提升租金收取效率，优化租赁管理流程。

当前版本定位为单机/内网使用的租赁台账系统，重点是日常登记、管理和统计，不包含真实在线转账能力。

**特别说明**：
1. 系统设计为在无互联网连接的内部局域网环境中运行，所有外部资源（Bootstrap、Icons等）均已本地化部署
2. 第一期仅支持管理员和房东两种角色，租客功能作为未来扩展项保留完整接口

## 进度看板

- 主任务看板（唯一口径）：`docs/主任务看板.md`
- 历史拆解与证据核对：`docs/任务清单.md`

## 当前实现状态（Day 1 - Day 7 + P1-A）

### 已完成

- 认证与权限主链路（登录、登出、会话鉴权、中间件拦截）
- 用户信息管理 CRUD（添加、查看、编辑、删除）
- 房产管理 CRUD 闭环（新增、查看、修改、删除）
- 合同管理 CRUD 闭环（创建、查看、修改、删除）
- 房产列表筛选与分页（关键字 + 状态）
- 合同状态字段与校验（pending/active/expired/terminated）
- 合同到期提醒雏形（`/contracts/expiring`）
- 收款与账单闭环（账单生成、逾期刷新、收款记账、收据页面）
- 水电租金公式账单（上月/本月读数、当月用量、单价、费用、总额）
- 创建页自动带出上期读数与实时总额预估
- 批量生成账单自动继承上期水电读数与单价
- 月度账单 CSV 导出（便于台账统计）
- 报表体系（财务/个人/出租率）与 CSV 导出
- 月度对账能力（聚合、趋势、钻取、排序、导出、打印）
- 统一导航稳定性修复（下拉菜单可用、菜单权限按角色正确渲染）
- 个人资料页 `/profile`（含“修改密码”入口与自助修改流程）
- 支付列表体验优化（两行筛选、8列精简展示、查看详情按钮、分页导航）
- 通知中心与通知维护脚本（提醒生成/已读清理）
- 数据备份、恢复、健康检查与回滚历史追踪
- 数据库迁移基础设施（status/up/down + migration 版本化）
- API 最小可用集（报表汇总/通知只读）
- API 令牌鉴权与生命周期管理（CLI + 管理页创建/吊销/轮换）
- API 访问审计与导出（筛选、时间窗、摘要卡片、CSV）
- API 审计保留治理（预览/提交、归档、历史查看、清理）
- 夜间运维总控与风险告警（分级路由、去重冷却、抑制统计、message_id 幂等、抑制率阈值告警）
- 租客域 MVP 只读入口（我的账单/我的通知）
- 测试门禁基础能力（Unit + Integration）

### 计划中（未完全实现）

- 月度台账统计增强（按租客/房屋/合同维度）
- 移动端能力（后续可选扩展）

### 本轮新增（P2 基线自动化）

- 性能压测基线：`./scripts/run-performance-baseline.sh [preview|strict] [base_url] [requests] [concurrency]`
- 安全扫描基线：`./scripts/run-security-baseline.sh [preview|strict] [base_url]`
- P2 一键入口：`./scripts/run-p2-baseline.sh [preview|strict] [base_url]`

说明：
- `preview` 模式用于日常巡检，输出报告但不阻断流程。
- `strict` 模式用于门禁，若阈值不达标会返回非 0 退出码。
- 运行报告会写入 `storage/logs/performance-baseline_*.json` 与 `storage/logs/security-baseline_*.json`。

## 核心功能（第一期）

### 1. 用户管理（两级权限）
- **管理员**：系统最高权限，管理所有功能和用户
- **房东**：房产所有者，管理自有房产和合同
- **租客**：作为未来扩展项，当前版本预留完整接口

### 2. 房产管理
- 房产信息数字化管理
- 本地图片上传与存储
- 文本地址管理（无地图服务）
- 房产状态跟踪（空闲、已租、维修中）
- 权限控制：管理员查看所有，房东仅查看自己的房产

### 3. 合同管理（集成租客信息）
- 合同模板系统
- 合同扫描件上传（替代电子签名）
- 租客信息作为合同字段管理（非独立用户）
- 合同状态管理
- 自动续约提醒
- 合同生命周期管理

### 4. 租金管理（离线适配）
- 自动账单生成
- 线下收款登记管理
- 支付凭证上传
- 逾期费用计算
- 收据生成与打印
- 水电用量公式计算（月用量 = 本月读数 - 上月读数）
- 月总账单汇总（租金 + 水费 + 电费）

### 5. 财务管理
- 收入统计与分析
- 财务报表生成（Excel/PDF）
- 利润分析
- 数据导出功能

### 6. 通知提醒（局域网）
- 站内消息通知（管理员↔房东）
- 租金到期提醒
- 合同到期提醒
- 局域网邮件通知（可选）

### 7. 报表分析
- 出租率统计
- 收入趋势分析
- 租客信息统计（基于合同数据）
- 数据可视化（Chart.js本地）

## 技术架构

### 后端技术栈
- **编程语言**: PHP 8.2+
- **Web服务器**: Apache 2.4+ 或 Nginx 1.20+
- **数据库**: MariaDB 10.6+ (MySQL兼容)
- **缓存**: Redis 6+ (可选，性能优化)
- **会话管理**: PHP原生Session
- **文件存储**: 本地文件系统
- **架构模式**: MVC模式

### 前端技术栈
- **HTML/CSS**: HTML5, CSS3
- **JavaScript**: 原生ES6+
- **CSS框架**: Bootstrap 5.3.0（本地部署）
- **图标库**: Bootstrap Icons 1.11.0（本地部署）
- **图表库**: Chart.js 4.0+（本地部署，可选）

### 开发与部署
- **版本控制**: Git
- **包管理**: Composer (PHP)
- **本地开发**: XAMPP/MAMP 或 Docker
- **生产环境**: Linux服务器 + Apache/Nginx（局域网）

## 扩展接口设计

### 租客功能扩展点
1. **数据库扩展**：
   - 用户表已保留`tenant`角色枚举
   - 合同表已预留`tenant_user_id`字段
   - 权限表预留租客权限代码

2. **代码扩展**：
   - 预留 `/tenant/` 路由前缀
   - 预留 `TenantController` 类结构
   - 预留 `views/tenant/` 目录
   - 代码中添加`// TODO: 租客功能扩展`注释

3. **界面扩展**：
   - 登录页面预留租客登录选项位置
   - 导航菜单预留租客功能入口
   - 样式设计考虑租客界面一致性

## 项目结构

```
easy-rent-php/
├── app/                    # 应用核心代码
│   ├── Controllers/       # 控制器
│   ├── Models/           # 数据模型
│   ├── Services/         # 业务逻辑服务
│   ├── Libraries/        # 自定义类库
│   ├── Helpers/          # 辅助函数
│   └── Config/           # 配置文件
├── public/               # Web可访问目录
│   ├── assets/          # 静态资源（本地化）
│   │   ├── css/         # CSS文件
│   │   │   ├── bootstrap.min.css      # Bootstrap 5.3
│   │   │   ├── bootstrap-icons.css    # Bootstrap Icons
│   │   │   └── custom.css             # 自定义样式
│   │   ├── js/          # JavaScript文件
│   │   │   ├── bootstrap.bundle.min.js # Bootstrap JS
│   │   │   ├── chart.min.js           # Chart.js (可选)
│   │   │   └── app.js                 # 自定义JS
│   │   ├── fonts/       # 字体文件
│   │   │   └── bootstrap-icons.woff   # Bootstrap Icons字体
│   │   └── icons/       # 图标文件
│   ├── uploads/         # 上传文件目录
│   │   ├── properties/  # 房产图片
│   │   ├── contracts/   # 合同文件
│   │   └── avatars/     # 用户头像
│   └── index.php        # 入口文件
├── views/               # 视图模板
│   ├── layouts/         # 布局文件
│   │   └── main.php     # 主布局
│   ├── partials/        # 局部视图
│   │   ├── header.php
│   │   ├── sidebar.php
│   │   └── footer.php
│   └── pages/           # 页面视图
│       ├── auth/        # 认证相关
│       ├── properties/  # 房产管理
│       ├── contracts/   # 合同管理
│       └── dashboard/   # 仪表板
├── database/            # 数据库相关
│   ├── migrations/      # 数据库迁移
│   ├── seeds/          # 数据填充
│   └── schema.sql      # 数据库结构
├── tests/              # 测试文件
├── vendor/             # Composer依赖
├── .htaccess           # Apache配置
├── composer.json       # PHP依赖管理
└── README.md           # 项目说明
```

## 开发计划

### 总工期: 11周 (约2.75个月)

| 阶段 | 工期 | 主要任务 |
|------|------|----------|
| 环境搭建与资源准备 | 1周 | 开发环境、本地资源、基础框架 |
| 核心功能开发 | 7周 | 用户管理（两级）、房产管理、合同管理（集成租客信息）、支付功能 |
| 高级功能开发 | 2周 | 报表系统、通知功能、数据导入导出 |
| 测试与部署 | 1周 | 局域网测试、生产部署、用户培训 |

## 局域网环境适配

### 无网络功能替代方案
| 功能 | 互联网方案 | 局域网替代方案 |
|------|------------|----------------|
| 前端资源 | CDN加载 | 本地文件部署 |
| 在线支付 | 第三方支付 | 线下支付记录+凭证上传 |
| 电子签名 | 在线签名服务 | 合同扫描件上传 |
| 地图服务 | 在线地图API | 文本地址描述 |
| 租客自助 | 租客门户网站 | 房东代管理（当前），预留扩展接口 |

### 资源本地化
- Bootstrap 5.3.0：下载到 `public/assets/css/` 和 `public/assets/js/`
- Bootstrap Icons 1.11.0：下载到 `public/assets/css/` 和 `public/assets/fonts/`
- 所有资源使用相对路径引用，确保无外部依赖

## 团队配置

- **PHP后端开发工程师**: 2人
- **前端开发工程师**: 1人
- **测试工程师**: 1人
- **项目经理**: 1人

## 成功指标

### 技术指标
- 系统可用性: ≥99.9%
- 页面加载时间: <3秒
- 外部资源依赖: 0个
- 错误率: <0.1%
- 扩展接口完整性: 100%

### 业务指标
- 租金收取效率提升: ≥50%
- 数据准确率: ≥99.5%
- 用户满意度: ≥90%
- 系统使用率: ≥80%

## 快速开始

### 环境要求
- PHP 8.2+
- MariaDB 10.6+
- Apache 2.4+ 或 Nginx 1.20+
- Composer 2.0+

### 安装步骤
1. 克隆项目仓库
   ```bash
   git clone <repository-url>
   cd easy-rent-php
   ```

2. 安装PHP依赖
   ```bash
   composer install
   ```

3. 配置环境
   ```bash
   cp app/Config/env.example.php app/Config/env.php
   # 编辑env.php文件，配置数据库连接等信息
   ```

4. 初始化数据库
   ```bash
   mysql -u root -p < database/schema.sql
   ```

5. 下载本地资源（Bootstrap 5.3和Icons 1.11）
   ```bash
   # 参考 docs/资源本地化指南.md
   ```

6. 启动开发服务器
   ```bash
   # 使用PHP内置服务器
   php -S localhost:8000 -t public
   ```

7. 运行测试
   ```bash
   ./scripts/run-regression.sh
   ./vendor/bin/phpunit --testsuite "EasyRent Unit Tests"
   ./vendor/bin/phpunit --testsuite "EasyRent Integration Tests"
   ```

   说明：`./scripts/run-regression.sh` 会按顺序执行 `lint + unit + integration`，用于发布前一键回归。

## 当前测试结果

- 总计：36/36 通过
- 覆盖：认证、房产 CRUD、合同 CRUD、收款账单闭环关键路径（含 owner 权限、筛选、读数校验、水电公式、批量继承、CSV 导出与逾期边界）

> 结果时间：2026-04-06

## 文档目录

详细文档请参考以下文件：

1. [收租管理系统方案（更新版）](docs/收租管理系统方案-更新版.md) - 完整的系统设计方案
2. [技术架构设计（更新版）](docs/技术架构设计-更新版.md) - 技术架构和部署方案
3. [核心功能模块设计（更新版）](docs/核心功能模块设计-更新版.md) - 各功能模块的PHP实现设计
4. [数据库设计（更新版）](docs/数据库设计-更新版.md) - 完整的数据库表结构设计
5. [开发计划与时间线（更新版）](docs/开发计划与时间线-更新版.md) - 11周详细开发计划
6. [用户角色调整说明](docs/用户角色调整说明.md) - 用户角色调整详细说明
7. [资源本地化指南](docs/资源本地化指南.md) - Bootstrap资源下载和配置指南
8. [移动端范围评估与实施方案（2026-04-07）](docs/移动端范围评估与实施方案-2026-04-07.md) - 移动端裁剪方案与实施路线

## 部署到局域网

### 部署步骤
1. 准备Linux服务器（Ubuntu/CentOS）
2. 安装PHP、MariaDB、Apache/Nginx
3. 上传项目代码到Web目录
4. 配置数据库连接
5. 设置文件权限
6. 配置虚拟主机
7. 测试系统功能

### 发布与回滚（P1-C）

建议发布流程：
1. 执行一键回归：`./scripts/run-regression.sh`
2. 执行发布前备份：`./scripts/backup-easy-rent.sh <db_user> <db_password> <db_name> <db_host> <db_port> storage/backups`
3. 部署代码
4. 执行数据库迁移：`./scripts/run-migrations.sh up`
5. 重载 Web 服务
6. 执行发布后冒烟验证（登录、账单、合同、通知）

回滚流程（数据库）：
1. 确认需要回滚的备份文件
2. 推荐先执行总控预演（恢复+健康检查）：

```bash
./scripts/run-restore-and-verify.sh preview storage/backups/<backup_file>.sql <base_url> <db_user> <db_password> <db_name> <db_host> <db_port>
```

3. 需要回滚时执行总控提交（恢复+健康检查）：

```bash
./scripts/run-restore-and-verify.sh commit storage/backups/<backup_file>.sql <base_url> <db_user> <db_password> <db_name> <db_host> <db_port>
```

总控脚本会输出执行日志到：`storage/logs/restore-verify_<mode>_<timestamp>.log`
总控脚本会输出结构化报告到：`storage/logs/restore-verify_<mode>_<timestamp>.json`

4. 或单独执行恢复脚本：
   先预览恢复动作：

```bash
./scripts/restore-easy-rent.sh storage/backups/<backup_file>.sql <db_user> <db_password> <db_name> <db_host> <db_port> --dry-run
```

5. 执行恢复命令：

```bash
./scripts/restore-easy-rent.sh storage/backups/<backup_file>.sql <db_user> <db_password> <db_name> <db_host> <db_port> --force
```

6. 或使用原生 mysql 命令恢复：

```bash
mysql -h<db_host> -P<db_port> -u<db_user> -p<db_password> <db_name> < storage/backups/<backup_file>.sql
```

7. 执行恢复后健康检查：

```bash
./scripts/post-restore-health-check.sh <base_url> <db_user> <db_password> <db_name> <db_host> <db_port>
```

8. 重新执行冒烟验证，确认业务恢复

回滚历史查看：

```bash
./scripts/show-restore-history.sh 10
./scripts/show-restore-history.sh 20 --failed-only
./scripts/show-restore-history.sh 30 --status=success --mode=preview
./scripts/show-restore-history.sh 10 --show-paths
./scripts/show-restore-history.sh 50 --summary
./scripts/show-restore-history.sh 50 --status=all --mode=all --csv=storage/logs/restore-history.csv
./scripts/show-restore-history.sh 50 --status=failed --mode=commit --json
./scripts/show-restore-history.sh 50 --status=all --mode=all --json-file=storage/logs/restore-history.json
```

回滚历史清理（避免日志长期堆积）：

```bash
./scripts/cleanup-restore-history.sh preview 30 50
./scripts/cleanup-restore-history.sh commit 30 50
```

发布检查项请参考：`docs/发布检查单-v1-2026-04-06.md`

运维巡检（P2）建议新增一步：

```bash
./scripts/run-ops-health-check.sh preview
./scripts/run-ops-health-check.sh commit http://127.0.0.1:8080 <db_user> <db_password> easy_rent 127.0.0.1 3306 24
```

巡检产物：
- `storage/logs/ops-health-check_<mode>_<timestamp>.log`
- `storage/logs/ops-health-check_<mode>_<timestamp>.json`

运维与培训文档：
- `docs/部署运维手册-2026-04-07.md`
- `docs/用户操作手册-2026-04-07.md`
- `docs/用户培训材料-2026-04-07.md`
- `docs/版本完成判定说明-2026-04-07.md`
- `docs/发布包清单-2026-04-07.md`

### 数据库迁移（P1-D）

迁移文件约定：
- `database/migrations/<name>.up.sql`
- `database/migrations/<name>.down.sql`

建议命名：`YYYYMMDD_HHMMSS_description`

常用命令：

```bash
./scripts/run-migrations.sh status
./scripts/run-migrations.sh up --step=1
./scripts/run-migrations.sh down --step=1
```

也可直接使用 PHP 命令：

```bash
php ./scripts/migrate-easy-rent.php status
php ./scripts/migrate-easy-rent.php up --step=1
php ./scripts/migrate-easy-rent.php down --step=1
```

### API 最小可用集（P1-E）

当前开放为登录态下的只读接口：

```bash
GET /api/reports/financial-summary?period=2026-04
GET /api/reports/occupancy-summary?city=上海&status=occupied
GET /api/notifications?filter=unread&type=reminder&priority=high&page=1&per_page=20
GET /api/notifications/unread-count
```

说明：
- 管理员可查看全量数据，房东仅查看本人数据。
- 接口返回统一 JSON 结构，字段包含 success / filters / summary / items / pagination 等。

API 令牌鉴权与访问审计（P1-G）：

1. 执行迁移（创建 `api_tokens`、`api_access_logs`）：

```bash
./scripts/run-migrations.sh up
```

2. 为指定用户创建令牌：

```bash
php ./scripts/create-api-token.php <user_id> [token_name] [expires_days]
```

3. 调用 API（推荐 Authorization 头）：

```bash
curl -H "Authorization: Bearer <token>" "http://127.0.0.1:8080/api/reports/financial-summary?period=2026-04"
```

4. token 生命周期管理：

```bash
php ./scripts/list-api-tokens.php [user_id] [all]
php ./scripts/revoke-api-token.php <token_id>
php ./scripts/rotate-api-token.php <token_id> [expires_days]
```

5. Web 管理页：

```bash
GET /api-tokens
GET /api-access-logs
GET /api-access-logs/export?auth_type=token&start_at=2026-04-01&end_at=2026-04-30
```

说明：
- API 鉴权支持 `Authorization: Bearer <token>`、`X-API-Token`、`api_token` 查询参数。
- API 访问会记录到 `api_access_logs`（认证方式、状态码、路径、IP、UA）。
- 若已有会话登录，也可继续以 session 方式访问 API。
- 管理员也可在 Web 端使用 `GET /api-tokens` 进行创建、禁用、轮换。
- 管理员可在 `GET /api-access-logs` 中按 user/token/status/auth/path 进行审计筛选。
- 审计页支持 `start_at` / `end_at` 时间范围筛选，并可按当前筛选条件导出 CSV。
- 审计页提供快捷时间范围（今日/近24小时/近7天）和汇总看板（2xx/4xx/5xx、认证方式分布、Top 路径）。

常见错误排查：
- `错误: token 不存在`：先执行 `php ./scripts/list-api-tokens.php [user_id] all` 确认真实 `token_id`。
- `token 已是失效状态，无需重复操作`：该 token 已禁用，可直接创建新 token 或轮换其它 active token。
- `错误: 用户状态不是 active`：请先在用户管理将账号状态恢复为 `active`，再创建/轮换 token。
- API 返回 401 且 message 为 `API访问未授权`：确认 Header 是否为 `Authorization: Bearer <token>`，并检查 token 是否过期/失效。

### 租客域 MVP（P1-F）

当前已提供登录态只读入口：

```bash
GET /tenant/bills
GET /tenant/notifications
```

说明：
- 仅 `tenant` 角色可访问；其他角色返回 403。
- 账单通过租客姓名/手机号/邮箱与合同字段进行匹配展示。
- 该版本为最小闭环，不含在线支付。

### 维护建议
- 每日数据库备份
- 定期检查系统日志
- 监控磁盘空间使用
- 定期安全更新

### 通知维护脚本

用于自动生成合同到期提醒，并定期清理历史已读通知。

1. 预览模式（不写入）
   - `./scripts/run-notification-maintenance.sh preview 30 30`
2. 执行模式（实际写入/清理）
   - `./scripts/run-notification-maintenance.sh commit 30 30`

参数说明：
- 第1个参数：`preview` 或 `commit`
- 第2个参数：提醒阈值天数（例如 30，表示扫描未来30天内到期合同）
- 第3个参数：已读通知保留天数（例如 30，表示清理30天前已读通知）

示例（每日凌晨2点执行一次）：
- `0 2 * * * cd /opt/homebrew/var/www/easy-rent.com && ./scripts/run-notification-maintenance.sh commit 30 30 >> storage/logs/notification-maintenance.log 2>&1`

每次执行会自动生成日志与结构化报告：
- 日志：`storage/logs/notification-maintenance_<mode>_<timestamp>.log`
- 报告：`storage/logs/notification-maintenance_<mode>_<timestamp>.json`
- 失败告警文件：`storage/logs/alerts/notification-maintenance-alert_failed_<timestamp>.json`

可选 webhook 告警（任务失败时触发）：
- `ALERT_WEBHOOK_URL=https://example.internal/webhook ./scripts/run-notification-maintenance.sh commit 30 30`

### API 审计日志保留脚本

用于对 `api_access_logs` 执行“先归档后清理”的保留策略。

1. 预览模式（不写入、不删除）
   - `./scripts/run-api-access-log-retention.sh preview 90 storage/logs/api-access-archives`
2. 执行模式（归档 CSV + 删除过期日志）
   - `./scripts/run-api-access-log-retention.sh commit 90 storage/logs/api-access-archives`

参数说明：
- 第1个参数：`preview` 或 `commit`
- 第2个参数：保留天数（例如 90，表示清理 90 天前日志）
- 第3个参数：归档目录（默认 `storage/logs/api-access-archives`）
- 每次执行会自动生成日志与结构化报告：
   - 日志：`storage/logs/api-access-retention_<mode>_<timestamp>.log`
   - 报告：`storage/logs/api-access-retention_<mode>_<timestamp>.json`
   - 失败告警文件：`storage/logs/alerts/api-access-retention-alert_failed_<timestamp>.json`

可选 webhook 告警（任务失败时触发）：
- `ALERT_WEBHOOK_URL=https://example.internal/webhook ./scripts/run-api-access-log-retention.sh commit 90 storage/logs/api-access-archives`

示例（每日凌晨3点执行一次）：
- `0 3 * * * cd /opt/homebrew/var/www/easy-rent.com && ./scripts/run-api-access-log-retention.sh commit 90 storage/logs/api-access-archives >> storage/logs/api-access-retention.log 2>&1`

历史查看：
- `./scripts/show-api-access-retention-history.sh 20 --status=all --mode=all --summary`
- `./scripts/show-api-access-retention-history.sh 50 --status=success --mode=commit --csv=storage/logs/api-access-retention-history.csv`
- `./scripts/show-api-access-retention-history.sh 50 --json --json-file=storage/logs/api-access-retention-history.json`
- `./scripts/show-api-access-retention-history.sh 50 --from=2026-04-01 --to=2026-04-30 --summary`
- `./scripts/show-api-access-retention-history.sh 30 --summary --trend-window=10 --fail-rate-threshold=0.30`
- `./scripts/show-api-access-retention-history.sh 30 --summary --trend-window=10 --fail-rate-threshold=0.10 --emit-risk-alert`

告警摘要（summary 中自动包含）：
- `consecutive_failed_from_latest`
- `latest_failed_timestamp`
- `latest_failed_report`

失败趋势统计（summary / JSON 中自动包含）：
- `trend_sample_size`
- `trend_failed`
- `recent_failure_rate`
- `fail_rate_threshold`
- `fail_rate_threshold_breached`
- `risk_level`（none/warning/critical）
- `risk_alert_file`
- `risk_notification_message_id`
- `risk_webhook_pushed`

风险告警触发（历史脚本，可选）：
- `--emit-risk-alert`：当失败率触发阈值时落盘风险告警文件
- `--risk-alert-dir=<path>`：风险告警目录（默认 `storage/logs/alerts`）
- `--risk-webhook-url=<url>`：风险告警 webhook（或使用环境变量 `RISK_ALERT_WEBHOOK_URL`）
- 风险告警 JSON 会包含 `notification_message_id`，用于下游幂等追踪

保留历史清理：
- `./scripts/cleanup-api-access-retention-history.sh preview 30 50`
- `./scripts/cleanup-api-access-retention-history.sh commit 30 50`

历史清理：
- `./scripts/cleanup-nightly-maintenance-history.sh preview 30 50`
- `./scripts/cleanup-nightly-maintenance-history.sh commit 30 50`

### 夜间运维总控脚本

将通知维护与 API 审计日志保留合并为统一入口：

1. 预览模式（推荐先执行）
   - `./scripts/run-nightly-maintenance.sh preview 30 30 90 storage/logs/api-access-archives 1 3`
2. 执行模式（计划任务）
   - `./scripts/run-nightly-maintenance.sh commit 30 30 90 storage/logs/api-access-archives 2 5`

新增参数：
- 第6个参数：重试次数（`retry_count`，默认 0）
- 第7个参数：重试间隔秒数（`retry_delay_seconds`，默认 3）

环境变量（风险评估，可选）：
- `RISK_EVALUATION_ENABLED=true|false`（默认 `true`）
- `RISK_TREND_WINDOW=10`（默认 10）
- `RISK_FAIL_RATE_THRESHOLD=0.30`（默认 0.30）
- `RISK_ALERT_DIR=storage/logs/alerts`（默认沿用 `ALERT_DIR`）
- `RISK_ALERT_WEBHOOK_URL=https://example.internal/webhook`（默认沿用 `ALERT_WEBHOOK_URL`）
- `RISK_WARNING_WEBHOOK_URL=https://example.internal/risk-warning`（warning 级别专用）
- `RISK_CRITICAL_WEBHOOK_URL=https://example.internal/risk-critical`（critical 级别专用）
- `RISK_NOTIFY_ON_INCREASE_ONLY=true|false`（默认 `true`，仅当总体风险等级上升时推送）
- `RISK_NOTIFICATION_COOLDOWN_MINUTES=60`（默认 60；同等级通知冷却窗口）
- `RISK_NOTIFICATION_COOLDOWN_PREVIEW_MINUTES=30`（可选；覆盖 preview 模式冷却）
- `RISK_NOTIFICATION_COOLDOWN_COMMIT_MINUTES=120`（可选；覆盖 commit 模式冷却）
- `RISK_SUPPRESSION_STATS_WINDOW=30`（默认 30；统计最近 N 份总控报告的抑制分布）
- `RISK_NOTIFICATION_MESSAGE_ID_PREFIX=risk`（默认 risk；统一 notification_message_id 前缀）
- 前缀校验：仅支持 1-32 位字母数字及 `._-`
- `RISK_SUPPRESSION_RATIO_ALERT_ENABLED=true|false`（默认 `true`；是否启用抑制率阈值告警）
- `RISK_SUPPRESSION_RATIO_ALERT_THRESHOLD=0.50`（默认 0.50；超过阈值触发抑制率告警）

产物：
- 日志：`storage/logs/nightly-maintenance_<mode>_<timestamp>.log`
- 报告：`storage/logs/nightly-maintenance_<mode>_<timestamp>.json`
- 失败告警文件：`storage/logs/alerts/nightly-maintenance-alert_failed_<timestamp>.json`
- 失败主告警文件包含 `notification_message_id`（并作为 webhook 的 `X-Message-Id` / `X-Idempotency-Key`）
- 报告中会聚合子任务产物路径：
   - `subtask_artifacts.notification.log_file/report_file`
   - `subtask_artifacts.api_access_retention.log_file/report_file`
- 报告中会输出子任务耗时分解：
   - `subtask_durations.notification_seconds`
   - `subtask_durations.api_access_retention_seconds`
- 报告中会输出自动风险评估结果（risk_assessment）：
   - `api_access_retention_history.risk_level/threshold_breached/risk_alert_file`
   - `nightly_maintenance_history.risk_level/threshold_breached/risk_alert_file`
   - `overall.risk_level/risk_rank/previous_risk_level/previous_risk_rank`
   - `overall.notify_on_increase_only/notification_webhook_target/notification_webhook_pushed`
   - `overall.notification_message_id`（webhook 推送时同时作为 `X-Message-Id` 和 `X-Idempotency-Key`）
   - `overall.notification_cooldown_minutes/effective_notification_cooldown_minutes/notification_cooldown_active`
   - `overall.notification_suppressed/notification_suppressed_reason/notification_suppressed_reason_code`
   - `overall.suppression_stats_window/suppression_stats.*`（含 `suppression_stats.suppressed_ratio` 与 `suppression_stats.by_mode.*.suppressed_ratio`）
   - `overall.suppression_ratio_alert.enabled/threshold/current_ratio/breached/consecutive_breaches_from_latest/alert_file/notification_message_id/webhook_pushed`

可选 webhook 告警（总控失败时触发）：
- `ALERT_WEBHOOK_URL=https://example.internal/webhook ./scripts/run-nightly-maintenance.sh commit 30 30 90 storage/logs/api-access-archives 2 5`

示例（每日凌晨2点执行一次总控）：
- `0 2 * * * cd /opt/homebrew/var/www/easy-rent.com && ./scripts/run-nightly-maintenance.sh commit 30 30 90 storage/logs/api-access-archives 2 5 >> storage/logs/nightly-maintenance-cron.log 2>&1`

夜间总控历史查看：
- `./scripts/show-nightly-maintenance-history.sh 20 --summary`
- `./scripts/show-nightly-maintenance-history.sh 50 --status=failed --json`
- `./scripts/show-nightly-maintenance-history.sh 50 --from=2026-04-01 --to=2026-04-30 --csv=storage/logs/nightly-maintenance-history-window.csv`
- `./scripts/show-nightly-maintenance-history.sh 30 --summary --trend-window=10 --fail-rate-threshold=0.30`
- `./scripts/show-nightly-maintenance-history.sh 30 --summary --trend-window=10 --fail-rate-threshold=0.10 --emit-risk-alert`

夜间总控历史清理：
- `./scripts/cleanup-nightly-maintenance-history.sh preview 30 50`
- `./scripts/cleanup-nightly-maintenance-history.sh commit 30 50`

## 未来扩展规划

### 版本2.0（租客功能扩展）
- 租客注册和登录功能
- 租客个人中心
- 租客账单查看
- 租客通知系统

### 版本2.1（高级功能）
- 租客维修申请
- 租客评价系统
- 移动端适配

### 项目最后可选任务（扩展模式）
- 租客在线支付与交互流程（当前版本不支持，仅作为未来扩展）

### 版本3.0（企业级功能）
- 多语言支持
- 高级权限管理
- 工作流引擎
- API开放接口

## 联系方式

如有任何问题或建议，请联系项目团队。

---

**项目状态**: 单机内网版可用，持续迭代中  
**技术栈**: PHP 8.2 + MariaDB + Bootstrap 5.3（本地化）  
**用户角色**: 管理员、房东（租客为未来扩展）  
**网络环境**: 纯局域网部署  
**版本**: 3.0.0  
**最后更新**: 2026年4月6日
# 历史备份差异索引（2026-04-07 -> 当前仓库）

- 对比来源：/Users/xmtdk/app/easy-rent.com-backup-20260407_210639.tar.gz
- 对比时间：2026-04-09
- 去噪规则：已排除 `._*` 与 `__MACOSX` 元数据文件
- 对比范围：app/Controllers、app/Core、database/migrations、scripts（路径级差异）

## 汇总

| 目录 | 备份文件数 | 当前文件数 | 当前新增 | 备份独有(当前缺失) | 共同文件 |
|---|---:|---:|---:|---:|---:|
| app/Controllers | 13 | 15 | 2 | 0 | 13 |
| app/Core | 9 | 9 | 0 | 0 | 9 |
| database/migrations | 6 | 14 | 8 | 0 | 6 |
| scripts | 36 | 36 | 0 | 0 | 36 |

## 关键结论

- 当前版本在计量与财务能力上持续扩展：多表计、支出管理、计量类型管理等。
- 当前迁移数量高于备份版本，说明数据库结构已完成多轮演进。
- scripts 目录总体文件数接近，但当前脚本以回归、运维与审计链路为主。

## app/Controllers 差异样例

### 当前新增（最多 20 项）

- app/Controllers/ExpenseController.php
- app/Controllers/SettingsController.php

### 备份独有（最多 20 项）

- 无

## app/Core 差异样例

### 当前新增（最多 20 项）

- 无

### 备份独有（最多 20 项）

- 无

## database/migrations 差异样例

### 当前新增（最多 20 项）

- database/migrations/20260408_120000_add_multi_meter_billing.down.sql
- database/migrations/20260408_120000_add_multi_meter_billing.up.sql
- database/migrations/20260408_173000_create_expense_categories.down.sql
- database/migrations/20260408_173000_create_expense_categories.up.sql
- database/migrations/20260408_180000_scope_expense_categories_by_owner.down.sql
- database/migrations/20260408_180000_scope_expense_categories_by_owner.up.sql
- database/migrations/20260409_090000_create_meter_types_and_expand_meter_type_values.down.sql
- database/migrations/20260409_090000_create_meter_types_and_expand_meter_type_values.up.sql

### 备份独有（最多 20 项）

- 无

## scripts 差异样例

### 当前新增（最多 20 项）

- 无

### 备份独有（最多 20 项）

- 无

## 备注

- 本索引仅做“路径级基线对比”，不包含逐行 diff。
- 如需精细合并，可在下一步生成按模块的逐文件对比清单。

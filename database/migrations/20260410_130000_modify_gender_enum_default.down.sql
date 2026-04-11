-- 恢复 tenants 表 gender 字段到原来的 ENUM 和默认值
ALTER TABLE tenants
MODIFY COLUMN gender ENUM('男','女','未知') NOT NULL DEFAULT '未知' COMMENT '性别';

-- 恢复 tenant_cohabitants 表 gender 字段到原来的 ENUM 和默认值
ALTER TABLE tenant_cohabitants
MODIFY COLUMN gender ENUM('男','女','未知') NOT NULL DEFAULT '未知' COMMENT '性别';
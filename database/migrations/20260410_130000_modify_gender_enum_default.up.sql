-- 修改 tenants 表 gender 字段，移除“未知”枚举值，默认值改为“男”
ALTER TABLE tenants
MODIFY COLUMN gender ENUM('男','女') NOT NULL DEFAULT '男' COMMENT '性别';

-- 将现有值为“未知”的记录更新为“男”
UPDATE tenants SET gender = '男' WHERE gender = '未知';

-- 修改 tenant_cohabitants 表 gender 字段，移除“未知”枚举值，默认值改为“男”
ALTER TABLE tenant_cohabitants
MODIFY COLUMN gender ENUM('男','女') NOT NULL DEFAULT '男' COMMENT '性别';

-- 将现有值为“未知”的记录更新为“男”
UPDATE tenant_cohabitants SET gender = '男' WHERE gender = '未知';
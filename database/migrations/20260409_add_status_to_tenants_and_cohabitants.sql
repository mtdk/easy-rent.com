-- 20260409_add_status_to_tenants_and_cohabitants.sql
-- 为租户和共同居住人表添加状态字段
ALTER TABLE tenants ADD COLUMN status ENUM('在住','迁出') NOT NULL DEFAULT '在住' COMMENT '状态' AFTER address;
ALTER TABLE tenant_cohabitants ADD COLUMN status ENUM('在住','迁出') NOT NULL DEFAULT '在住' COMMENT '状态' AFTER address;
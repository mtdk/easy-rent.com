-- 20260409_add_nation_to_tenant_and_cohabitant.sql
-- 为租户和共同居住人表添加“民族”字段
ALTER TABLE tenants ADD COLUMN nation VARCHAR(16) DEFAULT NULL COMMENT '民族' AFTER name;
ALTER TABLE tenant_cohabitants ADD COLUMN nation VARCHAR(16) DEFAULT NULL COMMENT '民族' AFTER name;
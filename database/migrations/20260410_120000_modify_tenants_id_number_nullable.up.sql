-- 将 tenants.id_number 改为可为 NULL，并保留唯一约束（唯一约束对 NULL 值无效）
ALTER TABLE tenants MODIFY COLUMN id_number CHAR(18) NULL COMMENT '身份证号';
-- 将 tenant_cohabitants.id_number 改为可为 NULL
ALTER TABLE tenant_cohabitants MODIFY COLUMN id_number CHAR(18) NULL COMMENT '身份证号';
-- 将 tenants.id_number 改回 NOT NULL，并将 NULL 值替换为空字符串
UPDATE tenants SET id_number = '' WHERE id_number IS NULL;
ALTER TABLE tenants MODIFY COLUMN id_number CHAR(18) NOT NULL COMMENT '身份证号';
-- 将 tenant_cohabitants.id_number 改回 NOT NULL
UPDATE tenant_cohabitants SET id_number = '' WHERE id_number IS NULL;
ALTER TABLE tenant_cohabitants MODIFY COLUMN id_number CHAR(18) NOT NULL COMMENT '身份证号';
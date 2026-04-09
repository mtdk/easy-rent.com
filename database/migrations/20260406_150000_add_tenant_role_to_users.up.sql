ALTER TABLE users
MODIFY COLUMN role ENUM('admin', 'landlord', 'tenant') NOT NULL DEFAULT 'landlord' COMMENT '角色: admin-管理员, landlord-房东, tenant-租客';

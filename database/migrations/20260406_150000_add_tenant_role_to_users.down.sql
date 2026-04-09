UPDATE users SET role = 'landlord' WHERE role = 'tenant';

ALTER TABLE users
MODIFY COLUMN role ENUM('admin', 'landlord') NOT NULL DEFAULT 'landlord' COMMENT '角色: admin-管理员, landlord-房东';

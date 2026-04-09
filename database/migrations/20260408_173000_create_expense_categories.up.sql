CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_expense_categories_name` (`name`),
  KEY `idx_expense_categories_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支出分类字典表';

INSERT INTO `expense_categories` (`name`, `is_active`, `sort_order`) VALUES
('房屋修缮', 1, 10),
('安全维护', 1, 20),
('水路维修', 1, 30),
('电路维修', 1, 40),
('保洁保养', 1, 50),
('管理费用', 1, 60),
('其他支出', 1, 70)
ON DUPLICATE KEY UPDATE
  `is_active` = VALUES(`is_active`),
  `sort_order` = VALUES(`sort_order`);

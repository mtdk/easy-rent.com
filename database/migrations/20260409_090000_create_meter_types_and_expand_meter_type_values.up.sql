CREATE TABLE IF NOT EXISTS `meter_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type_key` VARCHAR(30) NOT NULL COMMENT '类型编码，如 water/electric/gas',
  `type_name` VARCHAR(50) NOT NULL COMMENT '类型名称',
  `default_code_prefix` VARCHAR(20) NOT NULL DEFAULT 'METER' COMMENT '默认编号前缀',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_meter_types_type_key` (`type_key`),
  KEY `idx_meter_types_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `meter_types` (`type_key`, `type_name`, `default_code_prefix`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT 'water', '水表', 'WATER', 10, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `meter_types` WHERE `type_key` = 'water');

INSERT INTO `meter_types` (`type_key`, `type_name`, `default_code_prefix`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT 'electric', '电表', 'ELECTRIC', 20, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `meter_types` WHERE `type_key` = 'electric');

INSERT INTO `meter_types` (`type_key`, `type_name`, `default_code_prefix`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT 'gas', '天然气表', 'GAS', 30, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `meter_types` WHERE `type_key` = 'gas');

ALTER TABLE `contract_meters`
  MODIFY COLUMN `meter_type` VARCHAR(30) NOT NULL;

ALTER TABLE `rent_payment_meter_details`
  MODIFY COLUMN `meter_type` VARCHAR(30) NOT NULL;

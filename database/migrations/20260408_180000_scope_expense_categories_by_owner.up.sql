ALTER TABLE `expense_categories`
  ADD COLUMN `owner_id` INT UNSIGNED NULL COMMENT '所属房东ID，NULL表示系统默认分类' AFTER `id`;

ALTER TABLE `expense_categories`
  DROP INDEX `uk_expense_categories_name`;

ALTER TABLE `expense_categories`
  ADD UNIQUE KEY `uk_expense_categories_owner_name` (`owner_id`, `name`),
  ADD KEY `idx_expense_categories_owner_active_sort` (`owner_id`, `is_active`, `sort_order`),
  ADD CONSTRAINT `fk_expense_categories_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `expense_categories`
  DROP FOREIGN KEY `fk_expense_categories_owner`;

ALTER TABLE `expense_categories`
  DROP INDEX `uk_expense_categories_owner_name`;

ALTER TABLE `expense_categories`
  DROP INDEX `idx_expense_categories_owner_active_sort`;

ALTER TABLE `expense_categories`
  ADD UNIQUE KEY `uk_expense_categories_name` (`name`);

ALTER TABLE `expense_categories`
  DROP COLUMN `owner_id`;

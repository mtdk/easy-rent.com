ALTER TABLE `rent_payments`
  ADD COLUMN `bill_type` ENUM('monthly', 'checkout') NOT NULL DEFAULT 'monthly'
  COMMENT '账单类型：monthly=月度账单，checkout=退租结算'
  AFTER `payment_period`;
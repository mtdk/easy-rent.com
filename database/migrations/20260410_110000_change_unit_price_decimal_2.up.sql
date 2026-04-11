-- 将单价字段精度调整为2位小数（原为4位小数）
ALTER TABLE rent_payment_meter_details MODIFY unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE contract_meters MODIFY default_unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00;
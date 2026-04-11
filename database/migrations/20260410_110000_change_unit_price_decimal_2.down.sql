-- 恢复单价字段为4位小数精度
ALTER TABLE rent_payment_meter_details MODIFY unit_price DECIMAL(10,4) NOT NULL DEFAULT 0.0000;
ALTER TABLE contract_meters MODIFY default_unit_price DECIMAL(10,4) NOT NULL DEFAULT 0.0000;
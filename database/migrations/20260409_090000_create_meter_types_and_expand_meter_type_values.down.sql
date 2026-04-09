UPDATE `contract_meters`
SET `meter_type` = 'water'
WHERE `meter_type` NOT IN ('water', 'electric');

UPDATE `rent_payment_meter_details`
SET `meter_type` = 'water'
WHERE `meter_type` NOT IN ('water', 'electric');

ALTER TABLE `contract_meters`
  MODIFY COLUMN `meter_type` ENUM('water', 'electric') NOT NULL;

ALTER TABLE `rent_payment_meter_details`
  MODIFY COLUMN `meter_type` ENUM('water', 'electric') NOT NULL;

DROP TABLE IF EXISTS `meter_types`;

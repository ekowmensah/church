-- rollback: 2026_03_21_0105_create_assets_register.sql

ALTER TABLE asset_movements DROP FOREIGN KEY fk_asset_movements_asset;
ALTER TABLE asset_movements DROP FOREIGN KEY fk_asset_movements_from_department;
ALTER TABLE asset_movements DROP FOREIGN KEY fk_asset_movements_to_department;
ALTER TABLE assets DROP FOREIGN KEY fk_assets_department;

DROP TABLE IF EXISTS asset_movements;
DROP TABLE IF EXISTS assets;
DROP TABLE IF EXISTS asset_departments;

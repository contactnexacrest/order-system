-- 2026-11-18b — Owner decision D-381 (18 Nov 2026, confirmed twice): CHA + transport per container per
-- supplier. New empty columns suppliers.cha_transport_per_container (₹, factory → port) and suppliers.cha_port
-- (the port the figure is for). A supplier-price row without its own CHA figure now uses its supplier's,
-- else the product default (row → supplier → product → none); an older 'computed' price never gets the CHA
-- added. Owner decision D-382: new empty columns catalog_product_suppliers.entered_unit / entered_price keep
-- a block price typed per tonne (stored per CBM in the usual fields, as before). No existing price changes:
-- every supplier starts without a figure, so every row works out exactly as before until one is entered.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): phpMyAdmin on the shared host refuses the information_schema +
-- PREPARE guard. A second run only stops with "#1060 Duplicate column name", which is harmless.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
ALTER TABLE suppliers ADD COLUMN cha_transport_per_container DECIMAL(14,2) NULL;
ALTER TABLE suppliers ADD COLUMN cha_port VARCHAR(100) NULL;
ALTER TABLE catalog_product_suppliers ADD COLUMN entered_unit VARCHAR(10) NULL;
ALTER TABLE catalog_product_suppliers ADD COLUMN entered_price DECIMAL(14,2) NULL;

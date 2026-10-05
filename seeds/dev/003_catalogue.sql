-- Development seed data: a small product catalogue with barcodes, and a zero stock row for every
-- site × product (sites seeded by SQL skip SiteService, which creates them). Stock starts at 0:
-- record goods received on the receipt screen, so every unit has its ledger row.
-- PLACEHOLDERS ONLY: the client supplies the real products and barcodes (plan Q13).
-- Barcodes use the GS1 prefix 952, kept for demonstrations, so they never match a real product.
-- Safe to run repeatedly: rows are added only when their natural key is not there yet.

INSERT INTO `item_category` (`name`, `is_banana_box`, `units_per_case`)
SELECT c.`name`, c.`banana`, c.`per_case`
  FROM (SELECT 'Dry dog food' AS `name`, 0 AS `banana`, 1 AS `per_case`
        UNION ALL SELECT 'Dry cat food', 0, 1
        UNION ALL SELECT 'Wet dog food', 0, 12
        UNION ALL SELECT 'Wet cat food', 0, 24
        UNION ALL SELECT 'Treats', 1, 1) c
 WHERE NOT EXISTS (SELECT 1 FROM `item_category` x WHERE x.`name` = c.`name`);

INSERT INTO `product` (`category_id`, `name`, `brand`, `species_id`, `food_form`, `unit_weight_lbs`)
SELECT ic.`category_id`, p.`name`, p.`brand`, sp.`species_id`, p.`form`, p.`lbs`
  FROM (SELECT 'Dry dog food' AS `category`, 'Adult kibble' AS `name`, 'Dev Brand' AS `brand`, 'Dog' AS `species`, 'Dry' AS `form`, 30.00 AS `lbs`
        UNION ALL SELECT 'Dry dog food', 'Adult kibble', 'Dev Brand', 'Dog', 'Dry', 15.00
        UNION ALL SELECT 'Dry dog food', 'Puppy kibble', 'Dev Brand', 'Dog', 'Dry', 4.00
        UNION ALL SELECT 'Dry cat food', 'Indoor cat', 'Dev Brand', 'Cat', 'Dry', 16.00
        UNION ALL SELECT 'Dry cat food', 'Kitten', 'Dev Brand', 'Cat', 'Dry', 3.00
        UNION ALL SELECT 'Wet dog food', 'Chunks in gravy', 'Dev Brand', 'Dog', 'Wet', 0.81
        UNION ALL SELECT 'Wet cat food', 'Pate', 'Dev Brand', 'Cat', 'Wet', 0.34
        UNION ALL SELECT 'Treats', 'Dental chews', 'Dev Brand', 'Dog', 'Treat', NULL) p
  JOIN `item_category` ic ON ic.`name` = p.`category`
  JOIN `species` sp ON sp.`name` = p.`species`
 WHERE NOT EXISTS (SELECT 1 FROM `product` x WHERE x.`name` = p.`name` AND x.`brand` = p.`brand` AND x.`species_id` = sp.`species_id`
                     AND x.`food_form` = p.`form` AND x.`unit_weight_lbs` <=> p.`lbs`);

-- Stored as GTIN-14 (zero-padded), as Barcode::normalise gives them.
INSERT INTO `product_barcode` (`barcode`, `product_id`, `linked_by`, `linked_at`)
SELECT b.`code`, x.`product_id`, u.`user_id`, '2026-01-01 00:00:00'
  FROM (SELECT '09520000000011' AS `code`, 'Adult kibble' AS `name`, 30.00 AS `lbs`
        UNION ALL SELECT '09520000000028', 'Adult kibble', 15.00
        UNION ALL SELECT '09520000000035', 'Puppy kibble', 4.00
        UNION ALL SELECT '09520000000042', 'Indoor cat', 16.00
        UNION ALL SELECT '09520000000059', 'Kitten', 3.00
        UNION ALL SELECT '09520000000066', 'Chunks in gravy', 0.81
        UNION ALL SELECT '09520000000073', 'Pate', 0.34
        UNION ALL SELECT '09520000000080', 'Dental chews', NULL) b
  JOIN `product` x ON x.`name` = b.`name` AND x.`brand` = 'Dev Brand' AND x.`unit_weight_lbs` <=> b.`lbs`
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `product_barcode` y WHERE y.`barcode` = b.`code`);

INSERT INTO `site_stock` (`site_id`, `product_id`, `quantity_on_hand`)
SELECT s.`site_id`, p.`product_id`, 0
  FROM `site` s CROSS JOIN `product` p
 WHERE NOT EXISTS (SELECT 1 FROM `site_stock` ss WHERE ss.`site_id` = s.`site_id` AND ss.`product_id` = p.`product_id`);

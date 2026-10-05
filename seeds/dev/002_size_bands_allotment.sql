-- Development seed data: placeholder size bands and a published allotment version 1, so the
-- allotment screens and later the pet and distribution screens have something to work with.
-- PLACEHOLDERS ONLY: the client supplies the real size bands and pounds (plan Q13).
-- Safe to run repeatedly: bands are added only for a species that has none yet, and version 1
-- only when there are no allotment rules at all, so nothing an Administrator made is touched.
-- Ranges are half-open [min, max), as SizeBandService expects.

INSERT INTO `size_band` (`species_id`, `name`, `min_weight_lbs`, `max_weight_lbs`)
SELECT sp.`species_id`, b.`name`, b.`min_w`, b.`max_w`
  FROM (SELECT 'Dog' AS `species`, 'Toy' AS `name`, 0.0 AS `min_w`, 10.0 AS `max_w`
        UNION ALL SELECT 'Dog', 'Small', 10.0, 25.0
        UNION ALL SELECT 'Dog', 'Medium', 25.0, 50.0
        UNION ALL SELECT 'Dog', 'Large', 50.0, 90.0
        UNION ALL SELECT 'Dog', 'Giant', 90.0, NULL
        UNION ALL SELECT 'Cat', 'Small', 0.0, 8.0
        UNION ALL SELECT 'Cat', 'Standard', 8.0, 15.0
        UNION ALL SELECT 'Cat', 'Large', 15.0, NULL) b
  JOIN `species` sp ON sp.`name` = b.`species`
 WHERE NOT EXISTS (SELECT 1 FROM `size_band` x WHERE x.`species_id` = sp.`species_id`);

INSERT INTO `allotment_rule` (`rule_version`, `species_id`, `size_band_id`, `food_form`, `lbs_per_distribution`,
                              `effective_from`, `created_by`, `created_at`, `published_at`, `published_by`)
SELECT 1, sb.`species_id`, sb.`size_band_id`, 'Any', r.`lbs`, '2026-01-01', u.`user_id`, '2026-01-01 00:00:00', '2026-01-01 00:00:00', u.`user_id`
  FROM (SELECT 'Dog' AS `species`, 'Toy' AS `band`, 2.00 AS `lbs`
        UNION ALL SELECT 'Dog', 'Small', 4.00
        UNION ALL SELECT 'Dog', 'Medium', 8.00
        UNION ALL SELECT 'Dog', 'Large', 12.00
        UNION ALL SELECT 'Dog', 'Giant', 16.00
        UNION ALL SELECT 'Cat', 'Small', 1.50
        UNION ALL SELECT 'Cat', 'Standard', 2.50
        UNION ALL SELECT 'Cat', 'Large', 3.50) r
  JOIN `species` sp ON sp.`name` = r.`species`
  JOIN `size_band` sb ON sb.`species_id` = sp.`species_id` AND sb.`name` = r.`band`
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `allotment_rule`);

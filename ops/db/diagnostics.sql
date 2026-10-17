-- Read-only checks for the list pages. Run in phpMyAdmin (SQL tab), one block at a time.
-- Table prefix on the live site is joomla_ (change it if yours differs).
-- Nothing here changes data.

-- 1) Which indexes exist on the tables the lists read.
SHOW INDEX FROM joomla_fields_values;
SHOW INDEX FROM joomla_vigling_user_services;
SHOW INDEX FROM joomla_vigling_user_stock_services;
SHOW INDEX FROM joomla_vigling_service_nodes;

-- 2) Collation of the text id column that lists join on.
-- If item_id is NOT utf8mb4_unicode_ci, the CAST(... ) COLLATE ... in the list queries
-- cannot use the index well. Send me the result.
SELECT table_name, column_name, character_set_name, collation_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('joomla_fields_values', 'joomla_users')
  AND column_name IN ('item_id', 'id', 'name');

-- 3) How the specialists list finds its masters. Look at the "key" and "rows" columns:
-- key = idx_vigling_fields_field_item is good; key = NULL and a large "rows" is bad.
EXPLAIN
SELECT COUNT(*)
FROM joomla_users u
WHERE u.block = 0
  AND EXISTS (
    SELECT 1 FROM joomla_fields_values specfv
    WHERE specfv.item_id = CAST(u.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
      AND specfv.field_id = (SELECT id FROM joomla_fields WHERE name = 'vyberite_spetsialnos' AND context = 'com_users.user' LIMIT 1)
      AND specfv.value <> ''
      AND specfv.value <> '{}'
  );

-- 4) The same for the home-page promotions block.
EXPLAIN
SELECT COUNT(*)
FROM joomla_vigling_user_stock_services s
INNER JOIN joomla_users u ON u.id = s.user_id
WHERE s.is_active = 1 AND s.count_stock > 0 AND u.block = 0;

-- 5) Rows per table (to see whether an index can matter at all).
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('joomla_fields_values', 'joomla_users', 'joomla_vigling_user_services',
                     'joomla_vigling_user_stock_services', 'joomla_vigling_service_nodes', 'joomla_vigling_bookings');

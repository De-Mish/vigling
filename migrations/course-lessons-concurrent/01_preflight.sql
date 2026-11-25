SELECT '1. course_parts.concurrent_participants present (0 before apply, 1 after)' AS check_name, COUNT(*) AS cnt
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='joomla_vigling_course_parts' AND COLUMN_NAME='concurrent_participants';
SELECT '2. user_courses.concurrent_participants present (expect 1)' AS check_name, COUNT(*) AS cnt
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='joomla_vigling_user_courses' AND COLUMN_NAME='concurrent_participants';
SELECT '3. course_parts rows (info)' AS check_name, COUNT(*) AS cnt FROM `joomla_vigling_course_parts`;

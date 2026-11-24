SELECT '1. user_courses.lessons_count present (0 before apply, 1 after)' AS check_name, COUNT(*) AS cnt
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='joomla_vigling_user_courses' AND COLUMN_NAME='lessons_count';
SELECT '2. course_parts table present (0 before apply, 1 after)' AS check_name, COUNT(*) AS cnt
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='joomla_vigling_course_parts';
SELECT '3. course_slots.part_no present (0 before apply, 1 after)' AS check_name, COUNT(*) AS cnt
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='joomla_vigling_course_slots' AND COLUMN_NAME='part_no';
SELECT '4. bookings.course_part present (0 before apply, 1 after)' AS check_name, COUNT(*) AS cnt
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='joomla_vigling_bookings' AND COLUMN_NAME='course_part';
SELECT '5. current columns of uniq_user_course_id (info)' AS check_name, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='joomla_vigling_bookings' AND INDEX_NAME='uniq_user_course_id'
 ORDER BY SEQ_IN_INDEX;
SELECT '6. courses with more than one active slot (expect 0)' AS check_name, COUNT(*) AS problems
  FROM (SELECT course_id FROM `joomla_vigling_course_slots` WHERE is_active=1 GROUP BY course_id HAVING COUNT(*)>1) t;
SELECT '7. users with several bookings on one course (expect 0)' AS check_name, COUNT(*) AS problems
  FROM (SELECT user_id, course_id FROM `joomla_vigling_bookings`
         WHERE booking_kind='course' AND course_id IS NOT NULL
         GROUP BY user_id, course_id HAVING COUNT(*)>1) t;
SELECT '8. active courses / course bookings (info)' AS check_name,
  (SELECT COUNT(*) FROM `joomla_vigling_user_courses` WHERE is_active=1) AS active_courses,
  (SELECT COUNT(*) FROM `joomla_vigling_bookings` WHERE booking_kind='course') AS course_bookings;

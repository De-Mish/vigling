SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_user_courses' AND COLUMN_NAME='lessons_count')=0,
  'ALTER TABLE `joomla_vigling_user_courses` ADD COLUMN `lessons_count` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `duration_min`',
  'SELECT ''lessons_count already present''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
CREATE TABLE IF NOT EXISTS `joomla_vigling_course_parts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` INT UNSIGNED NOT NULL,
  `part_no` TINYINT UNSIGNED NOT NULL,
  `duration_min` INT UNSIGNED NOT NULL DEFAULT 60,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_course_part` (`course_id`, `part_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_course_slots' AND COLUMN_NAME='part_no')=0,
  'ALTER TABLE `joomla_vigling_course_slots` ADD COLUMN `part_no` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `course_id`',
  'SELECT ''part_no already present''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_course_slots' AND INDEX_NAME='idx_course_slot_part')=0,
  'ALTER TABLE `joomla_vigling_course_slots` ADD INDEX `idx_course_slot_part` (`course_id`, `part_no`, `is_active`)',
  'SELECT ''idx_course_slot_part already present''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_bookings' AND COLUMN_NAME='course_part')=0,
  'ALTER TABLE `joomla_vigling_bookings` ADD COLUMN `course_part` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `course_slot_id`',
  'SELECT ''course_part already present''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_bookings' AND INDEX_NAME='uniq_user_course_id' AND COLUMN_NAME='course_part')=0
         AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_bookings' AND INDEX_NAME='uniq_user_course_id')>0,
  'ALTER TABLE `joomla_vigling_bookings` DROP INDEX `uniq_user_course_id`',
  'SELECT ''uniq_user_course_id: nothing to drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_bookings' AND INDEX_NAME='uniq_user_course_id')=0,
  'ALTER TABLE `joomla_vigling_bookings` ADD UNIQUE KEY `uniq_user_course_id` (`user_id`, `course_id`, `course_part`)',
  'SELECT ''uniq_user_course_id already covers course_part''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
INSERT IGNORE INTO `joomla_vigling_course_parts` (`course_id`, `part_no`, `duration_min`, `capacity`)
SELECT c.id, 1, c.duration_min, c.capacity FROM `joomla_vigling_user_courses` c;
SELECT 'done: lessons_count / course_parts / part_no / course_part' AS check_name,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_user_courses' AND COLUMN_NAME='lessons_count') AS lessons_count,
  (SELECT COUNT(*) FROM `joomla_vigling_course_parts`) AS course_parts_rows,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_course_slots' AND COLUMN_NAME='part_no') AS slot_part_no,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='joomla_vigling_bookings' AND COLUMN_NAME='course_part') AS booking_course_part;

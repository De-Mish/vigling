-- Group course / model-search reschedule requests and the "can't ignore" notices.
-- Run once in phpMyAdmin (SQL tab) on the live database. Prefix is joomla_.
-- Safe to run twice (CREATE TABLE IF NOT EXISTS). Until it is run, the site keeps the
-- old behaviour: a group slot is moved for everyone at once, without asking participants.

CREATE TABLE IF NOT EXISTS `joomla_vigling_group_reschedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` VARCHAR(10) NOT NULL,
  `slot_id` INT UNSIGNED NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `master_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `old_start_utc` DATETIME NOT NULL,
  `old_end_utc` DATETIME NOT NULL,
  `new_start_utc` DATETIME NOT NULL,
  `new_end_utc` DATETIME NOT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL,
  `resolved_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gr_slot_status` (`kind`, `slot_id`, `status`),
  KEY `idx_gr_master_status` (`master_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `joomla_vigling_group_reschedule_replies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proposal_id` INT UNSIGNED NOT NULL,
  `booking_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
  `responded_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gr_reply` (`proposal_id`, `booking_id`),
  KEY `idx_gr_reply_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `joomla_vigling_critical_notices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `notice_type` VARCHAR(40) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `url` VARCHAR(500) NOT NULL DEFAULT '',
  `requires_action` TINYINT(1) NOT NULL DEFAULT 0,
  `ref_type` VARCHAR(30) NOT NULL DEFAULT '',
  `ref_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `acknowledged_at` DATETIME NULL DEFAULT NULL,
  `resolved_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cn_user_open` (`user_id`, `acknowledged_at`, `resolved_at`),
  KEY `idx_cn_ref` (`ref_type`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

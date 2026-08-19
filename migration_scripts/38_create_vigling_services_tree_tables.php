<?php
/**
 * Создаёт новые таблицы для древовидной модели услуг.
 *
 * Запуск:
 *   php migration_scripts/38_create_vigling_services_tree_tables.php
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);

require_once __DIR__ . '/load_j6_config.php';
$config = loadJ6Config($baseDir . '/configuration.php');
if (!$config) {
    fwrite(STDERR, "Не найден configuration.php\n");
    exit(1);
}

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$prefix = $config->dbprefix;
$tNodes = $mysqli->real_escape_string($prefix . 'vigling_service_nodes');
$tUserSvc = $mysqli->real_escape_string($prefix . 'vigling_user_services');
$tMap = $mysqli->real_escape_string($prefix . 'vigling_service_legacy_map');
$tUnresolved = $mysqli->real_escape_string($prefix . 'vigling_service_unresolved');

$sql = [];

$sql[] = "CREATE TABLE IF NOT EXISTS `{$tNodes}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` BIGINT UNSIGNED NULL,
  `level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `path` VARCHAR(1024) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `type` ENUM('group','service','variant') NOT NULL DEFAULT 'service',
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `legacy_source` VARCHAR(64) DEFAULT NULL,
  `legacy_id` BIGINT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parent_sort` (`parent_id`, `sort_order`),
  KEY `idx_legacy` (`legacy_source`, `legacy_id`),
  KEY `idx_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$tUserSvc}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `service_node_id` BIGINT UNSIGNED NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `duration_min` INT NOT NULL DEFAULT 0,
  `currency` VARCHAR(16) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `source_payload` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_service` (`user_id`, `service_node_id`),
  KEY `idx_user_active` (`user_id`, `is_active`),
  KEY `idx_service_active` (`service_node_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$tMap}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `legacy_source` VARCHAR(64) NOT NULL,
  `legacy_id` BIGINT NOT NULL,
  `service_node_id` BIGINT UNSIGNED NOT NULL,
  `confidence` DECIMAL(4,3) NOT NULL DEFAULT 1.000,
  `note` VARCHAR(1024) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_legacy` (`legacy_source`, `legacy_id`),
  KEY `idx_node` (`service_node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$tUnresolved}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `field_name` VARCHAR(64) NOT NULL,
  `cat_id` VARCHAR(64) NOT NULL,
  `service_raw` VARCHAR(128) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `duration_min` INT NOT NULL DEFAULT 0,
  `reason` VARCHAR(128) NOT NULL,
  `source_payload` JSON DEFAULT NULL,
  `resolved_node_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_unresolved_signature` (`user_id`, `field_name`, `cat_id`, `service_raw`, `price`, `duration_min`, `reason`),
  KEY `idx_reason` (`reason`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

foreach ($sql as $i => $query) {
    if (!$mysqli->query($query)) {
        fwrite(STDERR, "Ошибка SQL #" . ($i + 1) . ": " . $mysqli->error . "\n");
        exit(1);
    }
}

echo "Созданы/проверены таблицы:\n";
echo " - {$tNodes}\n";
echo " - {$tUserSvc}\n";
echo " - {$tMap}\n";
echo " - {$tUnresolved}\n";
exit(0);

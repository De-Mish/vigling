<?php
/**
 * Создаёт таблицу акционных услуг пользователей (stock_prices) в новой модели.
 *
 * Запуск:
 *   php migration_scripts/46_create_vigling_user_stock_services_table.php
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
$tUserStock = $mysqli->real_escape_string($prefix . 'vigling_user_stock_services');

$sql = "CREATE TABLE IF NOT EXISTS `{$tUserStock}` (
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

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Ошибка SQL: {$mysqli->error}\n");
    exit(1);
}

echo "Создана/проверена таблица: {$tUserStock}\n";
exit(0);

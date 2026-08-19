<?php
/**
 * Создаёт таблицу context-aware mapping для услуг.
 *
 * Назначение:
 * - резолвить услуги по контексту (например content article) + legacy id
 * - устранять коллизии одинаковых tag id в разных ветках
 *
 * Запуск:
 *   php migration_scripts/50_create_vigling_service_context_map_table.php
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
$tCtxMap = $mysqli->real_escape_string($prefix . 'vigling_service_context_map');

$sql = "CREATE TABLE IF NOT EXISTS `{$tCtxMap}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `context_source` VARCHAR(64) NOT NULL,
  `context_id` BIGINT NOT NULL,
  `legacy_source` VARCHAR(64) NOT NULL,
  `legacy_id` BIGINT NOT NULL,
  `service_node_id` BIGINT UNSIGNED NOT NULL,
  `confidence` DECIMAL(4,3) NOT NULL DEFAULT 1.000,
  `note` VARCHAR(1024) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ctx_legacy` (`context_source`,`context_id`,`legacy_source`,`legacy_id`),
  KEY `idx_service_node` (`service_node_id`),
  KEY `idx_ctx_source_id` (`context_source`,`context_id`),
  KEY `idx_legacy_source_id` (`legacy_source`,`legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "Ошибка SQL: {$mysqli->error}\n");
    exit(1);
}

echo "Создана/проверена таблица: {$prefix}vigling_service_context_map\n";
exit(0);


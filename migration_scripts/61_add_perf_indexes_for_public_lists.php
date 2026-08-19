<?php
/**
 * Adds covering indexes for public specialists/stocks/course list filters.
 *
 * Run from public_html:
 *   php migration_scripts/61_add_perf_indexes_for_public_lists.php
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

function perfIndexExists(mysqli $db, string $tableName, string $indexName): bool
{
    $tableEsc = $db->real_escape_string($tableName);
    $indexEsc = $db->real_escape_string($indexName);
    $res = $db->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$indexEsc}'");

    return (bool) ($res && $res->num_rows > 0);
}

function perfAddIndex(mysqli $db, string $tableName, string $indexName, string $definition): void
{
    if (perfIndexExists($db, $tableName, $indexName)) {
        echo "Индекс {$indexName} уже существует в {$tableName}\n";
        return;
    }

    $tableEsc = $db->real_escape_string($tableName);
    if (!$db->query("ALTER TABLE `{$tableEsc}` ADD KEY `{$indexName}` {$definition}")) {
        fwrite(STDERR, "Ошибка добавления {$indexName} в {$tableName}: {$db->error}\n");
        exit(1);
    }

    echo "Добавлен индекс {$indexName} в {$tableName}\n";
}

perfAddIndex(
    $mysqli,
    $prefix . 'vigling_user_services',
    'idx_filter_cat_active_node_tag',
    '(`legacy_cat_id`, `is_active`, `service_node_id`, `legacy_tag_id`)'
);

perfAddIndex(
    $mysqli,
    $prefix . 'vigling_user_stock_services',
    'idx_filter_cat_active_node_tag',
    '(`legacy_cat_id`, `is_active`, `service_node_id`, `legacy_tag_id`)'
);

perfAddIndex(
    $mysqli,
    $prefix . 'vigling_user_services',
    'idx_filter_active_node_tag_cat',
    '(`is_active`, `service_node_id`, `legacy_tag_id`, `legacy_cat_id`)'
);

perfAddIndex(
    $mysqli,
    $prefix . 'vigling_user_stock_services',
    'idx_filter_active_node_tag_cat',
    '(`is_active`, `service_node_id`, `legacy_tag_id`, `legacy_cat_id`)'
);

echo "Performance indexes checked.\n";
exit(0);

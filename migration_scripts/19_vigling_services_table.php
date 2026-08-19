<?php
/**
 * Создаёт таблицу #__vigling_services и заполняет данными из jsn_adaptation/lookups.json (svc_id_to_name).
 * Запуск: php migration_scripts/19_vigling_services_table.php
 * После выполнения плагин Vigling и JsnDecodeHelper читают названия услуг из БД, lookups.json не нужен.
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);
require_once $baseDir . '/configuration.php';
$config = new JConfig();

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');
$prefix = $config->dbprefix;
$table = $mysqli->real_escape_string($prefix . 'vigling_services');

$create = "CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` int(10) unsigned NOT NULL,
  `title` varchar(512) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$mysqli->query($create)) {
    fwrite(STDERR, "Ошибка создания таблицы: " . $mysqli->error . "\n");
    exit(1);
}
echo "Таблица {$table} создана или уже существует.\n";

$jsonPath = $baseDir . '/jsn_adaptation/lookups.json';
if (!is_file($jsonPath)) {
    echo "Файл lookups.json не найден. Таблица создана пустой. Импорт пропущен.\n";
    exit(0);
}
$data = json_decode(file_get_contents($jsonPath), true);
$svc = $data['svc_id_to_name'] ?? [];
if (empty($svc)) {
    echo "В lookups.json нет svc_id_to_name. Импорт пропущен.\n";
    exit(0);
}

$mysqli->query("TRUNCATE TABLE `{$table}`");
$stmt = $mysqli->prepare("INSERT INTO `{$table}` (`id`, `title`) VALUES (?, ?)");
if (!$stmt) {
    fwrite(STDERR, "Ошибка prepare: " . $mysqli->error . "\n");
    exit(1);
}
$count = 0;
foreach ($svc as $id => $title) {
    $id = (int) $id;
    $title = (string) $title;
    $stmt->bind_param('is', $id, $title);
    if ($stmt->execute()) {
        $count++;
    }
}
$stmt->close();
echo "Импортировано записей: {$count}.\n";
exit(0);

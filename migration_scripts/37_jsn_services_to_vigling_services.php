<?php
/**
 * Перенос услуг из старой JSN-структуры (Joomla 3) в таблицу #__vigling_services.
 *
 * Старая структура:
 * - Услуги = статьи в #__content (catid из категорий услуг).
 * - Категории услуг: #__categories с path LIKE 'uslugi/%' OR path LIKE 'zatochka-remont/%', level = 2.
 *
 * В #__vigling_services записываются: id = id статьи (#__content.id), title = #__content.title.
 *
 * Запуск:
 *   Тест (без вставки): php migration_scripts/37_jsn_services_to_vigling_services.php --dry-run
 *   Вставка:            php migration_scripts/37_jsn_services_to_vigling_services.php
 */

define('_JEXEC', 1);
$baseDir = dirname(__DIR__);
require_once $baseDir . '/configuration.php';
$config = new JConfig();

$dryRun = in_array('--dry-run', $argv ?? [], true);

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');
$prefix = $mysqli->real_escape_string($config->dbprefix);
$tContent = $prefix . 'content';
$tCategories = $prefix . 'categories';
$tVigling = $prefix . 'vigling_services';

$sql = "SELECT c.id, c.title
        FROM `{$tContent}` AS c
        INNER JOIN `{$tCategories}` AS cc ON c.catid = cc.id
        WHERE (cc.path LIKE 'uslugi/%' OR cc.path LIKE 'zatochka-remont/%')
          AND cc.published = 1
          AND (cc.level = 2 OR cc.level = '2')
          AND c.state = 1
        ORDER BY c.id";
$res = $mysqli->query($sql);
if (!$res) {
    fwrite(STDERR, "Ошибка выборки: " . $mysqli->error . "\n");
    exit(1);
}

$rows = [];
while ($row = $res->fetch_assoc()) {
    $id = (int) $row['id'];
    $title = trim((string) $row['title']);
    if ($title === '') {
        $title = 'Услуга #' . $id;
    }
    $rows[] = ['id' => $id, 'title' => $title];
}
$res->free();

$count = count($rows);
echo "Найдено записей для переноса: {$count}\n";

if ($count === 0) {
    echo "Нечего вставлять. Выход.\n";
    exit(0);
}

if ($dryRun) {
    $preview = 20;
    echo "\nРежим теста (--dry-run). Вставка не выполняется.\n";
    echo "Пример записей (первые " . min($preview, $count) . "):\n";
    echo str_repeat('-', 60) . "\n";
    foreach (array_slice($rows, 0, $preview) as $r) {
        echo "  id: " . $r['id'] . "  title: " . $r['title'] . "\n";
    }
    if ($count > $preview) {
        echo "  ... и ещё " . ($count - $preview) . " записей.\n";
    }
    echo str_repeat('-', 60) . "\n";
    echo "Для выполнения вставки запустите скрипт без флага --dry-run.\n";
    exit(0);
}

$create = "CREATE TABLE IF NOT EXISTS `{$tVigling}` (
  `id` int(10) unsigned NOT NULL,
  `title` varchar(512) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$mysqli->query($create)) {
    fwrite(STDERR, "Ошибка создания таблицы: " . $mysqli->error . "\n");
    exit(1);
}
echo "Таблица {$tVigling} проверена.\n";

$stmt = $mysqli->prepare("INSERT INTO `{$tVigling}` (`id`, `title`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `title` = VALUES(`title`)");
if (!$stmt) {
    fwrite(STDERR, "Ошибка prepare: " . $mysqli->error . "\n");
    exit(1);
}

$inserted = 0;
foreach ($rows as $r) {
    $id = $r['id'];
    $title = $r['title'];
    $stmt->bind_param('is', $id, $title);
    if ($stmt->execute()) {
        $inserted++;
    }
}
$stmt->close();

echo "Перенесено/обновлено записей в {$tVigling}: {$inserted}.\n";
exit(0);

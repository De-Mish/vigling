<?php
/**
 * Устанавливает доступ "Public" (access=1) для пункта меню ЛК (lk / profile),
 * чтобы гости могли открывать /lk?user_id=ID для записи к мастеру.
 * Запуск: php migration_scripts/36_set_lk_menu_public_access.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
if (!is_file($base . '/configuration.php')) {
    $base = dirname($base);
}
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: configuration.php или БД.\n");
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;
$menuTable = $prefix . 'menu';

$res = $mysqli->query(
    "SELECT id, title, alias, path, link, access FROM `{$menuTable}` "
    . "WHERE client_id = 0 AND (alias = 'lk' OR path = 'lk' OR (link LIKE '%com_users%' AND link LIKE '%profile%'))"
);
if (!$res) {
    die("Ошибка запроса: " . $mysqli->error . "\n");
}

$updated = 0;
while ($row = $res->fetch_object()) {
    $id = (int) $row->id;
    $access = (int) $row->access;
    if ($access !== 1) {
        if ($mysqli->query("UPDATE `{$menuTable}` SET access = 1 WHERE id = " . $id)) {
            echo "Пункт меню id={$id} ({$row->title}, {$row->path}): доступ изменён на Public (было {$access}).\n";
            $updated++;
        }
    } else {
        echo "Пункт меню id={$id} ({$row->title}): уже Public.\n";
    }
}

if ($updated === 0 && $res->num_rows === 0) {
    echo "Пункт меню ЛК (lk / profile) не найден. Создайте пункт меню на com_users, view=profile и запустите скрипт снова.\n";
}

$mysqli->close();
echo "Готово.\n";

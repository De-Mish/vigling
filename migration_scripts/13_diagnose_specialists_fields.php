<?php
/**
 * Диагностика полей и значений для com_specialists: почему не находятся мастера.
 * Запуск из корня сайта: php migration_scripts/13_diagnose_specialists_fields.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;

echo "=== 1. Поля с именем sity или context пользователя ===\n";
$q = "SELECT id, name, title, context, state FROM `{$prefix}fields` 
      WHERE name = 'sity' OR context LIKE '%user%' 
      ORDER BY context, name";
$res = $mysqli->query($q);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
} else {
    echo "Ошибка: " . $mysqli->error . "\n";
}

echo "\n=== 2. ID поля 'sity' (context com_users.user) ===\n";
$q = "SELECT id FROM `{$prefix}fields` WHERE context = 'com_users.user' AND name = 'sity' LIMIT 1";
$res = $mysqli->query($q);
$row = $res ? $res->fetch_assoc() : null;
$fieldIdSity = ($row !== null && isset($row['id'])) ? (int)$row['id'] : 0;
echo "field_id для sity: " . ($fieldIdSity ?: 'НЕ НАЙДЕН') . "\n";

echo "\n=== 3. Примеры записей в fields_values для поля sity (field_id={$fieldIdSity}) ===\n";
if ($fieldIdSity) {
    $q = "SELECT fv.id, fv.field_id, fv.item_id, LEFT(fv.value, 80) AS value_preview 
          FROM `{$prefix}fields_values` fv 
          WHERE fv.field_id = " . (int)$fieldIdSity . " AND TRIM(fv.value) <> '' 
          LIMIT 10";
    $res = $mysqli->query($q);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

echo "\n=== 4. Все context в таблице fields (уникальные) ===\n";
$q = "SELECT DISTINCT context FROM `{$prefix}fields` ORDER BY context";
$res = $mysqli->query($q);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        echo $row['context'] . "\n";
    }
}

echo "\n=== 5. Количество записей в fields_values по полям com_users.user ===\n";
$q = "SELECT f.name, COUNT(fv.item_id) AS cnt 
      FROM `{$prefix}fields_values` fv 
      INNER JOIN `{$prefix}fields` f ON f.id = fv.field_id AND f.context = 'com_users.user'
      GROUP BY f.name ORDER BY f.name";
$res = $mysqli->query($q);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        echo $row['name'] . ": " . $row['cnt'] . " записей\n";
    }
}

echo "\n=== 6. Один user_id с заполненным sity и его name в #__users ===\n";
if ($fieldIdSity) {
    $q = "SELECT u.id, u.name, u.block FROM `{$prefix}users` u 
          INNER JOIN `{$prefix}fields_values` fv ON fv.item_id = u.id AND fv.field_id = " . (int)$fieldIdSity . " 
          WHERE TRIM(fv.value) <> '' LIMIT 3";
    $res = $mysqli->query($q);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

$mysqli->close();
echo "\nГотово. Пришлите этот вывод в чат.\n";

<?php
/**
 * Миграция joomla_jsn_users (vigl1) → Custom Fields пользователей (vigl2).
 * Создаёт в J6 поля com_users.user и заполняет #__fields_values по данным JSN.
 *
 * Запуск из public_html:
 *   php migration_scripts/06_jsn_users_to_custom_fields.php
 *   php migration_scripts/06_jsn_users_to_custom_fields.php --dry-run
 *   php migration_scripts/06_jsn_users_to_custom_fields.php --create-fields-only
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_OFF);

$base = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);
$createFieldsOnly = in_array('--create-fields-only', $argv ?? [], true);

$cfgVigl1Path = $base . '/analyze/config_vigl1.php';
if (!is_file($cfgVigl1Path)) {
    die("Создайте analyze/config_vigl1.php\n");
}
$cfg1 = require $cfgVigl1Path;

require_once $base . '/migration_scripts/load_j6_config.php';
$cfg2 = loadJ6Config($base . '/configuration.php');
if (!$cfg2 || empty($cfg2->db)) {
    die("Не удалось прочитать J6 configuration.php\n");
}

$conn1 = @new mysqli($cfg1['host'], $cfg1['user'], $cfg1['password'], $cfg1['db']);
if ($conn1->connect_error) {
    die("Ошибка vigl1: " . $conn1->connect_error . "\n");
}
$conn1->set_charset('utf8mb4');

$conn2 = @new mysqli($cfg2->host, $cfg2->user, $cfg2->password, $cfg2->db);
if ($conn2->connect_error) {
    die("Ошибка vigl2: " . $conn2->connect_error . "\n");
}
$conn2->set_charset('utf8mb4');

$prefix2 = $cfg2->dbprefix ?? 'joomla_';
$tableFields = $prefix2 . 'fields';
$tableValues = $prefix2 . 'fields_values';
$tableUsers = $prefix2 . 'users';
$context = 'com_users.user';

$jsnToField = [
    'firstname' => ['title' => 'Имя (JSN)', 'type' => 'text'],
    'secondname' => ['title' => 'Отчество (JSN)', 'type' => 'text'],
    'lastname' => ['title' => 'Фамилия (JSN)', 'type' => 'text'],
    'avatar' => ['title' => 'Аватар (JSN)', 'type' => 'text'],
    'sity' => ['title' => 'Город (JSN)', 'type' => 'text'],
    'area' => ['title' => 'Район (JSN)', 'type' => 'text'],
    'street' => ['title' => 'Улица (JSN)', 'type' => 'text'],
    'doorway' => ['title' => 'Подъезд (JSN)', 'type' => 'text'],
    'house_number' => ['title' => 'Номер дома (JSN)', 'type' => 'text'],
    'about' => ['title' => 'О себе (JSN)', 'type' => 'textarea'],
    'work_day' => ['title' => 'Рабочие дни (JSN)', 'type' => 'textarea'],
    'work_from' => ['title' => 'Работа с (JSN)', 'type' => 'text'],
    'work_to' => ['title' => 'Работа до (JSN)', 'type' => 'text'],
    'vyberite_spetsialnos' => ['title' => 'Специальности (JSN)', 'type' => 'textarea'],
    'portfolio_field' => ['title' => 'Портфолио (JSN)', 'type' => 'textarea'],
    'telefon' => ['title' => 'Телефон (JSN)', 'type' => 'text'],
    'o_sebe' => ['title' => 'О себе доп. (JSN)', 'type' => 'textarea'],
    'prices' => ['title' => 'Цены (JSN)', 'type' => 'textarea'],
    'valuta' => ['title' => 'Валюта (JSN)', 'type' => 'text'],
    'link' => ['title' => 'Ссылка 1 (JSN)', 'type' => 'text'],
    'link_2' => ['title' => 'Ссылка 2 (JSN)', 'type' => 'text'],
    'link_3' => ['title' => 'Ссылка 3 (JSN)', 'type' => 'text'],
    'is_master' => ['title' => 'Мастер (JSN)', 'type' => 'text'],
    'home' => ['title' => 'Дома (JSN)', 'type' => 'textarea'],
    'rating' => ['title' => 'Рейтинг (JSN)', 'type' => 'text'],
    'stock_prices' => ['title' => 'Акционные цены (JSN)', 'type' => 'textarea'],
    'stock_prices_valuta' => ['title' => 'Акции валюта (JSN)', 'type' => 'text'],
];

$existingUserIds = [];
$res = $conn2->query("SELECT id FROM `" . $conn2->real_escape_string($tableUsers) . "`");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $existingUserIds[(int)$row['id']] = true;
    }
}
echo "Пользователей в J6: " . count($existingUserIds) . "\n";

$fieldIds = [];
$res = $conn2->query("SELECT id, name FROM `" . $conn2->real_escape_string($tableFields) . "` WHERE context = '" . $conn2->real_escape_string($context) . "'");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $fieldIds[$row['name']] = (int)$row['id'];
    }
}

foreach ($jsnToField as $name => $def) {
    if (isset($fieldIds[$name])) {
        continue;
    }
    if ($dryRun) {
        echo "[dry-run] Создали бы поле: {$name}\n";
        $fieldIds[$name] = 0;
        continue;
    }
    $title = $conn2->real_escape_string($def['title']);
    $type = $conn2->real_escape_string($def['type']);
    $now = date('Y-m-d H:i:s');
    $ordering = count($fieldIds) + 1;
    $sql = "INSERT INTO `{$tableFields}` (context, group_id, title, name, type, default_value, state, required, created_time, modified_time, language, access, ordering, params, fieldparams, label) "
         . "VALUES ('{$context}', 0, '{$title}', '{$name}', '{$type}', '', 1, 0, '{$now}', '{$now}', '*', 1, " . (int)$ordering . ", '{}', '{}', '{$title}')";
    if ($conn2->query($sql)) {
        $fieldIds[$name] = (int)$conn2->insert_id;
        echo "Создано поле: {$name} (id={$fieldIds[$name]})\n";
    } else {
        echo "Ошибка создания поля {$name}: " . $conn2->error . "\n";
    }
}

if ($createFieldsOnly) {
    $conn1->close();
    $conn2->close();
    echo "Режим --create-fields-only: поля созданы, выход.\n";
    exit(0);
}

$res = $conn2->query("SELECT id, name FROM `" . $conn2->real_escape_string($tableFields) . "` WHERE context = '" . $conn2->real_escape_string($context) . "'");
$fieldIds = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $fieldIds[$row['name']] = (int)$row['id'];
    }
}

if (!$dryRun) {
    $conn2->query("UPDATE `{$tableFields}` SET label = title WHERE context = '" . $conn2->real_escape_string($context) . "' AND (label IS NULL OR label = '')");
    if ($conn2->affected_rows > 0) {
        echo "Обновлены подписи (label) у полей: " . $conn2->affected_rows . "\n";
    }
}

$jsnCols = array_keys($jsnToField);
$colsList = 'id,' . implode(',', array_map(function ($c) use ($conn1) {
    return '`' . $conn1->real_escape_string($c) . '`';
}, $jsnCols));

$batchSize = 500;
$lastId = 0;
$totalMigrated = 0;
$totalSkipped = 0;

while (true) {
    if (!$conn1->ping()) {
        $conn1->close();
        $conn1 = @new mysqli($cfg1['host'], $cfg1['user'], $cfg1['password'], $cfg1['db']);
        if ($conn1->connect_error) {
            echo "Ошибка переподключения vigl1: " . $conn1->connect_error . "\n";
            break;
        }
        $conn1->set_charset('utf8mb4');
    }
    $sql = "SELECT {$colsList} FROM joomla_jsn_users WHERE id > " . (int)$lastId . " ORDER BY id LIMIT " . (int)$batchSize;
    $res = $conn1->query($sql);
    if (!$res) {
        echo "Ошибка запроса vigl1: " . $conn1->error . "\n";
        break;
    }
    $batchRows = $res->num_rows;
    if ($batchRows === 0) {
        $res->free();
        break;
    }
    $rowsToInsert = [];
    while ($row = $res->fetch_assoc()) {
        $userId = (int)$row['id'];
        $lastId = $userId;
        if (!isset($existingUserIds[$userId])) {
            $totalSkipped++;
            continue;
        }
        foreach ($jsnCols as $col) {
            $fieldId = $fieldIds[$col] ?? null;
            if (!$fieldId) {
                continue;
            }
            $v = $row[$col];
            if ($v === null || $v === '') {
                continue;
            }
            if (is_string($v) && mb_strlen($v) > 65530) {
                $v = mb_substr($v, 0, 65530);
            }
            $rowsToInsert[] = [(int)$fieldId, $userId, $v];
        }
        $totalMigrated++;
    }
    $res->free();

    if (!$dryRun && !empty($rowsToInsert)) {
        $chunkSize = 300;
        for ($i = 0; $i < count($rowsToInsert); $i += $chunkSize) {
            $chunk = array_slice($rowsToInsert, $i, $chunkSize);
            $values = [];
            foreach ($chunk as $r) {
                $values[] = "(" . (int)$r[0] . "," . (int)$r[1] . ",'" . $conn2->real_escape_string($r[2]) . "')";
            }
            $sql = "INSERT INTO `{$tableValues}` (field_id, item_id, value) VALUES " . implode(",", $values)
                 . " ON DUPLICATE KEY UPDATE value = VALUES(value)";
            $conn2->query($sql);
        }
    }
    if (!$dryRun) {
        echo date('H:i:s') . " пачка {$batchRows}, всего: {$totalMigrated}, последний id: {$lastId}\n";
    }
}

echo "Готово. Перенесено профилей: {$totalMigrated}, пропущено (нет в J6): {$totalSkipped}\n";
$conn1->close();
$conn2->close();

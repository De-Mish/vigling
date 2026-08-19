<?php
/**
 * Добавляет два custom-field'а соц-сетей для пользователей: `telegram` и `max`.
 *
 * Поле `link` (Vk) уже существует в #__fields. Эти два — расширение профиля мастера.
 *
 * Запуск:
 *   docker exec -w /var/www/html vigling-joomla php migration_scripts/68_add_social_link_fields.php
 *   php vigling.ru/public_html/migration_scripts/68_add_social_link_fields.php
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
$tableFields = $mysqli->real_escape_string($prefix . 'fields');
$context = 'com_users.user';

$fields = [
    'telegram' => ['title' => 'Телеграм', 'type' => 'text'],
    'max'      => ['title' => 'Макс',     'type' => 'text'],
];

$existing = [];
$sql = "SELECT id, name FROM `{$tableFields}` WHERE context = '" . $mysqli->real_escape_string($context) . "' AND name IN ('telegram','max')";
$res = $mysqli->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $existing[$row['name']] = (int) $row['id'];
    }
    $res->free();
}

$orderingBase = 100;
foreach ($fields as $name => $def) {
    if (isset($existing[$name])) {
        echo "Поле уже существует: {$name} (id={$existing[$name]})\n";
        continue;
    }

    $title = $mysqli->real_escape_string($def['title']);
    $type = $mysqli->real_escape_string($def['type']);
    $now = date('Y-m-d H:i:s');
    $ordering = $orderingBase++;

    $insertSql = "INSERT INTO `{$tableFields}` "
        . "(context, group_id, title, name, label, type, default_value, note, description, "
        . "state, required, only_use_in_subform, ordering, params, fieldparams, language, "
        . "created_time, created_user_id, modified_time, modified_by, access) "
        . "VALUES ('{$context}', 0, '{$title}', '{$name}', '{$title}', '{$type}', '', '', '', "
        . "1, 0, 0, {$ordering}, '{}', '{}', '*', "
        . "'{$now}', 0, '{$now}', 0, 1)";

    if (!$mysqli->query($insertSql)) {
        fwrite(STDERR, "Ошибка создания поля {$name}: {$mysqli->error}\n");
        exit(1);
    }
    echo "Создано поле: {$name} (id={$mysqli->insert_id})\n";
}

$mysqli->close();
echo "Готово.\n";
exit(0);

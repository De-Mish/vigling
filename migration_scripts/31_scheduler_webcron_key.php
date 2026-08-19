<?php
/**
 * Создаёт ключ WebCron для планировщика задач, если его нет, и выводит ссылку.
 * Запуск из корня сайта: php migration_scripts/31_scheduler_webcron_key.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
if (!is_file($base . '/configuration.php')) {
    $base = getcwd();
}
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$configContent = file_get_contents($base . '/configuration.php');
$liveSite = '';
if (preg_match('/public\s+\$live_site\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/', $configContent, $m)) {
    $liveSite = rtrim($m[1], '/');
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$prefix = $cfg->dbprefix;
$table = $prefix . 'extensions';
$res = $mysqli->query("SELECT extension_id, params FROM `{$table}` WHERE type = 'component' AND element = 'com_scheduler' LIMIT 1");
if (!$res || !$row = $res->fetch_object()) {
    die("Компонент com_scheduler не найден в БД.\n");
}

$params = json_decode($row->params, true);
if (!is_array($params)) {
    $params = [];
}
if (!isset($params['webcron'])) {
    $params['webcron'] = [];
}
$needSave = false;
if (empty($params['webcron']['key']) || strlen($params['webcron']['key']) < 16) {
    $params['webcron']['key'] = bin2hex(random_bytes(10));
    $needSave = true;
    echo "Ключ WebCron создан и сохранён в БД.\n";
} else {
    echo "Ключ WebCron уже есть в настройках.\n";
}
if (empty($params['webcron']['enabled'])) {
    $params['webcron']['enabled'] = 1;
    $needSave = true;
    echo "WebCron включён в настройках.\n";
}
if (isset($params['webcron']['base_link'])) {
    unset($params['webcron']['base_link']);
    $needSave = true;
}
if (isset($params['webcron']['reset_key'])) {
    unset($params['webcron']['reset_key']);
    $needSave = true;
}

if ($needSave) {
    $paramsJson = $mysqli->real_escape_string(json_encode($params));
    if (!$mysqli->query("UPDATE `{$table}` SET params = '{$paramsJson}' WHERE extension_id = " . (int) $row->extension_id)) {
        die("Ошибка UPDATE: " . $mysqli->error . "\n");
    }
}

$key = $params['webcron']['key'];
$relative = 'index.php?option=com_ajax&plugin=RunSchedulerWebcron&group=system&format=json&hash=' . $key;
$url = $liveSite ? ($liveSite . '/' . $relative) : ('https://ВАШ_ДОМЕН/' . $relative);

echo "\nСсылка WebCron (вызывайте раз в 1–2 минуты с крона хостера или cron-job.org):\n  " . $url . "\n";
echo "\n1) Добавьте в крон: * * * * * curl -s \"" . $url . "\"\n";
echo "2) За один вызов выполняется одна просроченная задача. Если предупреждение остаётся — вызывайте ссылку несколько раз подряд или настройте крон раз в минуту.\n";
echo "3) Если «Базовая ссылка» в админке пустая — выполните: php migration_scripts/32_clear_scheduler_config_session.php и снова откройте настройки → WebCron.\n";
$mysqli->close();

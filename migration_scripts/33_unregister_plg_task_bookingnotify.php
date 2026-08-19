<?php
/**
 * Удаляет плагин Task - Booking Notify из БД и задачи планировщика.
 * Уведомления по записям перенесены на системный крон: components/com_pushnotify/cron/
 * Запуск из корня сайта: php migration_scripts/33_unregister_plg_task_bookingnotify.php
 */
require_once __DIR__ . '/load_j6_config.php';
$base = dirname(__DIR__);
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
	die("Ошибка: не найден configuration.php или нет доступа к БД.\n");
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
	die("Ошибка подключения к БД: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $mysqli->real_escape_string($cfg->dbprefix);

$extTable = $prefix . 'extensions';
$taskTable = $prefix . 'scheduler_tasks';

$res = $mysqli->query("SELECT extension_id FROM `{$extTable}` WHERE type = 'plugin' AND element = 'bookingnotify' AND folder = 'task' LIMIT 1");
if ($res && $res->num_rows) {
	$mysqli->query("DELETE FROM `{$extTable}` WHERE type = 'plugin' AND element = 'bookingnotify' AND folder = 'task'");
	echo "Плагин Task - Booking Notify удалён из БД.\n";
} else {
	echo "Плагин в БД не найден.\n";
}

$res = $mysqli->query("SHOW TABLES LIKE '{$taskTable}'");
if ($res && $res->num_rows) {
	$typeLike = $mysqli->real_escape_string('plg_task_bookingnotify.%');
	$mysqli->query("DELETE FROM `{$taskTable}` WHERE `type` LIKE '{$typeLike}'");
	if ($mysqli->affected_rows > 0) {
		echo "Удалено задач планировщика: " . $mysqli->affected_rows . "\n";
	}
}

$autoloadFile = $base . '/administrator/cache/autoload_psr4.php';
if (is_file($autoloadFile)) {
	$content = file_get_contents($autoloadFile);
	if (strpos($content, "Viglin\\\\Plugin\\\\Task\\\\Bookingnotify\\\\") !== false) {
		$content = preg_replace("/\t'Viglin\\\\Plugin\\\\Task\\\\Bookingnotify\\\\' => \[.*?\],?\n/", '', $content);
		file_put_contents($autoloadFile, $content);
		echo "Запись Bookingnotify удалена из autoload_psr4.php.\n";
	}
}

$mysqli->close();
echo "Готово. Настройте крон: components/com_pushnotify/cron/README.txt\n";

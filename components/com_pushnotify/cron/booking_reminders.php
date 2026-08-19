<?php
/**
 * Общий cron напоминаний по записям.
 * Крон (раз в минуту): * * * * * cd /path/to/public_html && php components/com_pushnotify/cron/booking_reminders.php
 */
$log = function ($msg) {
	if (php_sapi_name() !== 'cli') return;
	$f = @fopen(dirname(__DIR__) . '/cron_reminders.log', 'a');
	if ($f) {
		fwrite($f, date('Y-m-d H:i:s') . ' ' . $msg . "\n");
		fclose($f);
	}
	echo $msg . "\n";
	flush();
};

if (php_sapi_name() === 'cli') {
	ini_set('display_errors', '1');
	error_reporting(E_ALL);
	$logFile = dirname(__DIR__) . '/cron_reminders.log';
	register_shutdown_function(function () use ($logFile) {
		$e = error_get_last();
		if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
			file_put_contents($logFile, date('Y-m-d H:i:s') . ' FATAL ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n", FILE_APPEND);
		}
	});
	$log('[booking_reminders] start');
}

$base = dirname(dirname(dirname(__DIR__)));
if (!is_file($base . '/includes/defines.php')) {
	$base = getcwd();
}
$log('base=' . $base);

if (!\defined('_JEXEC')) {
	\define('_JEXEC', 1);
}
require_once $base . '/includes/defines.php';
require_once $base . '/includes/framework.php';
\defined('_JEXEC') or die;

if (php_sapi_name() === 'cli') {
	if (empty($_SERVER['REQUEST_URI'])) {
		$_SERVER['REQUEST_URI'] = '/';
	}
	if (empty($_SERVER['SCRIPT_NAME']) || strpos($_SERVER['SCRIPT_NAME'], 'http') === 0) {
		$_SERVER['SCRIPT_NAME'] = '/index.php';
	}
	if (empty($_SERVER['HTTP_HOST'])) {
		$host = 'localhost';
		if (!class_exists('JConfig') && is_file($base . '/configuration.php')) {
			require_once $base . '/configuration.php';
		}
		if (class_exists('JConfig')) {
			$cfg = new \JConfig();
			$liveSite = isset($cfg->live_site) ? (string) $cfg->live_site : '';
			if ($liveSite && ($h = parse_url($liveSite, PHP_URL_HOST))) {
				$host = $h;
			}
		}
		$_SERVER['HTTP_HOST'] = $host;
	}
}

$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session.web', 'session.web.site')
	->alias('session', 'session.web.site')
	->alias('JSession', 'session.web.site')
	->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')
	->alias(\Joomla\Session\Session::class, 'session.web.site')
	->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;
(new \ReflectionMethod($app, 'initialise'))->invoke($app);
$app->createExtensionNamespaceMap();
$log('app initialised');

if (!class_exists(\Viglin\Component\Pushnotify\Site\Helper\NotificationSettingsHelper::class)) {
	$settingsHelper = $base . '/components/com_pushnotify/src/Helper/NotificationSettingsHelper.php';
	if (is_file($settingsHelper)) {
		require_once $settingsHelper;
	}
}
if (!class_exists(\Viglin\Plugin\System\Pushnotifybooking\Helper\BookingNotifyHelper::class)) {
	$notifyHelper = $base . '/plugins/system/pushnotifybooking/src/Helper/BookingNotifyHelper.php';
	if (is_file($notifyHelper)) {
		require_once $notifyHelper;
	}
}
if (!class_exists(\Viglin\Plugin\System\Pushnotifybooking\Helper\BookingNotifyHelper::class)) {
	echo "BookingNotifyHelper не найден.\n";
	exit(0);
}

$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
$prefix = $db->getPrefix();
$ordersTable = $prefix . 'vigling_bookings';
$remindersTable = $prefix . 'viglin_booking_reminders';

try {
	$db->setQuery("CREATE TABLE IF NOT EXISTS `{$remindersTable}` (
		`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		`user_id` INT UNSIGNED NOT NULL,
		`master_id` INT UNSIGNED NOT NULL,
		`order_time` DATETIME NOT NULL,
		`reminder_type` VARCHAR(32) NOT NULL DEFAULT 'started',
		`sent_at` DATETIME NOT NULL,
		UNIQUE KEY `idx_order_started` (`user_id`, `master_id`, `order_time`, `reminder_type`),
		KEY `idx_order_time` (`order_time`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")->execute();
} catch (\Throwable $e) {
	echo "Ошибка создания таблицы: " . $e->getMessage() . "\n";
	exit(1);
}

$columns = array_change_key_case($db->getTableColumns('#__vigling_bookings', false), CASE_LOWER);
$select = ['o.id', 'o.user_id', 'o.master_id', 'o.time', 'o.time_to', 'o.service_name'];
foreach (['booking_kind', 'course_id', 'course_slot_id', 'search_id', 'search_slot_id'] as $col) {
	if (isset($columns[$col])) {
		$select[] = 'o.' . $col;
	}
}

$offsets = \Viglin\Component\Pushnotify\Site\Helper\NotificationSettingsHelper::getReminderOffsets();
$legacy = (string) ($_SERVER['VIGLING_REMINDER_LEGACY'] ?? '');
if ($legacy === 'in_30min') {
	$offsets = [['minutes' => 30, 'event' => 'booking_in_30min', 'reminder_type' => 'in_30min', 'label' => 'За 30 минут']];
} elseif ($legacy === 'started') {
	$offsets = [['minutes' => 0, 'event' => 'booking_started', 'reminder_type' => 'started', 'label' => 'В момент начала']];
}

$nowObj = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
$now = $nowObj->format('Y-m-d H:i:s');
$verbose = (php_sapi_name() === 'cli' && isset($argv) && in_array('-v', $argv, true));
$totalSent = 0;

foreach ($offsets as $offset) {
	$minutes = (int) ($offset['minutes'] ?? 0);
	$reminderType = (string) ($offset['reminder_type'] ?? \Viglin\Component\Pushnotify\Site\Helper\NotificationSettingsHelper::reminderType($minutes));
	$targetTo = $minutes === 0 ? $nowObj : $nowObj->modify('+' . $minutes . ' minutes');
	$targetFrom = $minutes === 0 ? $nowObj->modify('-1 minutes') : $targetTo->modify('-1 minutes');

	$query = $db->getQuery(true)
		->select($select)
		->from($db->quoteName($ordersTable, 'o'))
		->where($db->quoteName('o.user_id') . ' > 0')
		->where($db->quoteName('o.time') . ' > ' . $db->quote($targetFrom->format('Y-m-d H:i:s')))
		->where($db->quoteName('o.time') . ' <= ' . $db->quote($targetTo->format('Y-m-d H:i:s')))
		->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName($remindersTable) . ' r WHERE r.user_id = o.user_id AND r.master_id = o.master_id AND r.order_time = o.time AND r.reminder_type = ' . $db->quote($reminderType) . ')');
	if ($minutes === 0) {
		$query->where($db->quoteName('o.time') . ' <= ' . $db->quote($now));
	}
	$db->setQuery($query);
	$rows = $db->loadAssocList() ?: [];

	if (php_sapi_name() === 'cli') {
		echo "Интервал {$reminderType} ({$minutes} мин.): найдено " . count($rows) . "\n";
	}
	if ($verbose) {
		foreach ($rows as $r) {
			echo "  id={$r['id']} user_id={$r['user_id']} master_id={$r['master_id']} time={$r['time']}\n";
		}
	}

	foreach ($rows as $row) {
		$order = [
			'id' => (int) ($row['id'] ?? 0),
			'user_id' => (int) ($row['user_id'] ?? 0),
			'master_id' => (int) ($row['master_id'] ?? 0),
			'time' => $row['time'] ?? '',
			'time_to' => $row['time_to'] ?? '',
			'service_name' => $row['service_name'] ?? 'Услуга',
			'booking_kind' => $row['booking_kind'] ?? 'service',
			'course_id' => (int) ($row['course_id'] ?? 0),
			'course_slot_id' => (int) ($row['course_slot_id'] ?? 0),
			'search_id' => (int) ($row['search_id'] ?? 0),
			'search_slot_id' => (int) ($row['search_slot_id'] ?? 0),
		];
		try {
			$db->setQuery(
				'INSERT IGNORE INTO ' . $db->quoteName($remindersTable)
				. ' (' . implode(', ', $db->quoteName(['user_id', 'master_id', 'order_time', 'reminder_type', 'sent_at'])) . ')'
				. ' VALUES ('
				. (int) $order['user_id'] . ','
				. (int) $order['master_id'] . ','
				. $db->quote($order['time']) . ','
				. $db->quote($reminderType) . ','
				. $db->quote($now)
				. ')'
			)->execute();
			if ((int) $db->getAffectedRows() === 0) {
				if ($verbose) {
					echo "  skip duplicate marker user_id={$order['user_id']} master_id={$order['master_id']} time={$order['time']} type={$reminderType}\n";
				}
				continue;
			}
		} catch (\Throwable $e) {
			if ($verbose) {
				echo "  marker insert failed: " . $e->getMessage() . "\n";
			}
			continue;
		}
		try {
			\Viglin\Plugin\System\Pushnotifybooking\Helper\BookingNotifyHelper::notifyReminder($order, $minutes);
		} catch (\Throwable $e) {
			if ($verbose) {
				echo "  notify failed after marker: " . $e->getMessage() . "\n";
			}
		}
		$totalSent++;
	}
}

if (php_sapi_name() === 'cli') {
	echo "Отправлено напоминаний: {$totalSent}\n";
}

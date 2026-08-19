<?php

namespace Viglin\Component\Pushnotify\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

class DisplayController extends BaseController
{
	protected $default_view = 'display';

	private function getDb()
	{
		return Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
	}

	private function jsonResponse($data): void
	{
		$app = Factory::getApplication();
		$app->setHeader('Content-Type', 'application/json; charset=utf-8');
		$app->sendHeaders();
		echo json_encode($data, JSON_UNESCAPED_UNICODE);
		$app->close();
	}

	private function requireUser(): ?int
	{
		$user = Factory::getApplication()->getIdentity();
		if (!$user || $user->id <= 0) {
			$this->jsonResponse(['success' => false, 'message' => 'Требуется авторизация']);
			return null;
		}
		return (int) $user->id;
	}

	public function subscribe()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		if (!Session::checkToken('post')) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный токен']);
			return;
		}

		$input = $this->input;
		$token = trim((string) $input->post->getString('token'));
		if ($token === '') {
			$this->jsonResponse(['success' => false, 'message' => 'Нет токена']);
			return;
		}

		$deviceType = trim((string) $input->post->getString('device_type', 'desktop'));
		$browser = trim((string) $input->post->getString('browser', ''));

		$db = $this->getDb();
		$now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$table = '#__pushnotify_subscriptions';

		$db->setQuery(
			'INSERT INTO ' . $db->quoteName($table)
			. ' (' . implode(', ', [
				$db->quoteName('user_id'),
				$db->quoteName('fcm_token'),
				$db->quoteName('device_type'),
				$db->quoteName('browser'),
				$db->quoteName('created'),
				$db->quoteName('last_used'),
			]) . ') VALUES ('
			. (int) $userId . ', '
			. $db->quote($token) . ', '
			. $db->quote($deviceType) . ', '
			. $db->quote($browser) . ', '
			. $db->quote($now) . ', '
			. $db->quote($now)
			. ') ON DUPLICATE KEY UPDATE '
			. $db->quoteName('id') . ' = LAST_INSERT_ID(' . $db->quoteName('id') . '), '
			. $db->quoteName('user_id') . ' = VALUES(' . $db->quoteName('user_id') . '), '
			. $db->quoteName('device_type') . ' = VALUES(' . $db->quoteName('device_type') . '), '
			. $db->quoteName('browser') . ' = VALUES(' . $db->quoteName('browser') . '), '
			. $db->quoteName('last_used') . ' = VALUES(' . $db->quoteName('last_used') . ')'
		)->execute();
		$subId = (int) $db->insertid();

		$prefTable = '#__pushnotify_preferences';
		$db->setQuery(
			'INSERT INTO ' . $db->quoteName($prefTable)
			. ' (' . $db->quoteName('user_id') . ', ' . $db->quoteName('notifications_enabled') . ')'
			. ' VALUES (' . (int) $userId . ', 1)'
			. ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('notifications_enabled') . ' = 1'
		)->execute();

		$this->jsonResponse(['success' => true, 'message' => 'Подписка успешна', 'subscription_id' => $subId]);
	}

	public function unsubscribe()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		if (!Session::checkToken('post')) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный токен']);
			return;
		}

		$token = trim((string) $this->input->post->getString('token'));
		if ($token === '') {
			$this->jsonResponse(['success' => false, 'message' => 'Нет токена']);
			return;
		}

		$db = $this->getDb();
		$db->setQuery(
			$db->getQuery(true)
				->delete($db->quoteName('#__pushnotify_subscriptions'))
				->where($db->quoteName('fcm_token') . ' = ' . $db->quote($token))
				->where($db->quoteName('user_id') . ' = ' . $userId)
		)->execute();

		$this->jsonResponse(['success' => true, 'message' => 'Подписка отменена']);
	}

	public function getPreferences()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		$db = $this->getDb();
		$db->setQuery(
			$db->getQuery(true)
				->select('notifications_enabled')
				->from($db->quoteName('#__pushnotify_preferences'))
				->where($db->quoteName('user_id') . ' = ' . $userId)
		);
		$enabled = $db->loadResult();
		$db->setQuery(
			$db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName('#__pushnotify_subscriptions'))
				->where($db->quoteName('user_id') . ' = ' . $userId)
		);
		$subscribed = (int) $db->loadResult() > 0;
		$currentToken = trim((string) $this->input->getString('token', ''));
		$currentTokenRegistered = false;
		if ($currentToken !== '') {
			$db->setQuery(
				$db->getQuery(true)
					->select('id')
					->from($db->quoteName('#__pushnotify_subscriptions'))
					->where($db->quoteName('user_id') . ' = ' . (int) $userId)
					->where($db->quoteName('fcm_token') . ' = ' . $db->quote($currentToken))
			);
			$currentTokenRegistered = (int) $db->loadResult() > 0;
		}
		$this->jsonResponse([
			'success' => true,
			'notifications_enabled' => (int) $enabled !== 0,
			'subscribed' => $subscribed,
			'current_token_registered' => $currentTokenRegistered,
		]);
	}

	public function updatePreferences()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		if (!Session::checkToken('post')) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный токен']);
			return;
		}

		$enabled = (int) $this->input->post->getInt('notifications_enabled', 1);
		$db = $this->getDb();
		$table = '#__pushnotify_preferences';
		$db->setQuery(
			$db->getQuery(true)
				->select('user_id')
				->from($db->quoteName($table))
				->where($db->quoteName('user_id') . ' = ' . $userId)
		);
		if ($db->loadResult()) {
			$db->setQuery(
				$db->getQuery(true)
					->update($db->quoteName($table))
					->set($db->quoteName('notifications_enabled') . ' = ' . $enabled)
					->where($db->quoteName('user_id') . ' = ' . $userId)
			)->execute();
		} else {
			$po = new \stdClass();
			$po->user_id = $userId;
			$po->notifications_enabled = $enabled;
			$db->insertObject($table, $po);
		}

		$this->jsonResponse(['success' => true, 'message' => 'Настройки обновлены']);
	}

	public function sendTest()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		if (!Session::checkToken('post')) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный токен']);
			return;
		}

		$result = \Viglin\Component\Pushnotify\Site\Helper\FcmHelper::sendNotification(
			$userId,
			'Тест VIGLING',
			'Проверка push-уведомлений на ПК. Всё работает.',
			['url' => rtrim(Uri::root(), '/') . '/lk'],
			'test'
		);

		if (($result['sent'] ?? 0) > 0) {
			$this->jsonResponse(['success' => true, 'message' => 'Тестовое уведомление отправлено. Проверьте рабочий стол браузера.']);
		} else {
			$this->jsonResponse(['success' => false, 'message' => 'Не отправлено. Проверьте: подписка активна, включены уведомления, установлен kreait/firebase-php в libraries.']);
		}
	}

	public function getInbox()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		$db = $this->getDb();
		$cols = $db->getTableColumns('#__pushnotify_inbox', false);
		if (empty($cols)) {
			$this->jsonResponse(['success' => true, 'items' => [], 'unread_count' => 0]);
			return;
		}

		$limit = (int) $this->input->getInt('limit', 50);
		$limit = min(max($limit, 1), 100);
		$offset = (int) $this->input->getInt('offset', 0);

		$q = $db->getQuery(true)
			->select('id, notification_type, title, body, order_id, read_at, created_at')
			->from($db->quoteName('#__pushnotify_inbox'))
			->where($db->quoteName('user_id') . ' = ' . (int) $userId)
			->where($db->quoteName('deleted_at') . ' IS NULL')
			->order($db->quoteName('created_at') . ' DESC')
			->setLimit($limit, $offset);
		$db->setQuery($q);
		$items = $db->loadObjectList() ?: [];

		$orderIds = [];
		foreach ($items as $it) {
			if (!empty($it->order_id) && strpos((string) $it->notification_type, 'booking_') === 0) {
				$orderIds[(int) $it->order_id] = true;
			}
		}
		$orderTimes = [];
		if (!empty($orderIds)) {
			$ordersTable = $db->getPrefix() . 'vigling_bookings';
			$idList = implode(',', array_map('intval', array_keys($orderIds)));
			$db->setQuery("SELECT id, " . $db->quoteName('time') . " FROM " . $db->quoteName($ordersTable) . " WHERE id IN ({$idList})");
			$rows = $db->loadAssocList() ?: [];
			foreach ($rows as $r) {
				$orderTimes[(int) $r['id']] = isset($r['time']) ? $r['time'] : null;
			}
		}
		foreach ($items as $it) {
			$it->event_time_utc = null;
			if (!empty($it->order_id) && isset($orderTimes[(int) $it->order_id]) && $orderTimes[(int) $it->order_id] !== null) {
				$t = $orderTimes[(int) $it->order_id];
				$it->event_time_utc = (strpos($t, 'T') !== false || strpos($t, '+') !== false) ? $t : str_replace(' ', 'T', $t) . 'Z';
			}
		}

		$qCount = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__pushnotify_inbox'))
			->where($db->quoteName('user_id') . ' = ' . (int) $userId)
			->where($db->quoteName('deleted_at') . ' IS NULL')
			->where($db->quoteName('read_at') . ' IS NULL');
		$db->setQuery($qCount);
		$unreadCount = (int) $db->loadResult();

		$this->jsonResponse([
			'success' => true,
			'items' => $items,
			'unread_count' => $unreadCount,
		]);
	}

	public function markRead()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		if (!Session::checkToken('post')) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный токен']);
			return;
		}

		$id = (int) $this->input->post->getInt('id');
		if ($id <= 0) {
			$this->jsonResponse(['success' => false, 'message' => 'Нет id']);
			return;
		}

		$db = $this->getDb();
		$now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$db->setQuery(
			$db->getQuery(true)
				->update($db->quoteName('#__pushnotify_inbox'))
				->set($db->quoteName('read_at') . ' = ' . $db->quote($now))
				->where($db->quoteName('id') . ' = ' . $id)
				->where($db->quoteName('user_id') . ' = ' . (int) $userId)
				->where($db->quoteName('deleted_at') . ' IS NULL')
		)->execute();

		$this->jsonResponse(['success' => true]);
	}

	public function deleteNotification()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		if (!Session::checkToken('post')) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный токен']);
			return;
		}

		$id = (int) $this->input->post->getInt('id');
		if ($id <= 0) {
			$this->jsonResponse(['success' => false, 'message' => 'Нет id']);
			return;
		}

		$db = $this->getDb();
		$now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$db->setQuery(
			$db->getQuery(true)
				->update($db->quoteName('#__pushnotify_inbox'))
				->set($db->quoteName('deleted_at') . ' = ' . $db->quote($now))
				->where($db->quoteName('id') . ' = ' . $id)
				->where($db->quoteName('user_id') . ' = ' . (int) $userId)
		)->execute();

		$this->jsonResponse(['success' => true]);
	}

	public function setTimezone()
	{
		$userId = $this->requireUser();
		if ($userId === null) return;

		$tz = trim((string) $this->input->getString('timezone', ''));
		if ($tz === '') {
			$this->jsonResponse(['success' => false, 'message' => 'Нет timezone']);
			return;
		}
		try {
			new \DateTimeZone($tz);
		} catch (\Exception $e) {
			$this->jsonResponse(['success' => false, 'message' => 'Неверный часовой пояс']);
			return;
		}
		$db = $this->getDb();
		$table = new \Joomla\CMS\Table\User($db);
		if (!$table->load($userId)) {
			$this->jsonResponse(['success' => false, 'message' => 'Пользователь не найден']);
			return;
		}
		$params = json_decode($table->params ?? '{}', true);
		if (!is_array($params)) {
			$params = [];
		}
		$params['timezone'] = $tz;
		$table->params = json_encode($params);
		if (!$table->store()) {
			$this->jsonResponse(['success' => false, 'message' => 'Ошибка сохранения']);
			return;
		}
		$this->jsonResponse(['success' => true]);
	}

	public function sw()
	{
		$configFile = JPATH_ROOT . '/configuration/firebase-config.php';
		if (!is_file($configFile)) {
			$configFile = JPATH_ROOT . '/configuration/firebase-config.php.example';
		}
		$config = is_file($configFile) ? (include $configFile) : [];
		if (!is_array($config)) {
			$config = [];
		}

		$app = Factory::getApplication();
		$app->setHeader('Content-Type', 'application/javascript; charset=utf-8');
		$app->setHeader('Service-Worker-Allowed', '/');
		$app->sendHeaders();

		$root = rtrim(Uri::root(), '/');
		$apiKey = isset($config['apiKey']) ? addslashes($config['apiKey']) : '';
		$authDomain = isset($config['authDomain']) ? addslashes($config['authDomain']) : '';
		$projectId = isset($config['projectId']) ? addslashes($config['projectId']) : '';
		$storageBucket = isset($config['storageBucket']) ? addslashes($config['storageBucket']) : '';
		$messagingSenderId = isset($config['messagingSenderId']) ? addslashes($config['messagingSenderId']) : '';
		$appId = isset($config['appId']) ? addslashes($config['appId']) : '';
		$vapidKey = isset($config['vapidKey']) ? addslashes($config['vapidKey']) : '';

		echo "importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js');\n";
		echo "importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js');\n";
		echo "firebase.initializeApp({ apiKey: '{$apiKey}', authDomain: '{$authDomain}', projectId: '{$projectId}', storageBucket: '{$storageBucket}', messagingSenderId: '{$messagingSenderId}', appId: '{$appId}' });\n";
		echo "const messaging = firebase.messaging();\n";
		echo "messaging.onBackgroundMessage(function(payload) {\n";
		echo "  console.log('[sw] onBackgroundMessage', payload);\n";
		echo "  var title = payload.notification && payload.notification.title ? payload.notification.title : 'Уведомление';\n";
		echo "  var body = payload.notification && payload.notification.body ? payload.notification.body : '';\n";
		echo "  var d = payload.data || {};\n";
		echo "  if (!d.url) d.url = '{$root}/';\n";
		echo "  var options = { body: body, data: d };\n";
		echo "  if (d.notification_tag) { options.tag = d.notification_tag; options.renotify = false; }\n";
		echo "  return self.registration.showNotification(title, options);\n";
		echo "});\n";
		echo "self.addEventListener('notificationclick', function(e) {\n";
		echo "  e.notification.close();\n";
		echo "  var url = e.notification.data && e.notification.data.url ? e.notification.data.url : '{$root}/';\n";
		echo "  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(c) {\n";
		echo "    for (var i = 0; i < c.length; i++) { if (c[i].url.indexOf(self.location.origin) === 0) { c[i].focus(); c[i].navigate(url); return; } }\n";
		echo "    if (clients.openWindow) clients.openWindow(url);\n";
		echo "  }));\n";
		echo "});\n";

		$app->close();
	}
}

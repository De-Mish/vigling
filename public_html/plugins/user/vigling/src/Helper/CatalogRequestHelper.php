<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * A master asks for a catalog entry that is not in the directory yet.
 * The request is a private message to the site administrators.
 */
final class CatalogRequestHelper
{
	public const SUBJECT = 'Нужных услуг нет в каталоге';
	public const REVIEW_SUBJECT = 'Запрос отзывов из других источников';
	public const SUCCESS = 'Спасибо, заявка отправлена';

	private const DAILY_LIMIT = 8;
	private const DUPLICATE_SECONDS = 900;
	private const SPEC_MAX = 120;
	private const SERVICE_MAX = 160;
	private const METHOD_MAX = 200;

	public static function submit($app): array
	{
		try {
			if (!Session::checkToken('post')) {
				return ['success' => false, 'message' => 'Неверный токен. Обновите страницу и отправьте заявку ещё раз.'];
			}
			$user = method_exists($app, 'getIdentity') ? $app->getIdentity() : null;
			if (!$user || empty($user->id)) {
				return ['success' => false, 'message' => 'Нужна авторизация.'];
			}
			$action = (string) $app->getInput()->post->get('action', '', 'cmd');
			if ($action === 'catalog_request') {
				return self::deliverCatalog($app, $user);
			}
			if ($action === 'contact_admin') {
				return self::deliverContact($app, $user);
			}
			if ($action === 'external_reviews') {
				return self::deliverReviews($app, $user);
			}

			return ['success' => false, 'message' => 'Неизвестное действие.'];
		} catch (\Throwable $e) {
			return ['success' => false, 'message' => 'Не удалось отправить заявку. Попробуйте позже.'];
		}
	}

	/**
	 * Same directory the profile settings use: every published second-level
	 * category, and the published articles inside it.
	 *
	 * @return list<array{id:int, title:string, services:list<array{id:int, title:string}>}>
	 */
	public static function directory(): array
	{
		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName(['id', 'title']))
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
				->where($db->quoteName('published') . ' = 1')
				->where($db->quoteName('level') . ' = 2')
				->order($db->quoteName('lft') . ' ASC');
			$db->setQuery($query);
			$categories = $db->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			return [];
		}

		$directory = [];
		$categoryIds = [];
		foreach ($categories as $row) {
			$id = (int) ($row['id'] ?? 0);
			$title = trim((string) ($row['title'] ?? ''));
			if ($id <= 0 || $title === '') {
				continue;
			}
			$categoryIds[] = $id;
			$directory[$id] = [
				'id' => $id,
				'title' => $title,
				'services' => [],
			];
		}
		if ($categoryIds === []) {
			return [];
		}

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$servicesQuery = $db->getQuery(true)
				->select($db->quoteName(['id', 'catid', 'title']))
				->from($db->quoteName('#__content'))
				->where($db->quoteName('state') . ' = 1')
				->where($db->quoteName('catid') . ' IN (' . implode(',', array_map('intval', $categoryIds)) . ')')
				->order($db->quoteName('catid') . ' ASC')
				->order($db->quoteName('title') . ' ASC');
			$db->setQuery($servicesQuery);
			$serviceRows = $db->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			return array_values($directory);
		}

		foreach ($serviceRows as $row) {
			$serviceId = (int) ($row['id'] ?? 0);
			$categoryId = (int) ($row['catid'] ?? 0);
			$title = trim((string) ($row['title'] ?? ''));
			if ($serviceId <= 0 || $categoryId <= 0 || $title === '' || !isset($directory[$categoryId])) {
				continue;
			}
			$directory[$categoryId]['services'][] = [
				'id' => $serviceId,
				'title' => $title,
			];
		}

		return array_values($directory);
	}

	private static function deliverCatalog($app, $user): array
	{
		if (!MasterGroupHelper::isMaster($user)) {
			return ['success' => false, 'message' => 'Заявку может отправить только мастер.'];
		}

		$post = $app->getInput()->post;
		$parsed = self::parseRequest($post, self::directory());
		if (!$parsed['ok']) {
			return ['success' => false, 'message' => $parsed['message']];
		}

		$lines = self::senderLines($user);
		$lines[] = 'Специализация: ' . $parsed['specialization'];
		$lines[] = 'Услуга: ' . $parsed['service'];
		$lines[] = 'Метод: ' . $parsed['method'];

		return self::sendToAdmins($app, $user, self::SUBJECT, self::htmlMessage($lines), 'vg:catalog');
	}

	private static function deliverContact($app, $user): array
	{
		$post = $app->getInput()->post;
		$subject = self::cleanText((string) $post->get('subject', '', 'string'), 120);
		$body = self::cleanBody((string) $post->get('body', '', 'raw'));
		if (self::tooShort($subject)) {
			return ['success' => false, 'message' => 'Введите тему сообщения.'];
		}
		if (self::tooLong((string) $post->get('subject', '', 'string'), 120)) {
			return ['success' => false, 'message' => 'Тема слишком длинная.'];
		}
		if (self::tooShort($body)) {
			return ['success' => false, 'message' => 'Напишите сообщение.'];
		}
		if (mb_strlen($body, 'UTF-8') >= 4000 && self::tooLongBody((string) $post->get('body', '', 'raw'), 4000)) {
			return ['success' => false, 'message' => 'Сообщение слишком длинное.'];
		}

		$lines = self::senderLines($user);
		$message = self::htmlMessage($lines) . "<br>\n" . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

		return self::sendToAdmins($app, $user, $subject, $message, 'vg:contact');
	}

	private static function deliverReviews($app, $user): array
	{
		$rawSources = $app->getInput()->post->get('sources', [], 'array');
		if (!is_array($rawSources)) {
			$rawSources = [];
		}
		$links = [];
		foreach ($rawSources as $raw) {
			if (!is_scalar($raw)) {
				continue;
			}
			$link = self::cleanLink((string) $raw);
			if ($link === '') {
				continue;
			}
			if (!self::isLink($link)) {
				return ['success' => false, 'message' => 'Проверьте ссылки: нужна ссылка на страницу источника.'];
			}
			$links[] = $link;
		}
		$links = array_values(array_unique($links));
		if ($links === []) {
			return ['success' => false, 'message' => 'Вставьте ссылку хотя бы на один источник.'];
		}
		if (count($links) > 12) {
			return ['success' => false, 'message' => 'Можно отправить не больше 12 источников.'];
		}

		$lines = self::senderLines($user);
		foreach ($links as $index => $link) {
			$lines[] = 'Источник ' . ($index + 1) . ': ' . $link;
		}

		return self::sendToAdmins($app, $user, self::REVIEW_SUBJECT, self::htmlMessage($lines), 'vg:reviews');
	}

	private static function senderLines($user): array
	{
		$userId = (int) $user->id;
		$name = trim((string) ($user->name ?? ''));
		if ($name === '') {
			$name = trim((string) ($user->username ?? ''));
		}
		if ($name === '') {
			$name = 'Пользователь #' . $userId;
		}
		$role = MasterGroupHelper::isMaster($user) ? 'Мастер' : 'Клиент';
		$lines = [
			$role . ': ' . $name . ' (id ' . $userId . ')',
		];
		$email = trim((string) ($user->email ?? ''));
		if ($email !== '') {
			$lines[] = 'Email: ' . $email;
		}
		$lines[] = 'Профиль: ' . rtrim(Uri::root(), '/') . '/' . $userId;

		return $lines;
	}

	private static function sendToAdmins($app, $user, string $subject, string $message, string $marker): array
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$userId = (int) $user->id;
		$storedMessage = '<!-- ' . $marker . ' -->' . $message;
		$duplicateSince = Factory::getDate('-' . self::DUPLICATE_SECONDS . ' seconds')->toSql();
		if (self::countMessages($db, $userId, $duplicateSince, $storedMessage, $subject, null) > 0) {
			return ['success' => false, 'message' => 'Такая заявка уже отправлена.'];
		}
		$daySince = Factory::getDate('-1 day')->toSql();
		if (self::countMessages($db, $userId, $daySince, null, null, $marker) >= self::DAILY_LIMIT) {
			return ['success' => false, 'message' => 'За последние сутки уже отправлено много заявок. Попробуйте завтра.'];
		}

		$adminIds = self::adminRecipientIds($db);
		if ($adminIds === [] || !method_exists($app, 'bootComponent')) {
			return ['success' => false, 'message' => 'Сейчас нет администратора, который может принять заявку.'];
		}

		$model = $app->bootComponent('com_messages')->getMVCFactory()->createModel('Message', 'Administrator');
		$stored = 0;
		foreach ($adminIds as $adminId) {
			if (method_exists($model, 'setState')) {
				$model->setState('message.id', 0);
			}
			$saved = $model->save([
				'user_id_from' => $userId,
				'user_id_to' => $adminId,
				'subject' => $subject,
				'message' => $storedMessage,
			]);
			$storedId = method_exists($model, 'getState') ? (int) $model->getState('message.id', 0) : 0;
			if ($saved || $storedId > 0) {
				$stored++;
			}
		}

		if ($stored < 1) {
			return ['success' => false, 'message' => 'Не удалось отправить заявку. Попробуйте позже.'];
		}

		return [
			'success' => true,
			'message' => self::SUCCESS,
		];
	}

	/**
	 * @param list<array{id:int, title:string, services:list<array{id:int, title:string}>}> $directory
	 * @return array{ok:bool, message:string, specialization:string, service:string, method:string}
	 */
	private static function parseRequest($post, array $directory): array
	{
		$empty = ['ok' => false, 'message' => '', 'specialization' => '', 'service' => '', 'method' => ''];
		$specMode = (string) $post->get('specialization_mode', '', 'cmd');
		$serviceMode = (string) $post->get('service_mode', '', 'cmd');
		$specId = (int) $post->get('specialization_id', 0, 'int');
		$serviceId = (int) $post->get('service_id', 0, 'int');
		$specText = self::cleanText((string) $post->get('specialization_text', '', 'string'), self::SPEC_MAX);
		$serviceText = self::cleanText((string) $post->get('service_text', '', 'string'), self::SERVICE_MAX);
		$methodText = self::cleanText((string) $post->get('method_text', '', 'string'), self::METHOD_MAX);

		$category = null;
		foreach ($directory as $row) {
			if ((int) $row['id'] === $specId) {
				$category = $row;
				break;
			}
		}

		if ($specMode === 'new') {
			if (self::tooShort($specText)) {
				$empty['message'] = 'Введите предлагаемую специализацию.';
				return $empty;
			}
			if (self::tooLong((string) $post->get('specialization_text', '', 'string'), self::SPEC_MAX)) {
				$empty['message'] = 'Название специализации слишком длинное.';
				return $empty;
			}
			foreach ($directory as $row) {
				if (self::sameTitle($row['title'], $specText)) {
					$empty['message'] = 'Такая специализация уже есть в каталоге. Выберите её из списка.';
					return $empty;
				}
			}
			$specialization = 'новая — «' . $specText . '»';
			$serviceMode = 'new';
		} elseif ($specMode === 'existing' && $category !== null) {
			$specialization = $category['title'] . ' (id ' . (int) $category['id'] . ')';
		} else {
			$empty['message'] = 'Выберите специализацию.';
			return $empty;
		}

		if ($serviceMode === 'new') {
			if (self::tooShort($serviceText)) {
				$empty['message'] = 'Введите предлагаемую услугу.';
				return $empty;
			}
			if (self::tooLong((string) $post->get('service_text', '', 'string'), self::SERVICE_MAX)) {
				$empty['message'] = 'Название услуги слишком длинное.';
				return $empty;
			}
			if ($category !== null) {
				foreach ($category['services'] as $service) {
					if (self::sameTitle($service['title'], $serviceText)) {
						$empty['message'] = 'Такая услуга уже есть в этой специализации. Выберите её из списка.';
						return $empty;
					}
				}
			}
			$service = 'новая — «' . $serviceText . '»';
		} elseif ($serviceMode === 'existing' && $category !== null) {
			$matched = null;
			foreach ($category['services'] as $serviceRow) {
				if ((int) $serviceRow['id'] === $serviceId) {
					$matched = $serviceRow;
					break;
				}
			}
			if ($matched === null) {
				$empty['message'] = 'Выберите услугу.';
				return $empty;
			}
			$service = $matched['title'] . ' (id ' . (int) $matched['id'] . ')';
		} else {
			$empty['message'] = 'Выберите услугу.';
			return $empty;
		}

		if (self::tooShort($methodText)) {
			$empty['message'] = 'Введите метод услуги.';
			return $empty;
		}
		if (self::tooLong((string) $post->get('method_text', '', 'string'), self::METHOD_MAX)) {
			$empty['message'] = 'Название метода слишком длинное.';
			return $empty;
		}

		return [
			'ok' => true,
			'message' => '',
			'specialization' => $specialization,
			'service' => $service,
			'method' => $methodText,
		];
	}

	private static function cleanText(string $value, int $max): string
	{
		$value = strip_tags($value);
		$value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
		$value = preg_replace('/\s+/u', ' ', $value) ?? '';
		$value = trim($value);
		if (mb_strlen($value, 'UTF-8') > $max) {
			return mb_substr($value, 0, $max, 'UTF-8');
		}

		return $value;
	}

	private static function cleanBody(string $value, int $max): string
	{
		$value = strip_tags($value);
		$value = str_replace(["\r\n", "\r"], "\n", $value);
		$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
		$value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';
		$value = trim($value);
		if (mb_strlen($value, 'UTF-8') > $max) {
			return mb_substr($value, 0, $max, 'UTF-8');
		}

		return $value;
	}

	private static function tooLongBody(string $raw, int $max): bool
	{
		return mb_strlen(self::cleanBody($raw, $max + 1), 'UTF-8') > $max;
	}

	private static function cleanLink(string $value): string
	{
		$value = trim(strip_tags($value));
		$value = preg_replace('/\s+/u', '', $value) ?? '';
		if (preg_match('#^(javascript|data|vbscript):#i', $value)) {
			return '';
		}

		return $value;
	}

	private static function isLink(string $value): bool
	{
		$length = mb_strlen($value, 'UTF-8');
		if ($length < 4 || $length > 500) {
			return false;
		}

		return (bool) preg_match('#^(https?://|www\.)#i', $value) || str_contains($value, '.');
	}

	private static function tooShort(string $value): bool
	{
		return mb_strlen($value, 'UTF-8') < 2;
	}

	private static function tooLong(string $raw, int $max): bool
	{
		$clean = strip_tags($raw);
		$clean = preg_replace('/\s+/u', ' ', trim($clean)) ?? '';

		return mb_strlen($clean, 'UTF-8') > $max;
	}

	private static function sameTitle(string $left, string $right): bool
	{
		return mb_strtolower($left, 'UTF-8') === mb_strtolower($right, 'UTF-8');
	}

	private static function htmlMessage(array $lines): string
	{
		$safe = [];
		foreach ($lines as $line) {
			$safe[] = htmlspecialchars((string) $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}

		return implode("<br>\n", $safe);
	}

	private static function countMessages(DatabaseInterface $db, int $userId, string $since, ?string $message, ?string $subject, ?string $marker): int
	{
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__messages'))
			->where($db->quoteName('user_id_from') . ' = ' . $userId)
			->where($db->quoteName('date_time') . ' >= ' . $db->quote($since));
		if ($subject !== null) {
			$query->where($db->quoteName('subject') . ' = ' . $db->quote($subject));
		}
		if ($message !== null) {
			$query->where($db->quoteName('message') . ' = ' . $db->quote($message));
		}
		if ($marker !== null) {
			$query->where($db->quoteName('message') . ' LIKE ' . $db->quote('%' . $db->escape($marker, true) . '%', false));
		}
		$db->setQuery($query);

		return (int) $db->loadResult();
	}

	/**
	 * Super users, not only those who opted into system email.
	 * The inbox is the review queue; system email is separate.
	 *
	 * @return list<int>
	 */
	private static function adminRecipientIds(DatabaseInterface $db): array
	{
		$groups = [MasterGroupHelper::SUPER_USER_GROUP_ID];
		try {
			$table = new Asset($db);
			$rootId = (int) $table->getRootId();
			$rules = Access::getAssetRules($rootId)->getData();
			$adminRule = $rules['core.admin'] ?? null;
			if (is_object($adminRule) && method_exists($adminRule, 'getData')) {
				foreach ($adminRule->getData() as $groupId => $enabled) {
					if ($enabled) {
						$groups[] = (int) $groupId;
					}
				}
			}
		} catch (\Throwable $e) {
			$groups = [MasterGroupHelper::SUPER_USER_GROUP_ID];
		}

		$groups = array_values(array_unique(array_filter($groups, static function (int $id): bool {
			return $id > 0;
		})));
		if ($groups === []) {
			return [];
		}

		$query = $db->getQuery(true)
			->select($db->quoteName('map.user_id'))
			->from($db->quoteName('#__user_usergroup_map', 'map'))
			->join(
				'INNER',
				$db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('map.user_id')
			)
			->whereIn($db->quoteName('map.group_id'), $groups)
			->where($db->quoteName('u.block') . ' = 0');
		$db->setQuery($query);
		$ids = array_map('intval', $db->loadColumn() ?: []);

		return array_values(array_unique(array_filter($ids, static function (int $id): bool {
			return $id > 0;
		})));
	}
}

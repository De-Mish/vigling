<?php
namespace Joomla\Plugin\System\Profileuserid\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Event\Application\AfterInitialiseEvent;
use Joomla\CMS\Event\Application\AfterRouteEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Router\Router;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

final class Profileuserid extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterInitialise' => ['onAfterInitialise', 100],
			'onAfterRoute' => 'onAfterRoute',
		];
	}

	/**
	 * Site-relative public profile URL: /144675 or /144675?cat_id=1&service=2&tag=3
	 */
	public static function publicProfileUrl(int $userId, array $extra = []): string
	{
		$userId = (int) $userId;
		if ($userId <= 0) {
			return '';
		}

		$url = rtrim(Uri::root(true), '/') . '/' . $userId;
		$query = [];
		foreach ($extra as $key => $value) {
			if ($value === null || $value === '') {
				continue;
			}
			$query[$key] = $value;
		}
		if ($query !== []) {
			$url .= '?' . http_build_query($query);
		}

		return $url;
	}

	public function onAfterInitialise(AfterInitialiseEvent $event): void
	{
		$app = $event->getApplication();
		if (!$app->isClient('site')) {
			return;
		}

		$router = $app->getRouter();
		$router->attachParseRule([$this, 'parseNumericProfile'], Router::PROCESS_BEFORE);
		$router->attachParseRule([$this, 'parseStandalonePages'], Router::PROCESS_BEFORE);
		// PROCESS_DURING runs after SiteRouter::buildSefRoute, so /lk is not prepended.
		$router->attachBuildRule([$this, 'buildNumericProfile'], Router::PROCESS_DURING);
	}

	/**
	 * Map a single numeric path segment (/144675) to com_users profile.
	 * Does not consume nested numeric segments such as /poisk-spetsialistov/16.
	 */
	public function parseNumericProfile(&$router, &$uri)
	{
		$path = trim((string) $uri->getPath(), '/');
		if (!preg_match('/^[1-9][0-9]*$/', $path)) {
			return [];
		}

		if ($this->hasConflictingNumericRoute($path)) {
			return [];
		}

		$userId = (int) $path;
		$uri->setPath('');
		$uri->setVar('option', 'com_users');
		$uri->setVar('view', 'profile');
		$uri->setVar('user_id', $userId);

		return [
			'option' => 'com_users',
			'view' => 'profile',
			'user_id' => $userId,
		];
	}

	public function parseStandalonePages(&$router, &$uri)
	{
		$path = strtolower(trim((string) $uri->getPath(), '/'));
		if ($path !== 'privacy-policy') {
			return [];
		}

		$uri->setPath('');
		$uri->setVar('option', 'com_content');
		$uri->setVar('view', 'featured');
		$uri->setVar('privacy_page', '1');

		return [
			'option' => 'com_content',
			'view' => 'featured',
			'privacy_page' => '1',
		];
	}

	/**
	 * Emit /144675 for public profiles. Leave /lk, login, registration and layout=edit alone.
	 *
	 * Runs after SiteRouter::buildSefRoute, which may already have turned the URI into /lk
	 * and left user_id in the query string.
	 */
	public function buildNumericProfile(&$router, &$uri): void
	{
		$userId = (int) $uri->getVar('user_id', 0);
		if ($userId <= 0) {
			return;
		}
		if ((string) $uri->getVar('layout', '') === 'edit') {
			return;
		}
		if ((string) $uri->getVar('task', '') !== '') {
			return;
		}

		$option = (string) $uri->getVar('option', '');
		$view = (string) $uri->getVar('view', '');
		if ($option !== '' && $option !== 'com_users') {
			return;
		}
		if ($view !== '' && $view !== 'profile') {
			return;
		}

		$path = trim(str_replace('index.php', '', (string) $uri->getPath()), '/');
		if ($option === '' && $view === '') {
			$looksLikeProfile = $path === 'lk'
				|| str_starts_with($path, 'lk/')
				|| $path === 'component/users'
				|| str_starts_with($path, 'component/users/')
				|| $path === 'profile'
				|| str_ends_with($path, '/profile');
			if (!$looksLikeProfile) {
				return;
			}
		}

		$uri->setPath((string) $userId);
		$uri->delVar('option');
		$uri->delVar('view');
		$uri->delVar('user_id');
		$uri->delVar('Itemid');
	}

	public function onAfterRoute(AfterRouteEvent $event): void
	{
		$app = $event->getApplication();
		if ($app->isClient('administrator')) {
			return;
		}

		if ($this->handleEmailVerificationBridge($app)) {
			return;
		}

		$input = $app->input;
		$qView = isset($_GET['view']) ? (string) $_GET['view'] : '';
		if ($input->get('option') === 'com_users' && $qView !== '') {
			if ($qView === 'login') {
				$app->redirect(Route::_('index.php?option=com_users&view=login', false));
			}
			if ($qView === 'registration') {
				$regItems = $app->getMenu()->getItems('link', 'index.php?option=com_users&view=registration', true);
				$url = !empty($regItems) ? Route::_('index.php?option=com_users&view=registration', false) : Route::_('index.php?option=com_users&view=registration', false);
				$app->redirect($url);
			}
		}
		if ($input->get('option') !== 'com_users' || $input->get('view') !== 'profile') {
			return;
		}
		if ($input->get('layout') === 'edit') {
			return;
		}
		$userId = (int) $input->get('user_id', 0);
		$activeMenu = $app->getMenu()->getActive();
		$menuAlias = (string) ($activeMenu->alias ?? '');
		$menuPath = (string) ($activeMenu->path ?? '');
		$isLkContext = ($menuAlias === 'lk' || $menuPath === 'lk' || str_starts_with($menuPath, 'lk/'));

		// Hard guard: /lk is private. Do not allow opening foreign profiles through user_id.
		if ($isLkContext) {
			$currentUserId = (int) $app->getIdentity()->id;
			if ($currentUserId <= 0) {
				$app->setUserState('com_users.edit.profile.id', null);
				$app->redirect(Route::_('index.php?option=com_users&view=login', false));
				return;
			}
			if ($userId > 0 && $userId !== $currentUserId) {
				$app->redirect(Route::_('index.php?option=com_users&view=profile', false));
				return;
			}
			$app->setUserState('com_users.edit.profile.id', $currentUserId);
			return;
		}

		if ($userId > 0) {
			$this->maybeRedirectToShortProfileUrl($app, $userId);
			$app->setUserState('com_users.edit.profile.id', $userId);
		} else {
			$app->setUserState('com_users.edit.profile.id', null);
		}
	}

	private function maybeRedirectToShortProfileUrl($app, int $userId): void
	{
		if (strtoupper((string) $app->getInput()->getMethod()) !== 'GET') {
			return;
		}
		if ((string) $app->getInput()->getCmd('task', '') !== '') {
			return;
		}

		$path = $this->currentSitePath();
		if ($this->isLkPath($path)) {
			return;
		}
		if ($path === (string) $userId) {
			return;
		}

		$target = rtrim(Uri::root(), '/') . '/' . $userId;
		$query = Uri::getInstance()->getQuery(true);
		unset($query['option'], $query['view'], $query['user_id'], $query['Itemid']);
		if ($query !== []) {
			$target .= '?' . http_build_query($query);
		}

		$app->redirect($target, 301);
	}

	private function currentSitePath(): string
	{
		$path = trim((string) Uri::getInstance()->getPath(), '/');
		$base = trim((string) Uri::root(true), '/');
		if ($base !== '') {
			if ($path === $base) {
				return '';
			}
			if (str_starts_with($path, $base . '/')) {
				$path = substr($path, strlen($base) + 1);
			}
		}
		if (str_starts_with($path, 'index.php')) {
			$path = trim(substr($path, strlen('index.php')), '/');
		}

		return $path;
	}

	private function isLkPath(string $path): bool
	{
		return $path === 'lk' || str_starts_with($path, 'lk/');
	}

	private function hasConflictingNumericRoute(string $segment): bool
	{
		$items = Factory::getApplication()->getMenu()->getItems('alias', $segment) ?: [];
		foreach ((array) $items as $item) {
			if ((int) ($item->published ?? 0) !== 1) {
				continue;
			}
			$route = trim((string) ($item->route ?? $item->alias ?? ''), '/');
			if ($route === $segment || (int) ($item->parent_id ?? 0) === 1) {
				return true;
			}
		}

		return false;
	}

	private function handleEmailVerificationBridge($app): bool
	{
		if (PluginHelper::isEnabled('system', 'emailverification')) {
			return false;
		}

		$servicePath = JPATH_SITE . '/plugins/system/emailverification/src/Service/EmailVerificationService.php';
		if (!is_file($servicePath)) {
			return false;
		}
		require_once $servicePath;

		$serviceClass = '\\Joomla\\Plugin\\System\\Emailverification\\Service\\EmailVerificationService';
		if (!class_exists($serviceClass)) {
			return false;
		}

		try {
			/** @var DatabaseInterface $db */
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$serviceClass::ensureSchema($db);
			$serviceClass::enforceExpiredPendingBatch($db, 200);
		} catch (\Throwable $e) {
			Log::add('emailverification_bridge_schema_init_failed: ' . $e->getMessage(), Log::ERROR, 'emailverification');
			return false;
		}

		$app->getLanguage()->load('plg_system_emailverification', JPATH_SITE . '/plugins/system/emailverification');
		$input = $app->input;
		$option = $input->getCmd('option');
		$task = (string) $input->getString('task', '');

		if ($option === 'com_users' && $task === 'profile.verifyEmail') {
			$token = (string) $input->getString('token', '');
			$result = $serviceClass::verifyByToken($db, $token);
			if (!empty($result['ok'])) {
				$app->enqueueMessage(Text::_((string) $result['message_key']), 'message');
				$identity = $app->getIdentity();
				$target = ((int) ($identity->id ?? 0) > 0)
					? 'index.php?option=com_users&view=profile'
					: 'index.php?option=com_users&view=login';
				$app->redirect(Route::_($target, false));
				return true;
			}
			$app->enqueueMessage(Text::_((string) ($result['message_key'] ?? 'PLG_SYSTEM_EMAILVERIFICATION_TOKEN_INVALID')), 'warning');
			$app->redirect(Route::_('index.php?option=com_users&view=login', false));
			return true;
		}

		$userId = (int) ($app->getIdentity()->id ?? 0);
		if ($userId <= 0) {
			return false;
		}

		try {
			if ($serviceClass::enforceGraceForUser($db, $userId)) {
				$app->enqueueMessage(Text::_('PLG_SYSTEM_EMAILVERIFICATION_ACCOUNT_BLOCKED_WARNING'), 'warning');
				$app->logout($userId, ['clientid' => 0]);
				$app->redirect(Route::_('index.php?option=com_users&view=login', false));
				return true;
			}
			$state = $serviceClass::getUserVerificationState($db, $userId);
			if ($state && ($state['status'] ?? '') === $serviceClass::STATUS_BLOCKED) {
				$app->enqueueMessage(Text::_('PLG_SYSTEM_EMAILVERIFICATION_ACCOUNT_BLOCKED_WARNING'), 'warning');
				$app->logout($userId, ['clientid' => 0]);
				$app->redirect(Route::_('index.php?option=com_users&view=login', false));
				return true;
			}
			if ($state && ($state['status'] ?? '') === 'pending') {
				$app->enqueueMessage(
					Text::sprintf('PLG_SYSTEM_EMAILVERIFICATION_PENDING_WARNING', $serviceClass::getGracePeriodHumanLabel()),
					'warning'
				);
			}
		} catch (\Throwable $e) {
			Log::add('emailverification_bridge_runtime_failed: ' . $e->getMessage(), Log::ERROR, 'emailverification');
		}

		return false;
	}
}

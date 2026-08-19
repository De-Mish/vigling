<?php
namespace Joomla\Plugin\System\Profileuserid\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Event\Application\AfterRouteEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

final class Profileuserid extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return ['onAfterRoute' => 'onAfterRoute'];
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
			$app->setUserState('com_users.edit.profile.id', $userId);
		} else {
			$app->setUserState('com_users.edit.profile.id', null);
		}
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

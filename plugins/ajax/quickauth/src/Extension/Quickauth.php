<?php

namespace Viglin\Plugin\Ajax\Quickauth\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

final class Quickauth extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			'onAjaxQuickauth' => 'onAjaxQuickauth',
		];
	}

	public function onAjaxQuickauth(AjaxEvent $event): void
	{
		$app = $event->getApplication();
		if (!$app->isClient('site')) {
			$event->updateEventResult(['success' => false, 'message' => 'Forbidden']);
			return;
		}
		if (!Session::checkToken('request')) {
			$this->loadLanguage();
			$event->updateEventResult(['success' => false, 'message' => Text::_('PLG_AJAX_QUICKAUTH_ERR_TOKEN')]);
			return;
		}
		$input = $app->getInput();
		$action = $input->getCmd('action', '');
		if ($action === 'login') {
			$this->handleLogin($event, $app, $input);
			return;
		}
		if ($action === 'register') {
			$this->handleRegister($event, $app, $input);
			return;
		}
		if ($action === 'toggle_favorite') {
			$this->toggleFavorite($event, $app, $input);
			return;
		}
		if ($action === 'resend_verification') {
			$this->handleResendVerification($event, $app, $input);
			return;
		}
		$event->updateEventResult(['success' => false, 'message' => 'Invalid action']);
	}

	private function handleLogin(AjaxEvent $event, $app, $input): void
	{
		$return = $this->resolveReturnUrl($input->get('return', '', 'RAW'));
		$username = $input->get('username', '', 'USERNAME');
		$password = $input->get('password', '', 'RAW');
		if ($username === '' || $password === '') {
			$this->loadLanguage();
			$event->updateEventResult(['success' => false, 'message' => Text::_('PLG_AJAX_QUICKAUTH_ERR_LOGIN')]);
			return;
		}
		$credentials = ['username' => $username, 'password' => $password];
		if (true !== $app->login($credentials, ['remember' => false])) {
			$blockedByUnverified = $this->isBlockedByUnverified($username);
			$this->loadLanguage();
			$message = $blockedByUnverified
				? Text::_('PLG_AJAX_QUICKAUTH_ERR_NOT_VERIFIED')
				: Text::_('PLG_AJAX_QUICKAUTH_ERR_LOGIN');
			$event->updateEventResult([
				'success' => false,
				'message' => $message,
				'reason_key' => $blockedByUnverified ? 'email_verification_blocked' : 'invalid_credentials',
				'redirect' => $blockedByUnverified ? Route::_('index.php?option=com_users&view=login', false) : '',
			]);
			return;
		}
		$app->setUserState('users.login.form.data', []);
		$redirect = $this->buildRedirectUrl($return);
		$event->updateEventResult(['success' => true, 'redirect' => $redirect, 'form_token' => Session::getFormToken()]);
	}

	private function handleRegister(AjaxEvent $event, $app, $input): void
	{
		if (ComponentHelper::getParams('com_users')->get('allowUserRegistration') == 0) {
			$this->loadLanguage();
			$event->updateEventResult(['success' => false, 'message' => Text::_('PLG_AJAX_QUICKAUTH_ERR_REG_DISABLED')]);
			return;
		}

		$recaptchaCheck = $this->verifyRecaptchaBeforeRegistration($input);
		if (!$recaptchaCheck['allowed']) {
			$event->updateEventResult([
				'success' => false,
				'message' => $recaptchaCheck['message'] ?: 'Подтвердите, что вы не робот',
			]);
			return;
		}

		$this->loadLanguage();
		$app->getLanguage()->load('com_users', JPATH_SITE);
		$return = $this->resolveReturnUrl($input->get('return', '', 'RAW'));
		$jform = $input->post->get('jform', [], 'array');
		if (!is_array($jform)) {
			$jform = [];
		}
		$input->post->set('option', 'com_users');
		$input->post->set('task', 'registration.register');
		$input->post->set('jform', $jform);
		$comUsersPath = JPATH_SITE . '/components/com_users';
		Form::addFormPath($comUsersPath . '/forms');
		Form::addFormPath($comUsersPath . '/models/forms');
		Form::addFieldPath($comUsersPath . '/models/fields');
		$model = $app->bootComponent('com_users')->getMVCFactory()->createModel('Registration', 'Site');
		$form = $model->getForm();
		if (!$form) {
			$event->updateEventResult(['success' => false, 'message' => $this->translateMessage($model->getError() ?: 'Form error')]);
			return;
		}
		$data = $model->validate($form, $jform);
		if ($data === false) {
			$errors = $model->getErrors();
			$msg = 'Ошибка заполнения';
			if (!empty($errors) && $errors[0] instanceof \Throwable) {
				$msg = $errors[0]->getMessage();
			} elseif (!empty($errors)) {
				$msg = (string) $errors[0];
			}
			$event->updateEventResult(['success' => false, 'message' => $this->translateMessage($msg)]);
			return;
		}
		$data = $this->normalizeForMailTemplate($data);
		$result = $model->register($data);
		if ($result === false) {
			$event->updateEventResult(['success' => false, 'message' => $this->translateMessage($model->getError() ?: 'Ошибка регистрации')]);
			return;
		}
		$app->setUserState('com_users.registration.data', null);
		if ($result === 'adminactivate' || $result === 'useractivate') {
			$this->loadLanguage();
			$event->updateEventResult(['success' => false, 'message' => Text::_('PLG_AJAX_QUICKAUTH_ERR_ACTIVATION')]);
			return;
		}
		$username = isset($jform['username']) ? trim((string) $jform['username']) : '';
		$password = isset($jform['password1']) ? (string) $jform['password1'] : '';
		if ($username !== '' && $password !== '') {
			$credentials = ['username' => $username, 'password' => $password];
			$app->login($credentials, ['remember' => false]);
		}
		$redirect = $this->buildRedirectUrl($return);
		$event->updateEventResult(['success' => true, 'redirect' => $redirect, 'form_token' => Session::getFormToken()]);
	}

	private function handleResendVerification(AjaxEvent $event, $app, $input): void
	{
		$this->loadLanguage();
		$app->getLanguage()->load('plg_system_emailverification', JPATH_SITE . '/plugins/system/emailverification');

		$serviceClass = $this->getVerificationServiceClass();
		if ($serviceClass === null) {
			$event->updateEventResult([
				'success' => false,
				'message' => Text::_('PLG_AJAX_QUICKAUTH_RESEND_GENERIC'),
				'reason_key' => 'service_unavailable',
				'requires_verification' => true,
				'resend_allowed' => false,
			]);
			return;
		}

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$userId = (int) ($app->getIdentity()->id ?? 0);
			$email = trim((string) $input->post->getString('email', $input->getString('email', '')));
			if ($email === '' && $userId > 0) {
				$email = trim((string) ($app->getIdentity()->email ?? ''));
			}
			$result = null;

			if ($userId > 0) {
				$result = $serviceClass::resendForUserId($db, $userId, 120);
				$reason = (string) ($result['reason_key'] ?? '');
				if (!$result['ok'] && $reason === 'no_state' && $email !== '') {
					// Fallback by email in case user session identity and verification row got out of sync.
					$result = $serviceClass::resendByEmail($db, $email, 120);
				}
			} else {
				$result = $serviceClass::resendByEmail($db, $email, 120);
			}

			$key = (string) ($result['message_key'] ?? 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC');
			$event->updateEventResult([
				'success' => !empty($result['ok']),
				'message' => Text::_($key),
				'reason_key' => (string) ($result['reason_key'] ?? 'unknown'),
				'requires_verification' => isset($result['requires_verification']) ? (bool) $result['requires_verification'] : true,
				'resend_allowed' => isset($result['resend_allowed']) ? (bool) $result['resend_allowed'] : !empty($result['ok']),
			]);
			return;
		} catch (\Throwable $e) {
			Log::add(
				'quickauth_resend_exception msg=' . $e->getMessage()
				. ' at=' . $e->getFile() . ':' . $e->getLine(),
				Log::ERROR,
				'emailverification'
			);
			$event->updateEventResult([
				'success' => false,
				'message' => Text::_('PLG_AJAX_QUICKAUTH_RESEND_GENERIC'),
				'reason_key' => 'exception',
				'requires_verification' => true,
				'resend_allowed' => false,
			]);
			return;
		}
	}

	private function verifyRecaptchaBeforeRegistration($input): array
	{
		$verifierClass = $this->getRecaptchaVerifierClass();

		if ($verifierClass === null) {
			return ['allowed' => true, 'message' => ''];
		}

		$token = trim((string) $input->post->getString('recaptcha_token', $input->getString('recaptcha_token', '')));
		$action = trim((string) $input->post->getString('recaptcha_action', $input->getString('recaptcha_action', '')));

		$context = 'quickauth_register';
		$expectedAction = $verifierClass::ACTION_QUICKAUTH_REGISTER;

		if ($action === $verifierClass::ACTION_BOOKING_QUICKAUTH_REGISTER) {
			$context = 'booking_quickauth_register';
			$expectedAction = $verifierClass::ACTION_BOOKING_QUICKAUTH_REGISTER;
		}

		$result = $verifierClass::verify(
			$token,
			$expectedAction,
			$context,
			(string) $input->server->getString('REMOTE_ADDR', '')
		);

		if (!empty($result['allowed'])) {
			return ['allowed' => true, 'message' => ''];
		}

		$message = trim((string) ($result['message'] ?? ''));
		if ($message === '') {
			$message = 'Подтвердите, что вы не робот';
		}

		return ['allowed' => false, 'message' => $message];
	}

	private function isBlockedByUnverified(string $login): bool
	{
		$serviceClass = $this->getVerificationServiceClass();
		if ($serviceClass === null) {
			return false;
		}
		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			return (bool) $serviceClass::isBlockedByUnverified($db, $login);
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function getVerificationServiceClass(): ?string
	{
		$class = '\\Joomla\\Plugin\\System\\Emailverification\\Service\\EmailVerificationService';
		if (class_exists($class)) {
			return $class;
		}
		$servicePath = JPATH_SITE . '/plugins/system/emailverification/src/Service/EmailVerificationService.php';
		if (!is_file($servicePath)) {
			return null;
		}
		require_once $servicePath;
		return class_exists($class) ? $class : null;
	}

	private function getRecaptchaVerifierClass(): ?string
	{
		$class = '\\Joomla\\Plugin\\System\\Viglingrecaptcha\\Service\\RecaptchaVerifier';
		if (class_exists($class)) {
			return $class;
		}

		$servicePath = JPATH_SITE . '/plugins/system/viglingrecaptcha/src/Service/RecaptchaVerifier.php';
		if (!is_file($servicePath)) {
			return null;
		}

		require_once $servicePath;

		return class_exists($class) ? $class : null;
	}

	private function resolveReturnUrl($return): string
	{
		$return = is_string($return) ? trim($return) : '';
		$return = str_replace('&amp;', '&', $return);
		if ($return === '') {
			return 'index.php?option=com_users&view=profile';
		}
		$startsSlash = (strpos($return, '/') === 0);
		$startsIndex = (strpos($return, 'index.php') === 0);
		if ($startsSlash || $startsIndex) {
			return $return;
		}
		if (strpos($return, 'http') === 0 && !Uri::isInternal($return)) {
			return 'index.php?option=com_users&view=profile';
		}
		return $return;
	}

	private function translateMessage(string $msg): string
	{
		$t = Text::_($msg);
		return $t !== $msg ? $t : $msg;
	}

	private function buildRedirectUrl(string $return): string
	{
		if (strpos($return, 'http') === 0) {
			return $return;
		}
		if (strpos($return, 'index.php') === 0) {
			return \Joomla\CMS\Router\Route::_($return, false);
		}
		return rtrim(Uri::root(), '/') . '/' . ltrim($return, '/');
	}

	private function normalizeForMailTemplate(array $data): array
	{
		foreach ($data as $key => $value) {
			$data[$key] = $this->normalizeValue($value);
		}

		return $data;
	}

	private function normalizeValue($value)
	{
		if ($value === null || is_scalar($value)) {
			return $value;
		}

		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->normalizeValue($item);
			}

			return json_encode($value, JSON_UNESCAPED_UNICODE);
		}

		if (is_object($value)) {
			$encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
			return $encoded !== false ? $encoded : get_class($value);
		}

		return (string) $value;
	}

	private function toggleFavorite(AjaxEvent $event, $app, $input): void
	{
		$user = $app->getIdentity();
		if (!$user || (int) $user->id <= 0) {
			$event->updateEventResult(['success' => false, 'message' => 'Нужна авторизация']);
			return;
		}

		$masterId = (int) $input->post->getInt('master_id', $input->getInt('master_id', 0));
		if ($masterId <= 0) {
			$event->updateEventResult(['success' => false, 'message' => 'Не указан мастер']);
			return;
		}
		if ($masterId === (int) $user->id) {
			$event->updateEventResult(['success' => false, 'message' => 'Нельзя добавить себя в избранное']);
			return;
		}

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$table = $db->replacePrefix('#__vigling_user_favorites');
			$this->ensureFavoritesTable($db, $table);

			$query = $db->getQuery(true)
				->select('1')
				->from($db->quoteName('#__vigling_user_favorites'))
				->where($db->quoteName('user_id') . ' = ' . (int) $user->id)
				->where($db->quoteName('master_id') . ' = ' . $masterId);
			$db->setQuery($query);
			$exists = (bool) $db->loadResult();

			if ($exists) {
				$delete = $db->getQuery(true)
					->delete($db->quoteName('#__vigling_user_favorites'))
					->where($db->quoteName('user_id') . ' = ' . (int) $user->id)
					->where($db->quoteName('master_id') . ' = ' . $masterId);
				$db->setQuery($delete)->execute();
				$event->updateEventResult(['success' => true, 'active' => false, 'message' => 'Удалено из избранного']);
				return;
			}

			$insert = $db->getQuery(true)
				->insert($db->quoteName('#__vigling_user_favorites'))
				->columns([$db->quoteName('user_id'), $db->quoteName('master_id'), $db->quoteName('created_at')])
				->values((int) $user->id . ', ' . $masterId . ', ' . $db->quote((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')));
			$db->setQuery($insert)->execute();
			$event->updateEventResult(['success' => true, 'active' => true, 'message' => 'Добавлено в избранное']);
		} catch (\Throwable $e) {
			$event->updateEventResult(['success' => false, 'message' => 'Ошибка сохранения']);
		}
	}

	private function ensureFavoritesTable(DatabaseInterface $db, string $tableName): void
	{
		$db->setQuery('SHOW TABLES LIKE ' . $db->quote($tableName));
		if ($db->loadResult()) {
			return;
		}

		$db->setQuery(
			'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__vigling_user_favorites') . " (
				`id` int unsigned NOT NULL AUTO_INCREMENT,
				`user_id` int NOT NULL,
				`master_id` int NOT NULL,
				`created_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uniq_user_master` (`user_id`,`master_id`),
				KEY `idx_master_id` (`master_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		)->execute();
	}
}

<?php
/**
 * PHP environment page. Public visitors get 403.
 * Only a Joomla Super User signed into /administrator/ can view phpinfo().
 */

define('_JEXEC', 1);

$allowed = false;

try {
	if (is_file(__DIR__ . '/defines.php')) {
		include_once __DIR__ . '/defines.php';
	}

	if (!defined('_JDEFINES')) {
		define('JPATH_BASE', __DIR__);
		require_once JPATH_BASE . '/includes/defines.php';
	}

	require_once JPATH_BASE . '/includes/framework.php';

	$container = \Joomla\CMS\Factory::getContainer();
	$container->alias('session.web', 'session.web.administrator')
		->alias('session', 'session.web.administrator')
		->alias('JSession', 'session.web.administrator')
		->alias(\Joomla\CMS\Session\Session::class, 'session.web.administrator')
		->alias(\Joomla\Session\Session::class, 'session.web.administrator')
		->alias(\Joomla\Session\SessionInterface::class, 'session.web.administrator');

	$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
	\Joomla\CMS\Factory::$application = $app;

	$session = $app->getSession();

	if (method_exists($session, 'isStarted') && !$session->isStarted()) {
		$session->start();
	}

	$user = $app->getIdentity();

	if ((!$user || $user->guest) && $session->has('user')) {
		$app->loadIdentity($session->get('user'));
		$user = $app->getIdentity();
	}

	$allowed = $user && !$user->guest && $user->authorise('core.admin');
} catch (\Throwable $exception) {
	$allowed = false;
}

if (!$allowed) {
	header('HTTP/1.1 403 Forbidden');
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Robots-Tag: noindex, nofollow');
	echo 'Forbidden';
	exit;
}

phpinfo();

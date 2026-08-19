<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

$app = Factory::getApplication();
$cookieLogin = isset($this->user->cookieLogin) ? $this->user->cookieLogin : null;

if (empty($cookieLogin) && empty($this->user->guest)) {
	$menu = $app->getMenu();
	$profileItem = null;
	foreach ($menu->getMenu() as $it) {
		if (isset($it->query['option'], $it->query['view']) && $it->query['option'] === 'com_users' && $it->query['view'] === 'profile') {
			$profileItem = $it;
			break;
		}
	}
	if ($profileItem) {
		$app->redirect(Route::_('index.php?Itemid=' . (int) $profileItem->id));
		return;
	}
	$app->redirect(Route::_('index.php?option=com_users&view=profile'));
	return;
}

if (!empty($cookieLogin) || !empty($this->user->guest)) {
	echo $this->loadTemplate('login');
} else {
	echo $this->loadTemplate('logout');
}

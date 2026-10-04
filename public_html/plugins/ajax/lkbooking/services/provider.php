<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Viglin\Plugin\Ajax\Lkbooking\Extension\Lkbooking;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				return new Lkbooking(
					(array) PluginHelper::getPlugin('ajax', 'lkbooking'),
					Factory::getApplication()
				);
			}
		);
	}
};

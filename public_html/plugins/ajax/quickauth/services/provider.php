<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Viglin\Plugin\Ajax\Quickauth\Extension\Quickauth;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				return new Quickauth(
					(array) PluginHelper::getPlugin('ajax', 'quickauth'),
					Factory::getApplication()
				);
			}
		);
	}
};

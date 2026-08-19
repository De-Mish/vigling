<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

require_once __DIR__ . '/../src/Extension/Profileuserid.php';

use Joomla\Plugin\System\Profileuserid\Extension\Profileuserid;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$plugin = new Profileuserid(
					(array) PluginHelper::getPlugin('system', 'profileuserid')
				);
				$plugin->setApplication(Factory::getApplication());
				return $plugin;
			}
		);
	}
};

<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

require_once __DIR__ . '/../src/Extension/Regautologin.php';

use Joomla\Plugin\System\Regautologin\Extension\Regautologin;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$plugin = new Regautologin(
					(array) PluginHelper::getPlugin('system', 'regautologin')
				);
				$plugin->setApplication(Factory::getApplication());
				return $plugin;
			}
		);
	}
};

<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Viglin\Component\Pushnotify\Administrator\Extension\PushnotifyComponent;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->registerServiceProvider(new MVCFactory('\\Viglin\\Component\\Pushnotify'));
		$container->registerServiceProvider(new ComponentDispatcherFactory('\\Viglin\\Component\\Pushnotify'));
		$container->registerServiceProvider(new RouterFactory('\\Viglin\\Component\\Pushnotify'));

		$container->set(
			ComponentInterface::class,
			function (Container $container) {
				$component = new PushnotifyComponent($container->get(ComponentDispatcherFactoryInterface::class));
				$component->setRegistry($container->get(Registry::class));
				$component->setMVCFactory($container->get(MVCFactoryInterface::class));
				$component->setRouterFactory($container->get(RouterFactoryInterface::class));
				return $component;
			}
		);
	}
};

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
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Viglin\Component\Modeli\Administrator\Extension\ModeliComponent;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->registerServiceProvider(new MVCFactory('\\Viglin\\Component\\Modeli'));
		$container->registerServiceProvider(new ComponentDispatcherFactory('\\Viglin\\Component\\Modeli'));
		$container->registerServiceProvider(new RouterFactory('\\Viglin\\Component\\Modeli'));

		$container->set(
			ComponentInterface::class,
			function (Container $container) {
				$component = new ModeliComponent($container->get(ComponentDispatcherFactoryInterface::class));
				$component->setRegistry($container->get(Registry::class));
				$component->setMVCFactory($container->get(MVCFactoryInterface::class));
				$component->setRouterFactory($container->get(RouterFactoryInterface::class));

				return $component;
			}
		);
	}
};

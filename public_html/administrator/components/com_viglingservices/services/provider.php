<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Viglin\Component\Viglingservices\Administrator\Extension\ViglingservicesComponent;

return new class () implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->registerServiceProvider(new MVCFactory('\\Viglin\\Component\\Viglingservices'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Viglin\\Component\\Viglingservices'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new ViglingservicesComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                $component->setRegistry($container->get(Registry::class));
                return $component;
            }
        );
    }
};

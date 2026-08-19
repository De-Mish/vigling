<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\System\Viglingrecaptcha\Extension\Viglingrecaptcha;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $pluginClassFile = __DIR__ . '/../src/Extension/Viglingrecaptcha.php';
        if (!class_exists(Viglingrecaptcha::class) && is_file($pluginClassFile)) {
            require_once $pluginClassFile;
        }

        $container->set(
            PluginInterface::class,
            function (Container $container) {
                return new Viglingrecaptcha(
                    (array) PluginHelper::getPlugin('system', 'viglingrecaptcha'),
                    Factory::getApplication()
                );
            }
        );
    }
};

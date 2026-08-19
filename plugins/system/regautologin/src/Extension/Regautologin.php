<?php

namespace Joomla\Plugin\System\Regautologin\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

final class Regautologin extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterInitialise' => ['registerRegistrationControllerOverride', 1],
		];
	}

	public function registerRegistrationControllerOverride(): void
	{
		$app = $this->getApplication();
		if ($app->isClient('administrator')) {
			return;
		}
		$targetClass = 'Joomla\\Component\\Users\\Site\\Controller\\RegistrationController';
		if (class_exists($targetClass, false)) {
			return;
		}
		spl_autoload_register(function ($class) use ($targetClass) {
			if ($class !== $targetClass) {
				return false;
			}
			require_once __DIR__ . '/../Controller/RegistrationController.php';
			return true;
		}, true, true);
	}
}

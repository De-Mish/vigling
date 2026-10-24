<?php

namespace Viglin\Component\Pushnotify\Administrator\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;

class PushnotifyComponent extends MVCComponent implements RouterServiceInterface
{
	use HTMLRegistryAwareTrait;
	use RouterServiceTrait;
}

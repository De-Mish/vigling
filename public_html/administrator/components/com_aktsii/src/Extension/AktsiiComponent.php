<?php

namespace Viglin\Component\Aktsii\Administrator\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;

class AktsiiComponent extends MVCComponent implements RouterServiceInterface
{
	use HTMLRegistryAwareTrait;
	use RouterServiceTrait;
}

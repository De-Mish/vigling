<?php

namespace Joomla\Module\Specialists\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\Module\Specialists\Site\Helper\SpecialistsHelper;

\defined('_JEXEC') or die;

class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();
        $params = $data['params'];

        $data['listUrl'] = $params->get('list_url', '/specialists-list.php');
        if (strpos($data['listUrl'], 'http') !== 0) {
            $data['listUrl'] = \Joomla\CMS\Uri\Uri::root(false) . ltrim($data['listUrl'], '/');
        }
        $data['showCategories'] = (bool) $params->get('show_categories', 1);
        $data['showHomeFilter'] = (bool) $params->get('show_home_filter', 1);
        $data['categories'] = $data['showCategories'] ? SpecialistsHelper::getCategories() : [];

        return $data;
    }
}

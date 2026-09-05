<?php

namespace Viglin\Component\Poisk\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Viglin\Component\Poisk\Site\Helper\ListMapHelper;
use Viglin\Component\Poisk\Site\Helper\PoiskHelper;

class MapController extends BaseController
{
	public function pins()
	{
		try {
			$app = Factory::getApplication();
			$model = $app->bootComponent('com_poisk')->getMVCFactory()->createModel('List', 'Site');
			$model->populateState();
			$path = trim((string) \Joomla\CMS\Uri\Uri::getInstance()->getPath(), '/');
			$branch = (string) $app->getInput()->getCmd('branch', '');
			if ($branch === 'zatochka-remont'
				|| $path === 'zatochka-remont'
				|| str_starts_with($path, 'zatochka-remont/')) {
				$model->setState('category_path_prefix', 'zatochka-remont');
				$model->setState('branch_scope', 'zatochka-remont');
			}
			$items = $model->getMapItems();
			$userIds = array_values(array_unique(array_map(static function ($item) {
				return (int) ($item->id ?? 0);
			}, $items)));
			$fieldsByUser = $userIds !== []
				? PoiskHelper::getFieldsForUserIds($userIds, ['sity', 'area', 'street', 'house_number'])
				: [];

			$pins = [];
			foreach ($items as $item) {
				$userId = (int) ($item->id ?? 0);
				if ($userId <= 0) {
					continue;
				}
				$fields = $fieldsByUser[$userId] ?? $fieldsByUser[(string) $userId] ?? [];
				$pins[] = ListMapHelper::pinFromFields(
					$userId,
					(string) ($item->name ?? ''),
					$fields,
					'/' . $userId
				);
			}

			ListMapHelper::jsonResponse(['ok' => true, 'pins' => $pins, 'total' => count($pins)]);
		} catch (\Throwable $e) {
			ListMapHelper::jsonResponse(['ok' => false, 'pins' => [], 'error' => 'map_failed']);
		}
	}
}

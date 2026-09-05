<?php

namespace Viglin\Component\Kurs\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Viglin\Component\Kurs\Site\Helper\KursHelper;
use Viglin\Component\Poisk\Site\Helper\ListMapHelper;

class MapController extends BaseController
{
	public function pins()
	{
		try {
			$app = Factory::getApplication();
			$model = $app->bootComponent('com_kurs')->getMVCFactory()->createModel('List', 'Site');
			$model->populateState();
			$items = $model->getMapItems();
			$userIds = array_values(array_unique(array_map(static function ($item) {
				return (int) ($item->master_id ?? 0);
			}, $items)));
			$fieldsByUser = $userIds !== []
				? KursHelper::getFieldsForUserIds($userIds, ['sity', 'area', 'street', 'house_number'])
				: [];

			$pins = [];
			$seen = [];
			foreach ($items as $item) {
				$userId = (int) ($item->master_id ?? 0);
				if ($userId <= 0 || isset($seen[$userId])) {
					continue;
				}
				$seen[$userId] = true;
				$fields = $fieldsByUser[$userId] ?? $fieldsByUser[(string) $userId] ?? [];
				$title = trim((string) ($item->title ?? $item->description ?? 'Курс'));
				$masterName = trim((string) ($item->master_name ?? ''));
				$pins[] = ListMapHelper::pinFromFields(
					$userId,
					$title !== '' ? $title : $masterName,
					$fields,
					'/' . $userId,
					$masterName !== '' ? 'Мастер: ' . $masterName : ''
				);
			}

			ListMapHelper::jsonResponse(['ok' => true, 'pins' => $pins, 'total' => count($pins)]);
		} catch (\Throwable $e) {
			ListMapHelper::jsonResponse(['ok' => false, 'pins' => [], 'error' => 'map_failed']);
		}
	}
}

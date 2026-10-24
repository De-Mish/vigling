<?php

namespace Viglin\Component\Orders\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper;

/**
 * Looks up the current price list entry of a master for a previously booked service.
 */
final class CatalogPriceHelper
{
	/**
	 * @return array{has_catalog:bool,item:?array{name:string,price:int,duration:int,svc_id:int,tag_id:int,pause_min:int,category:string}}
	 */
	public static function findCurrentService(int $masterId, int $svcId, int $tagId, string $serviceName): array
	{
		$groups = [];
		if ($masterId > 0 && class_exists(JsnDecodeHelper::class)) {
			$loaded = JsnDecodeHelper::getUserServicesStructuredWithIds($masterId);
			$groups = is_array($loaded) ? $loaded : [];
		}
		if ($groups === []) {
			return ['has_catalog' => false, 'item' => null];
		}

		$bySvc = [];
		$byName = [];
		$needle = mb_strtolower(trim($serviceName));
		foreach ($groups as $group) {
			if (!is_array($group) || empty($group['items']) || !is_array($group['items'])) {
				continue;
			}
			foreach ($group['items'] as $item) {
				if (!is_array($item)) {
					continue;
				}
				$item['_category'] = trim((string) ($group['title'] ?? ''));
				if ($svcId > 0 && trim((string) ($item['svc_id'] ?? '')) === (string) $svcId) {
					$bySvc[] = $item;
				}
				if ($needle !== '' && mb_strtolower(trim((string) ($item['name'] ?? ''))) === $needle) {
					$byName[] = $item;
				}
			}
		}

		$pool = $bySvc !== [] ? $bySvc : $byName;
		if ($pool === []) {
			return ['has_catalog' => true, 'item' => null];
		}
		$picked = $pool[0];
		if ($tagId > 0) {
			foreach ($pool as $item) {
				if ((int) ($item['tag_id'] ?? 0) === $tagId) {
					$picked = $item;
					break;
				}
			}
		}
		$duration = (int) ($picked['duration'] ?? 0);

		return [
			'has_catalog' => true,
			'item' => [
				'name' => trim((string) ($picked['name'] ?? '')),
				'price' => max(0, (int) ($picked['price'] ?? 0)),
				'duration' => max(15, min(480, $duration > 0 ? $duration : 60)),
				'svc_id' => (int) ($picked['svc_id'] ?? 0),
				'tag_id' => (int) ($picked['tag_id'] ?? 0),
				'pause_min' => max(0, (int) ($picked['pause_min'] ?? 0)),
				'category' => trim((string) ($picked['_category'] ?? '')),
			],
		];
	}
}

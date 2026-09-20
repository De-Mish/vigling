<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

if (!function_exists('ryba_is_orders_menu_item')) {
	function ryba_is_orders_menu_item(object $item, $menu = null, int $depth = 0): bool
	{
		if ($depth > 3) {
			return false;
		}
		$type = (string) ($item->type ?? '');
		if ($type === 'alias' && $menu) {
			$targetId = 0;
			try {
				$targetId = (int) $item->getParams()->get('aliasoptions', 0);
			} catch (\Throwable $e) {
				$targetId = 0;
			}
			if ($targetId > 0) {
				$target = $menu->getItem($targetId);
				if ($target && $target !== $item) {
					return ryba_is_orders_menu_item($target, $menu, $depth + 1);
				}
			}
		}
		$query = is_array($item->query ?? null) ? $item->query : [];
		if (($query['option'] ?? '') === 'com_orders') {
			return true;
		}
		$link = (string) ($item->link ?? '');
		if ($link !== '' && (stripos($link, 'option=com_orders') !== false || preg_match('#(^|/)component/orders(/|\?|$)#i', $link))) {
			return true;
		}

		return false;
	}
}

if (!function_exists('ryba_rebuild_menu_tree')) {
	function ryba_rebuild_menu_tree(array $items, int $startLevel = 1): array
	{
		$items = array_values($items);
		$count = count($items);
		for ($i = 0; $i < $count; $i++) {
			$item = $items[$i];
			$next = $items[$i + 1] ?? null;
			$item->parent = false;
			foreach ($items as $other) {
				if ((int) ($other->parent_id ?? 0) === (int) ($item->id ?? 0)) {
					$item->parent = true;
					break;
				}
			}
			if ($next) {
				$item->deeper = ((int) $next->level > (int) $item->level);
				$item->shallower = ((int) $next->level < (int) $item->level);
				$item->level_diff = ((int) $item->level - (int) $next->level);
			} else {
				$item->deeper = ($startLevel > (int) $item->level);
				$item->shallower = ($startLevel < (int) $item->level);
				$item->level_diff = ((int) $item->level - $startLevel);
			}
		}

		return $items;
	}
}

if (!function_exists('ryba_filter_appointment_menu_items')) {
	function ryba_filter_appointment_menu_items(array $list, int $startLevel = 1): array
	{
		if ($list === []) {
			return [];
		}
		$menu = null;
		try {
			$menu = Factory::getApplication()->getMenu();
		} catch (\Throwable $e) {
			$menu = null;
		}
		$hideIds = [];
		foreach ($list as $item) {
			if (!is_object($item)) {
				continue;
			}
			if (ryba_is_orders_menu_item($item, $menu)) {
				$hideIds[(int) ($item->id ?? 0)] = true;
			}
		}
		if ($hideIds === []) {
			return array_values($list);
		}
		$changed = true;
		while ($changed) {
			$changed = false;
			foreach ($list as $item) {
				if (!is_object($item)) {
					continue;
				}
				$id = (int) ($item->id ?? 0);
				if ($id <= 0 || isset($hideIds[$id])) {
					continue;
				}
				$parentId = (int) ($item->parent_id ?? 0);
				if ($parentId > 0 && isset($hideIds[$parentId])) {
					$hideIds[$id] = true;
					$changed = true;
				}
			}
		}
		$childrenByParent = [];
		foreach ($list as $item) {
			if (!is_object($item)) {
				continue;
			}
			$parentId = (int) ($item->parent_id ?? 0);
			$childrenByParent[$parentId][] = (int) ($item->id ?? 0);
		}
		foreach ($list as $item) {
			if (!is_object($item)) {
				continue;
			}
			$type = (string) ($item->type ?? '');
			if ($type !== 'heading' && $type !== 'separator') {
				continue;
			}
			$id = (int) ($item->id ?? 0);
			if ($id <= 0 || isset($hideIds[$id])) {
				continue;
			}
			$kids = $childrenByParent[$id] ?? [];
			if ($kids === []) {
				continue;
			}
			$allHidden = true;
			foreach ($kids as $kid) {
				if ($kid > 0 && !isset($hideIds[$kid])) {
					$allHidden = false;
					break;
				}
			}
			if ($allHidden) {
				$hideIds[$id] = true;
			}
		}
		$filtered = [];
		foreach ($list as $item) {
			if (!is_object($item)) {
				continue;
			}
			$id = (int) ($item->id ?? 0);
			if ($id > 0 && isset($hideIds[$id])) {
				continue;
			}
			$filtered[] = $item;
		}

		return ryba_rebuild_menu_tree($filtered, $startLevel);
	}
}

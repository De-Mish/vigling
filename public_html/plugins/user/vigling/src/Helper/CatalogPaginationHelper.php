<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Pagination\Pagination;

/**
 * Page links otherwise keep only the offset, so the next page opens with an empty filter.
 */
final class CatalogPaginationHelper
{
	public static function links(Pagination $pagination): string
	{
		$html = $pagination->getPagesLinks();
		if ($html === '') {
			return '';
		}

		$filters = self::currentFilters();

		$rewritten = preg_replace_callback(
			'/href="([^"]*)"/',
			static function (array $match) use ($filters): string {
				$href = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
				$parts = parse_url($href);
				$query = [];
				if (is_array($parts) && isset($parts['query'])) {
					parse_str((string) $parts['query'], $query);
				}
				$merged = $filters;
				$start = $query['start'] ?? $query['limitstart'] ?? null;
				if ($start !== null && $start !== '' && (int) $start > 0) {
					$merged['start'] = (int) $start;
				}
				$path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
				if ($path === '') {
					$path = '/';
				}
				$url = $path . ($merged !== [] ? '?' . http_build_query($merged) : '');

				return 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
			},
			$html
		);

		return is_string($rewritten) ? $rewritten : $html;
	}

	/**
	 * Filters that were actually on this request. Empty values stay off the link,
	 * which is the same as an unset filter.
	 *
	 * @return array<string, mixed>
	 */
	private static function currentFilters(): array
	{
		$input = Factory::getApplication()->getInput();
		$filters = [];

		foreach (['cat_id' => 'filter_cat_id', 'service' => 'filter_service', 'tag' => 'filter_tag'] as $name => $alias) {
			$value = self::uintParam($input, $name, $alias);
			if ($value > 0) {
				$filters[$name] = $value;
			}
		}

		foreach ([
			'city' => 'filter_city',
			'area' => 'filter_area',
			'master_name' => 'filter_master_name',
			'booking_mode' => '',
			'avail_day' => '',
			'avail_time' => '',
			'avail_date' => '',
			'filter_order' => '',
			'filter_order_Dir' => '',
		] as $name => $alias) {
			$value = self::stringParam($input, $name, $alias);
			if ($value !== '') {
				$filters[$name] = $value;
			}
		}

		$home = self::intList($input, 'home', 'filter_home');
		if ($home !== []) {
			$filters['home'] = $home;
		}
		$payment = self::paymentList($input);
		if ($payment !== []) {
			$filters['payment'] = $payment;
		}
		if (self::childrenOn($input)) {
			$filters['children'] = '1';
		}
		if ($input->exists('limit')) {
			$limit = $input->getUint('limit', 0);
			if ($limit >= 1) {
				$filters['limit'] = $limit;
			}
		}

		return $filters;
	}

	private static function uintParam($input, string $name, string $alias): int
	{
		if ($input->exists($name)) {
			return $input->getUint($name, 0);
		}
		if ($alias !== '' && $input->exists($alias)) {
			return $input->getUint($alias, 0);
		}

		return 0;
	}

	private static function stringParam($input, string $name, string $alias): string
	{
		if ($input->exists($name)) {
			return trim((string) $input->getString($name, ''));
		}
		if ($alias !== '' && $input->exists($alias)) {
			return trim((string) $input->getString($alias, ''));
		}

		return '';
	}

	/**
	 * @return list<int>
	 */
	private static function intList($input, string $name, string $alias): array
	{
		$raw = $input->exists($name)
			? $input->get($name, [], 'array')
			: ($alias !== '' && $input->exists($alias) ? $input->get($alias, [], 'array') : []);
		$list = [];
		foreach ((array) $raw as $value) {
			$number = (int) $value;
			if ($number > 0) {
				$list[] = $number;
			}
		}

		return array_values(array_unique($list));
	}

	/**
	 * @return list<string>
	 */
	private static function paymentList($input): array
	{
		$raw = $input->exists('payment')
			? $input->get('payment', [], 'array')
			: ($input->exists('filter_payment') ? $input->get('filter_payment', [], 'array') : []);
		$list = [];
		foreach ((array) $raw as $value) {
			$key = strtolower(trim((string) $value));
			if (in_array($key, ['card', 'cash', 'transfer'], true)) {
				$list[] = $key;
			}
		}

		return array_values(array_unique($list));
	}

	private static function childrenOn($input): bool
	{
		$name = $input->exists('children') ? 'children' : ($input->exists('filter_children') ? 'filter_children' : '');
		if ($name === '') {
			return false;
		}
		$raw = strtolower(trim((string) $input->getString($name, '')));

		return in_array($raw, ['1', 'yes', 'on', 'true', 'да'], true);
	}
}

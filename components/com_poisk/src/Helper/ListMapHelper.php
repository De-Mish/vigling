<?php

namespace Viglin\Component\Poisk\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class ListMapHelper
{
	public static function normalizeAddressPart(string $value): string
	{
		$value = trim((string) preg_replace('/\s+/u', ' ', $value));

		return trim($value, ' ,');
	}

	public static function jsonResponse(array $payload): void
	{
		$app = Factory::getApplication();
		$app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
		$app->setHeader('Cache-Control', 'no-store', true);
		echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$app->close();
	}

	public static function viewerCity(): string
	{
		$user = Factory::getApplication()->getIdentity();
		$userId = $user ? (int) $user->id : 0;
		if ($userId <= 0) {
			return '';
		}

		$fields = PoiskHelper::getFieldsForUserIds([$userId], ['sity', 'city']);
		$city = trim((string) (
			$fields[$userId]['sity']
			?? $fields[(string) $userId]['sity']
			?? $fields[$userId]['city']
			?? $fields[(string) $userId]['city']
			?? ''
		));
		if ($city !== '') {
			return $city;
		}

		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName('profile_value'))
				->from($db->quoteName('#__user_profiles'))
				->where($db->quoteName('user_id') . ' = ' . $userId)
				->where($db->quoteName('profile_key') . ' IN ('
					. $db->quote('profile.city') . ', '
					. $db->quote('profile.sity')
					. ')');
			$db->setQuery($query);
			$value = trim((string) $db->loadResult());
		} catch (\Throwable $e) {
			return '';
		}

		if ($value !== '' && ($value[0] === '"' || $value[0] === '[' || $value[0] === '{')) {
			$decoded = json_decode($value, true);
			if (is_string($decoded)) {
				$value = trim($decoded);
			}
		}

		return $value;
	}

	public static function applyViewerCity(string &$city, bool &$locked, array &$query): void
	{
		if ($locked && trim((string) ($query['city'] ?? '')) !== '') {
			return;
		}

		$profileCity = self::viewerCity();
		if ($profileCity === '') {
			// Clients/guests without a city keep a general map: all pins, not a forced Moscow filter.
			return;
		}

		$city = $profileCity;
		$locked = true;
		$query['city'] = $profileCity;
	}

	public static function pinFromFields(
		int $userId,
		string $name,
		array $fields,
		string $profileHref,
		string $servicesText = '',
		array $extra = []
	): array {
		$sity = trim((string) ($fields['sity'] ?? ''));
		$area = trim((string) ($fields['area'] ?? ''));
		$street = trim((string) ($fields['street'] ?? ''));
		$house = trim((string) ($fields['house_number'] ?? ''));
		$parts = array_values(array_filter(array_map([self::class, 'normalizeAddressPart'], [$sity, $area, $street, $house])));
		$addr = implode(', ', $parts);
		$localParts = array_values(array_filter(array_map([self::class, 'normalizeAddressPart'], [$area, $street, $house])));
		$addrLocal = implode(', ', $localParts);
		$queryParts = array_values(array_unique(array_filter($parts)));
		$query = count($queryParts) >= 2 ? implode(', ', $queryParts) : ($sity !== '' ? $sity : '');

		return array_merge([
			'id' => $userId,
			'name' => $name,
			'city' => $sity,
			'area' => $area,
			'query' => $query,
			'href' => $profileHref,
			'line' => $servicesText,
			'addr' => $addr !== '' ? $addr : ($sity !== '' ? $sity : 'Адрес не указан'),
			'addr_local' => $addrLocal !== '' ? $addrLocal : 'Адрес не указан',
		], $extra);
	}
}

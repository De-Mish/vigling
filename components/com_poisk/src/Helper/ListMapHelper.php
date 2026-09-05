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

	public static function decodeCityValue($value): string
	{
		if (!is_scalar($value)) {
			return '';
		}

		$current = self::normalizeAddressPart((string) $value);
		if ($current === '') {
			return '';
		}

		for ($i = 0; $i < 3; $i++) {
			$first = $current[0] ?? '';
			if ($first !== '"' && $first !== '[' && $first !== '{') {
				break;
			}
			$decoded = json_decode($current, true);
			if (is_string($decoded)) {
				$current = self::normalizeAddressPart($decoded);
				continue;
			}
			if (is_array($decoded)) {
				$firstValue = reset($decoded);
				$current = is_scalar($firstValue) ? self::normalizeAddressPart((string) $firstValue) : '';
				continue;
			}
			break;
		}

		return $current;
	}

	public static function cityForUser(int $userId): string
	{
		if ($userId <= 0) {
			return '';
		}

		$fromProfile = '';
		try {
			$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select([$db->quoteName('profile_key'), $db->quoteName('profile_value')])
				->from($db->quoteName('#__user_profiles'))
				->where($db->quoteName('user_id') . ' = ' . $userId)
				->where($db->quoteName('profile_key') . ' IN ('
					. $db->quote('profile.city') . ', '
					. $db->quote('profile.sity')
					. ')');
			$db->setQuery($query);
			$rows = $db->loadAssocList() ?: [];
			$byKey = [];
			foreach ($rows as $row) {
				$byKey[(string) ($row['profile_key'] ?? '')] = self::decodeCityValue($row['profile_value'] ?? '');
			}
			$fromProfile = $byKey['profile.city'] ?? '';
			if ($fromProfile === '') {
				$fromProfile = $byKey['profile.sity'] ?? '';
			}
		} catch (\Throwable $e) {
			$fromProfile = '';
		}

		if ($fromProfile !== '') {
			return $fromProfile;
		}

		$fields = PoiskHelper::getFieldsForUserIds([$userId], ['sity', 'city']);
		$userFields = $fields[$userId] ?? $fields[(string) $userId] ?? [];

		return self::decodeCityValue($userFields['sity'] ?? '')
			?: self::decodeCityValue($userFields['city'] ?? '');
	}

	public static function viewerCity(): string
	{
		$user = Factory::getApplication()->getIdentity();
		$userId = $user ? (int) $user->id : 0;

		return self::cityForUser($userId);
	}

	public static function applyViewerCity(string &$city, bool &$locked, array &$query): void
	{
		$requested = self::decodeCityValue($query['city'] ?? '');
		if ($requested !== '') {
			$city = $requested;
			$locked = true;
			$query['city'] = $requested;
			return;
		}

		// Profile city only sets the viewport. Do not inject it into the pins query:
		// markers must stay at each specialist's own city, matching the unfiltered list.
		$profileCity = self::viewerCity();
		$city = $profileCity !== '' ? $profileCity : 'Москва';
		$locked = true;
		$query['city'] = '';
	}

	public static function pinFromFields(
		int $userId,
		string $name,
		array $fields,
		string $profileHref,
		string $servicesText = '',
		array $extra = []
	): array {
		$sity = self::decodeCityValue($fields['sity'] ?? '');
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

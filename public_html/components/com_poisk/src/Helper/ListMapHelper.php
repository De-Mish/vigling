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

	/**
	 * Fields the public map needs. Entrance, floor and apartment stay off the pin.
	 *
	 * @return list<string>
	 */
	public static function pinFieldNames(): array
	{
		return ['sity', 'area', 'street', 'house_number'];
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
		$area = self::decodeCityValue($fields['area'] ?? '');
		$street = self::decodeCityValue($fields['street'] ?? '');
		$house = self::decodeCityValue($fields['house_number'] ?? '');
		// Most precise string first. The map geocodes that point; the clusterer
		// then groups nearby houses into a street and nearby streets into a district
		// as the zoom changes. A district-only query put every pin on one point.
		$queries = self::geocodeQueries($sity, $area, $street, $house);
		$query = $queries[0] ?? '';
		$addr = implode(', ', array_values(array_filter([$sity, $area, $street, $house], static function (string $part): bool {
			return $part !== '';
		})));
		$addrLocal = implode(', ', array_values(array_filter([$area, $street, $house], static function (string $part): bool {
			return $part !== '';
		})));
		$href = $profileHref;
		if ($href !== '' && strpos($href, 'source=') === false) {
			$href .= (strpos($href, '?') === false ? '?' : '&') . 'source=map';
		}

		return array_merge([
			'id' => $userId,
			'name' => $name,
			'city' => $sity,
			'area' => $area,
			'street' => $street,
			'house' => $house,
			'query' => $query,
			'queries' => $queries,
			'href' => $href,
			'line' => $servicesText,
			'addr' => $addr !== '' ? $addr : ($sity !== '' ? $sity : 'Адрес не указан'),
			'addr_local' => $addrLocal !== '' ? $addrLocal : 'Адрес не указан',
		], $extra);
	}

	/**
	 * @return list<string>
	 */
	private static function geocodeQueries(string $city, string $area, string $street, string $house): array
	{
		$queries = [];
		$push = static function (array $parts) use (&$queries): void {
			$parts = array_values(array_unique(array_filter($parts, static function (string $part): bool {
				return $part !== '';
			})));
			if ($parts === []) {
				return;
			}
			$query = implode(', ', $parts);
			if (!in_array($query, $queries, true)) {
				$queries[] = $query;
			}
		};

		if ($street !== '' && $house !== '') {
			$push([$city, $street, $house]);
		}
		if ($street !== '') {
			$push([$city, $street]);
		}
		if ($area !== '') {
			$push([$city, $area]);
		}
		if ($city !== '') {
			$push([$city]);
		}

		return $queries;
	}
}
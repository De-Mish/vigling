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
		], $extra);
	}
}

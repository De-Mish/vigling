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

	public static function balloon(string $titleHtml, string $lineHtml, string $addrHtml): string
	{
		$iconUser = '<svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-1px;margin-right:4px"><path fill="currentColor" d="M12 12a5 5 0 1 0-5-5a5 5 0 0 0 5 5m0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5"/></svg>';
		$iconMap = '<svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-1px;margin-right:4px"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 4.89 7 13 7 13s7-8.11 7-13a7 7 0 0 0-7-7m0 9.5A2.5 2.5 0 1 1 14.5 9A2.5 2.5 0 0 1 12 11.5"/></svg>';

		return '<div class="map-balloon">'
			. '<a href="#">' . $iconUser . $titleHtml . '</a><br>'
			. ($lineHtml !== '' ? '<span>' . $lineHtml . '</span><br>' : '')
			. '<span>' . $iconMap . $addrHtml . '</span>'
			. '</div>';
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
		$nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
		$addrEsc = htmlspecialchars($addr !== '' ? $addr : ($sity !== '' ? $sity : 'Адрес не указан'), ENT_QUOTES, 'UTF-8');
		$servicesEsc = htmlspecialchars($servicesText, ENT_QUOTES, 'UTF-8');
		$balloon = '<div class="map-balloon">'
			. '<a href="' . htmlspecialchars($profileHref, ENT_QUOTES, 'UTF-8') . '">' . $nameEsc . '</a><br>'
			. ($servicesEsc !== '' ? '<span>' . $servicesEsc . '</span><br>' : '')
			. '<span>' . $addrEsc . '</span>'
			. '</div>';

		return array_merge([
			'id' => $userId,
			'name' => $name,
			'city' => $sity,
			'area' => $area,
			'query' => $query,
			'balloon' => $balloon,
		], $extra);
	}
}

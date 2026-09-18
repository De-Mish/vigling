<?php
/**
 * JSON-LD printers for homepage and public master profiles.
 * Hidden from visitors; used by search engines.
 */
defined('_JEXEC') || die;

use Joomla\CMS\Uri\Uri;

/**
 * @param array<string, mixed> $data
 */
function vigling_print_json_ld(array $data): void
{
	$json = json_encode(
		$data,
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
	);
	if (!is_string($json) || $json === '') {
		return;
	}
	echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
}

function vigling_print_website_json_ld(string $sitename): void
{
	$root = rtrim(Uri::root(), '/') . '/';
	$searchTarget = $root . 'poisk-spetsialistov?master_name={search_term_string}';
	vigling_print_json_ld([
		'@context' => 'https://schema.org',
		'@type' => 'WebSite',
		'name' => $sitename !== '' ? $sitename : 'VIGLING',
		'url' => $root,
		'inLanguage' => 'ru',
		'potentialAction' => [
			'@type' => 'SearchAction',
			'target' => [
				'@type' => 'EntryPoint',
				'urlTemplate' => $searchTarget,
			],
			'query-input' => 'required name=search_term_string',
		],
	]);
}

/**
 * @param array<int, array<string, mixed>> $servicesStructured
 */
function vigling_print_person_json_ld(
	string $name,
	string $profileUrl,
	string $imageUrl,
	string $city,
	string $area,
	string $street,
	string $about,
	array $servicesStructured
): void {
	if ($name === '' || $profileUrl === '') {
		return;
	}

	$person = [
		'@context' => 'https://schema.org',
		'@type' => 'Person',
		'name' => $name,
		'url' => $profileUrl,
	];
	if ($imageUrl !== '') {
		$person['image'] = $imageUrl;
	}
	if ($about !== '') {
		$person['description'] = mb_substr($about, 0, 300);
	}

	$address = array_filter([
		'@type' => 'PostalAddress',
		'addressLocality' => $city,
		'addressRegion' => $area,
		'streetAddress' => trim($street),
		'addressCountry' => 'RU',
	]);
	if (count($address) > 2) {
		$person['address'] = $address;
	}

	$offers = [];
	foreach ($servicesStructured as $cat) {
		$catTitle = trim((string) ($cat['title'] ?? ''));
		foreach ((array) ($cat['items'] ?? []) as $item) {
			$itemName = trim((string) ($item['name'] ?? ''));
			if ($itemName === '') {
				continue;
			}
			$offerName = $catTitle !== '' ? ($catTitle . ' — ' . $itemName) : $itemName;
			$offer = [
				'@type' => 'Offer',
				'name' => $offerName,
				'priceCurrency' => 'RUB',
			];
			$price = (int) ($item['price'] ?? 0);
			if ($price > 0) {
				$offer['price'] = (string) $price;
			}
			$offers[] = $offer;
			if (count($offers) >= 20) {
				break 2;
			}
		}
	}
	if ($offers !== []) {
		$person['makesOffer'] = $offers;
	}

	vigling_print_json_ld($person);
}

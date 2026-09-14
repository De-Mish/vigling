<?php

namespace Viglin\Template\Ryba\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;

/**
 * Caches public-profile map coordinates so Yandex is not queried on every view.
 */
final class ProfileMapGeocodeHelper
{
	public const API_KEY = '705d45a1-9138-4d99-afd4-dc261c612036';

	private const CACHE_TTL = 2592000;
	private const HTTP_TIMEOUT = 1;

	/** @var array<string, array{lat:float,lon:float,address:string}> */
	private static $memo = [];

	/**
	 * @param  array<int, string>  $addresses
	 * @return array{lat:float,lon:float,address:string}|null
	 */
	public static function resolveCenter(array $addresses, bool $allowFetch = true): ?array
	{
		$normalized = [];
		foreach ($addresses as $address) {
			$address = trim((string) $address);
			if ($address !== '') {
				$normalized[] = $address;
			}
		}
		$normalized = array_values(array_unique($normalized));
		if ($normalized === []) {
			return null;
		}

		foreach ($normalized as $address) {
			$cached = self::readCache($address);
			if ($cached !== null) {
				return $cached;
			}
		}

		if (!$allowFetch || !self::cacheDirWritable()) {
			return null;
		}

		foreach ($normalized as $address) {
			$fetched = self::fetchYandex($address);
			if ($fetched === null) {
				continue;
			}
			self::writeCache($address, $fetched);
			return $fetched;
		}

		return null;
	}

	/**
	 * @return array{lat:float,lon:float,address:string}|null
	 */
	private static function readCache(string $address): ?array
	{
		$key = self::cacheKey($address);
		if (isset(self::$memo[$key])) {
			return self::$memo[$key];
		}
		$path = self::cachePath($key);
		if (!is_file($path)) {
			return null;
		}
		if ((time() - (int) filemtime($path)) > self::CACHE_TTL) {
			return null;
		}
		$raw = @file_get_contents($path);
		if (!is_string($raw) || $raw === '') {
			return null;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['lat'], $data['lon'])) {
			return null;
		}
		$lat = (float) $data['lat'];
		$lon = (float) $data['lon'];
		if (!is_finite($lat) || !is_finite($lon)) {
			return null;
		}
		$resolved = [
			'lat' => $lat,
			'lon' => $lon,
			'address' => isset($data['address']) ? (string) $data['address'] : $address,
		];
		self::$memo[$key] = $resolved;
		return $resolved;
	}

	/**
	 * @param  array{lat:float,lon:float,address:string}  $coords
	 */
	private static function writeCache(string $address, array $coords): void
	{
		$dir = self::cacheDir();
		if ($dir === '' || !self::cacheDirWritable()) {
			return;
		}
		$key = self::cacheKey($address);
		self::$memo[$key] = $coords;
		@file_put_contents(
			self::cachePath($key),
			json_encode($coords, JSON_UNESCAPED_UNICODE),
			LOCK_EX
		);
	}

	/**
	 * @return array{lat:float,lon:float,address:string}|null
	 */
	private static function fetchYandex(string $address): ?array
	{
		$url = 'https://geocode-maps.yandex.ru/1.x/?' . http_build_query([
			'apikey' => self::API_KEY,
			'geocode' => $address,
			'format' => 'json',
			'results' => 1,
			'lang' => 'ru_RU',
		]);
		try {
			$http = HttpFactory::getHttp(['timeout' => self::HTTP_TIMEOUT]);
			$response = $http->get($url);
			$status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : (int) ($response->code ?? 0);
			if ($status !== 200) {
				return null;
			}
			$body = method_exists($response, 'getBody') ? (string) $response->getBody() : (string) ($response->body ?? '');
			$data = json_decode($body, true);
			$pos = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'] ?? '';
			if (!is_string($pos) || trim($pos) === '') {
				return null;
			}
			$parts = preg_split('/\s+/', trim($pos)) ?: [];
			if (count($parts) < 2) {
				return null;
			}
			$lon = (float) $parts[0];
			$lat = (float) $parts[1];
			if (!is_finite($lat) || !is_finite($lon)) {
				return null;
			}
			return [
				'lat' => $lat,
				'lon' => $lon,
				'address' => $address,
			];
		} catch (\Throwable $e) {
			return null;
		}
	}

	private static function cacheKey(string $address): string
	{
		return hash('sha256', mb_strtolower(trim($address)));
	}

	private static function cacheDir(): string
	{
		$root = defined('JPATH_CACHE') ? JPATH_CACHE : '';
		if ($root === '' || !is_dir($root)) {
			return '';
		}
		return $root . '/vigling_profile_geocode';
	}

	private static function cachePath(string $key): string
	{
		return self::cacheDir() . '/' . $key . '.json';
	}

	private static function cacheDirWritable(): bool
	{
		$dir = self::cacheDir();
		if ($dir === '') {
			return false;
		}
		if (!is_dir($dir)) {
			if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
				return false;
			}
		}
		return is_writable($dir);
	}
}

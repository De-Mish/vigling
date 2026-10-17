<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

/**
 * Short cache for public catalog query results.
 *
 * Values live in Redis when the site is switched to the Redis cache handler in
 * Global Configuration (System - Cache Handler) and the PHP Redis extension is
 * available. In every other case, or when Redis is down, the old file cache is
 * used, so nothing changes until Redis is configured.
 * Values are serialized so stdClass rows stay objects.
 */
final class CatalogQueryCache
{
	private const REDIS_PREFIX = 'vigling:catalog:';

	/** @var \Redis|false|null null = not tried yet, false = unavailable */
	private static $redis = null;

	public static function remember(string $bucket, string $key, int $ttl, callable $loader)
	{
		$ttl = max(1, $ttl);
		$bucket = preg_replace('/[^a-z0-9_-]/i', '', $bucket) ?: 'catalog';
		$hash = hash('sha256', $key);

		$redis = self::redis();
		if ($redis !== false) {
			$redisKey = self::REDIS_PREFIX . $bucket . ':' . $hash;
			$usable = true;
			try {
				$raw = $redis->get($redisKey);
				if (is_string($raw) && $raw !== '') {
					$data = @unserialize($raw, ['allowed_classes' => [\stdClass::class]]);
					if (is_array($data) && array_key_exists('v', $data)) {
						return $data['v'];
					}
				}
			} catch (\Throwable $e) {
				self::$redis = false;
				$usable = false;
			}

			if ($usable) {
				$value = $loader();
				try {
					$redis->setex($redisKey, $ttl, serialize(['v' => $value]));
				} catch (\Throwable $e) {
					self::$redis = false;
				}

				return $value;
			}
		}

		return self::rememberInFile($bucket, $hash, $ttl, $loader);
	}

	private static function rememberInFile(string $bucket, string $hash, int $ttl, callable $loader)
	{
		$dir = (\defined('JPATH_CACHE') ? JPATH_CACHE : sys_get_temp_dir()) . '/vigling-catalog/' . $bucket;
		$file = $dir . '/' . $hash . '.ser';
		if (is_file($file) && (time() - (int) filemtime($file)) < $ttl) {
			$raw = @file_get_contents($file);
			if (is_string($raw) && $raw !== '') {
				$data = @unserialize($raw, ['allowed_classes' => [\stdClass::class]]);
				if (is_array($data) && array_key_exists('v', $data)) {
					return $data['v'];
				}
			}
		}

		$value = $loader();
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		if (is_dir($dir) && is_writable($dir)) {
			@file_put_contents($file, serialize(['v' => $value]), LOCK_EX);
		}

		return $value;
	}

	/** @return \Redis|false */
	private static function redis()
	{
		if (self::$redis !== null) {
			return self::$redis;
		}

		self::$redis = false;
		try {
			if (!class_exists('\\Redis', false) || !class_exists('\\Joomla\\CMS\\Factory')) {
				return self::$redis;
			}
			$app = \Joomla\CMS\Factory::getApplication();
			if ((string) $app->get('cache_handler', 'file') !== 'redis') {
				return self::$redis;
			}

			$host = trim((string) $app->get('redis_server_host', 'localhost'));
			$port = (int) $app->get('redis_server_port', 6379);
			$auth = (string) $app->get('redis_server_auth', '');
			$db = (int) $app->get('redis_server_db', 0);

			$client = new \Redis();
			$connected = $host !== '' && $host[0] === '/'
				? $client->connect($host)
				: $client->connect($host, $port > 0 ? $port : 6379, 1.0);
			if (!$connected) {
				return self::$redis;
			}
			if ($auth !== '' && !$client->auth($auth)) {
				return self::$redis;
			}
			if ($db > 0) {
				$client->select($db);
			}
			self::$redis = $client;
		} catch (\Throwable $e) {
			self::$redis = false;
		}

		return self::$redis;
	}
}

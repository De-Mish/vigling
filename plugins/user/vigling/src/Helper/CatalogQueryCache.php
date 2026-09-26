<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

/**
 * Short file cache for public catalog query results.
 * Values are serialized so stdClass rows stay objects.
 */
final class CatalogQueryCache
{
	public static function remember(string $bucket, string $key, int $ttl, callable $loader)
	{
		$ttl = max(1, $ttl);
		$bucket = preg_replace('/[^a-z0-9_-]/i', '', $bucket) ?: 'catalog';
		$dir = (\defined('JPATH_CACHE') ? JPATH_CACHE : sys_get_temp_dir()) . '/vigling-catalog/' . $bucket;
		$file = $dir . '/' . hash('sha256', $key) . '.ser';
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
}

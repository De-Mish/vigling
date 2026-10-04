<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

trait CatalogCacheTrait
{
	private function rememberCatalog(string $bucket, string $key, callable $loader)
	{
		if (!class_exists(CatalogQueryCache::class, false)) {
			$path = __DIR__ . '/CatalogQueryCache.php';
			if (is_file($path)) {
				require_once $path;
			}
		}
		if (class_exists(CatalogQueryCache::class, false)) {
			return CatalogQueryCache::remember($bucket, $key, 180, $loader);
		}

		return $loader();
	}
}
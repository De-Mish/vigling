<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Shared sorting rules for the catalog lists (specialists, stocks, courses, model search).
 *
 * The chosen sort type is stored in a long-lived cookie, so it survives filtering,
 * resetting the filter, logging in and logging out until another type is selected.
 */
final class CatalogSortHelper
{
	public const GROUP_MASTERS = 'masters';
	public const GROUP_OFFERS = 'offers';

	private const COOKIE_PREFIX = 'vg_sort_';
	private const COOKIE_LIFETIME = 31536000;

	/**
	 * @param list<string> $allowed
	 */
	public static function resolveOrdering(string $group, array $allowed, string $default): string
	{
		$app = Factory::getApplication();
		$input = $app->getInput();
		$cookieName = self::COOKIE_PREFIX . $group;

		$requested = trim((string) $input->getString('filter_order', ''));
		$saved = trim((string) $input->cookie->getString($cookieName, ''));

		if ($requested !== '' && in_array($requested, $allowed, true)) {
			if ($requested !== $saved) {
				self::writeCookie($app, $cookieName, $requested);
			}

			return $requested;
		}

		if ($saved !== '' && in_array($saved, $allowed, true)) {
			return $saved;
		}

		return $default;
	}

	public static function ratingExpression(DatabaseInterface $db): ?string
	{
		$helper = JPATH_SITE . '/components/com_orders/src/Helper/ReviewHelper.php';
		if (!class_exists(\Viglin\Component\Orders\Site\Helper\ReviewHelper::class, false)) {
			if (!is_file($helper)) {
				return null;
			}
			require_once $helper;
		}
		if (!\Viglin\Component\Orders\Site\Helper\ReviewHelper::ensureSchema($db)) {
			return null;
		}

		return 'COALESCE((SELECT ROUND(AVG(' . $db->quoteName('rv.rating') . '), 1)'
			. ' FROM ' . $db->quoteName($db->getPrefix() . 'vigling_reviews', 'rv')
			. ' WHERE ' . $db->quoteName('rv.to_user_id') . ' = ' . $db->quoteName('u.id')
			. ' AND ' . $db->quoteName('rv.direction') . ' = ' . $db->quote(\Viglin\Component\Orders\Site\Helper\ReviewHelper::DIRECTION_CLIENT_TO_MASTER)
			. ' AND ' . $db->quoteName('rv.rating') . ' BETWEEN 1 AND 5), 0)';
	}

	private static function writeCookie($app, string $name, string $value): void
	{
		if (headers_sent()) {
			return;
		}
		setcookie($name, $value, [
			'expires' => time() + self::COOKIE_LIFETIME,
			'path' => '/',
			'secure' => method_exists($app, 'isSSLConnection') ? (bool) $app->isSSLConnection() : false,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
	}
}

<?php

namespace Viglin\Component\Orders\Site\Helper;

\defined('_JEXEC') or die;

/**
 * TEMPORARY placeholder: online booking is paused for every master except the
 * ones listed in ALLOWED_MASTER_IDS. Browsing and registration keep working.
 *
 * To lift the pause: set ENABLED to false (or delete this file together with the
 * calls to BookingPauseHelper in Lkbooking.php, OrdersController::repeat() and
 * the profile template default_public.php).
 */
final class BookingPauseHelper
{
	public const ENABLED = true;

	public const ALLOWED_MASTER_IDS = [144765];

	public const MESSAGE = 'Онлайн-запись скоро откроется. Сейчас вы можете смотреть профили мастеров, услуги и цены.';

	public static function isPausedFor(int $masterId): bool
	{
		return self::ENABLED && !\in_array($masterId, self::ALLOWED_MASTER_IDS, true);
	}
}

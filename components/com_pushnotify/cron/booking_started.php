<?php
/**
 * Обёртка для совместимости: старый cron «приём начался».
 */
$_SERVER['VIGLING_REMINDER_LEGACY'] = 'started';
require __DIR__ . '/booking_reminders.php';

<?php
/**
 * Обёртка для совместимости: старый cron «за 30 минут».
 */
$_SERVER['VIGLING_REMINDER_LEGACY'] = 'in_30min';
require __DIR__ . '/booking_reminders.php';

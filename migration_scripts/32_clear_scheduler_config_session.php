<?php
/**
 * Очищает кэш/сессию настроек com_scheduler, чтобы форма в админке подтянула ключ WebCron из БД.
 * Запуск: php migration_scripts/32_clear_scheduler_config_session.php
 * После запуска откройте заново: Настройки планировщика (иконка шестерёнки) → вкладка WebCron.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
if (!is_file($base . '/configuration.php')) {
    $base = getcwd();
}
require_once $base . '/includes/defines.php';
require_once $base . '/includes/framework.php';
$app = \Joomla\CMS\Factory::getApplication('administrator');
$app->initialise();

$app->setUserState('com_config.edit.component.com_scheduler.data', null);
echo "Сессия настроек планировщика очищена.\n";
echo "Откройте заново: Запланированные задачи → Настройки (шестерёнка) → вкладка WebCron — поле «Базовая ссылка» должно заполниться.\n";

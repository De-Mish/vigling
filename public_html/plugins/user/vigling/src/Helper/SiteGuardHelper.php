<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Extra safety around bookings. Every check fails open: a missing table,
 * a full disk or a database error leaves the existing action working.
 *
 * A feature is on until a row says otherwise. To turn booking off without
 * uploading a file, in phpMyAdmin:
 * INSERT INTO prefix_vigling_feature_flags (name, enabled, updated_at)
 * VALUES ('booking_create', 0, UTC_TIMESTAMP())
 * ON DUPLICATE KEY UPDATE enabled = 0, updated_at = UTC_TIMESTAMP();
 * Delete the row, or set enabled = 1, to turn it back on.
 * Names: booking_create, booking_cancel, booking_reschedule,
 * booking_complete, booking_journal.
 */
final class SiteGuardHelper
{
    public const CREATE_LIMIT = 60;
    public const CREATE_WINDOW = 600;
    private const DEAD_KEEP_DAYS = 90;

    /** @var array<string, bool> */
    private static array $flagCache = [];

    private static bool $schemaReady = false;

    /**
     * @return array{ok:bool, db:bool}
     */
    public static function health(): array
    {
        $dbOk = false;
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery('SELECT 1');
            $dbOk = (int) $db->loadResult() === 1;
        } catch (\Throwable $e) {
            self::note('health_db_failed', []);
        }

        return ['ok' => $dbOk, 'db' => $dbOk];
    }

    public static function enabled(string $flag): bool
    {
        $flag = self::flagName($flag);
        if ($flag === '') {
            return true;
        }
        if (array_key_exists($flag, self::$flagCache)) {
            return self::$flagCache[$flag];
        }
        $enabled = true;
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            if (!self::ensureSchema($db)) {
                self::$flagCache[$flag] = true;

                return true;
            }
            $query = $db->getQuery(true)
                ->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__vigling_feature_flags'))
                ->where($db->quoteName('name') . ' = ' . $db->quote($flag));
            $db->setQuery($query, 0, 1);
            $value = $db->loadResult();
            if ($value !== null) {
                $enabled = (int) $value === 1;
            }
        } catch (\Throwable $e) {
            $enabled = true;
        }
        self::$flagCache[$flag] = $enabled;
        if (!$enabled) {
            self::note('feature_off', ['flag' => $flag]);
        }

        return $enabled;
    }

    public static function disabledMessage(string $flag): string
    {
        switch (self::flagName($flag)) {
            case 'booking_create':
                return 'Запись временно недоступна. Попробуйте позже.';
            case 'booking_cancel':
                return 'Отмена записи временно недоступна. Попробуйте позже.';
            case 'booking_reschedule':
                return 'Перенос записи временно недоступен. Попробуйте позже.';
            case 'booking_complete':
                return 'Отметка о выполнении временно недоступна. Попробуйте позже.';
            case 'booking_journal':
                return 'Блокировка времени временно недоступна. Попробуйте позже.';
            default:
                return 'Действие временно недоступно. Попробуйте позже.';
        }
    }

    public static function allowBookingCreate(int $userId): bool
    {
        if ($userId <= 0) {
            return true;
        }
        try {
            $dir = self::rateDir();
            if ($dir === '') {
                return true;
            }
            $file = $dir . '/create-' . $userId . '.json';
            $handle = @fopen($file, 'c+');
            if ($handle === false) {
                return true;
            }
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);

                return true;
            }
            $raw = stream_get_contents($handle);
            $times = json_decode(is_string($raw) ? $raw : '', true);
            if (!is_array($times)) {
                $times = [];
            }
            $now = time();
            $times = self::pruneHits($times, $now, self::CREATE_WINDOW);
            if (count($times) >= self::CREATE_LIMIT) {
                flock($handle, LOCK_UN);
                fclose($handle);
                self::note('booking_rate_limited', ['user_id' => $userId]);

                return false;
            }
            $times[] = $now;
            $payload = json_encode(array_values($times));
            if (!is_string($payload)) {
                flock($handle, LOCK_UN);
                fclose($handle);

                return true;
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $payload);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    public static function record(string $action, array $order): void
    {
        $action = self::flagName($action);
        if ($action === '') {
            return;
        }
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            if (!self::ensureSchema($db)) {
                return;
            }
            $actor = 0;
            try {
                $identity = Factory::getApplication()->getIdentity();
                $actor = (int) ($identity->id ?? 0);
            } catch (\Throwable $e) {
                $actor = 0;
            }
            $time = trim((string) ($order['time'] ?? ''));
            $row = (object) [
                'booking_id' => max(0, (int) ($order['id'] ?? 0)),
                'action' => substr($action, 0, 32),
                'actor_user_id' => max(0, $actor),
                'client_user_id' => max(0, (int) ($order['user_id'] ?? 0)),
                'master_id' => max(0, (int) ($order['master_id'] ?? 0)),
                'time_utc' => $time !== '' ? $time : null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];
            $db->insertObject('#__vigling_booking_audit', $row);
            self::note('booking_audit', [
                'action' => $action,
                'booking_id' => (int) $row->booking_id,
                'actor_user_id' => (int) $row->actor_user_id,
            ]);
        } catch (\Throwable $e) {
            self::note('booking_audit_failed', ['action' => $action]);
        }
    }

    public static function maintain(DatabaseInterface $db): void
    {
        self::ensureSchema($db);
        self::purgeDeadLetters($db);
        self::purgeRateFiles();
    }

    /**
     * @param list<mixed> $times
     * @return list<int>
     */
    public static function pruneHits(array $times, int $now, int $window): array
    {
        $kept = [];
        foreach ($times as $time) {
            if (!is_numeric($time)) {
                continue;
            }
            $stamp = (int) $time;
            if ($stamp > 0 && ($now - $stamp) < $window) {
                $kept[] = $stamp;
            }
        }

        return $kept;
    }

    public static function backoffSeconds(int $failedAttempts): int
    {
        $failedAttempts = max(0, min(4, $failedAttempts));

        return 60 * (2 ** $failedAttempts);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function note(string $event, array $context): void
    {
        try {
            $dir = \defined('JPATH_ADMINISTRATOR') ? JPATH_ADMINISTRATOR . '/logs' : '';
            if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
                return;
            }
            $event = preg_replace('/[^a-z0-9_]/', '', strtolower($event)) ?? '';
            if ($event === '') {
                return;
            }
            $safe = ['ts' => gmdate('c'), 'event' => $event];
            foreach ($context as $key => $value) {
                $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key)) ?? '';
                if ($name === '' || !is_scalar($value)) {
                    continue;
                }
                if (is_string($value)) {
                    $safe[$name] = substr($value, 0, 80);
                } else {
                    $safe[$name] = $value;
                }
            }
            $line = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($line)) {
                return;
            }
            $file = $dir . '/vigling-guard.php';
            if (!is_file($file)) {
                @file_put_contents($file, "#<?php die('Forbidden.'); ?>\n", LOCK_EX);
            }
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
        }
    }

    private static function ensureSchema(DatabaseInterface $db): bool
    {
        if (self::$schemaReady) {
            return true;
        }
        try {
            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__vigling_feature_flags') . ' (
                    `name` VARCHAR(64) NOT NULL,
                    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `updated_at` DATETIME NULL,
                    PRIMARY KEY (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            )->execute();
            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__vigling_booking_audit') . ' (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `booking_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `action` VARCHAR(32) NOT NULL,
                    `actor_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `client_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `master_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `time_utc` DATETIME NULL,
                    `created_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_booking` (`booking_id`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            )->execute();
            self::$schemaReady = true;

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function purgeDeadLetters(DatabaseInterface $db): void
    {
        try {
            $columns = array_change_key_case((array) $db->getTableColumns('#__vigling_push_dead', false), CASE_LOWER);
            if (!isset($columns['failed_at'])) {
                return;
            }
            $cut = gmdate('Y-m-d H:i:s', time() - (self::DEAD_KEEP_DAYS * 86400));
            $db->setQuery(
                'DELETE FROM ' . $db->quoteName('#__vigling_push_dead')
                . ' WHERE ' . $db->quoteName('failed_at') . ' < ' . $db->quote($cut)
            )->execute();
        } catch (\Throwable $e) {
        }
    }

    private static function purgeRateFiles(): void
    {
        $dir = self::rateDir(false);
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $cut = time() - 172800;
        foreach (glob($dir . '/create-*.json') ?: [] as $file) {
            if (is_file($file) && (int) @filemtime($file) > 0 && (int) @filemtime($file) < $cut) {
                @unlink($file);
            }
        }
    }

    private static function rateDir(bool $create = true): string
    {
        $root = \defined('JPATH_CACHE') ? JPATH_CACHE : '';
        if ($root === '' || !is_dir($root)) {
            return '';
        }
        $dir = $root . '/vigling-guard';
        if (!is_dir($dir)) {
            if (!$create || !@mkdir($dir, 0775, true)) {
                return '';
            }
        }
        if (!is_writable($dir)) {
            return '';
        }

        return $dir;
    }

    private static function flagName(string $flag): string
    {
        $flag = strtolower(trim($flag));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $flag)) {
            return '';
        }

        return $flag;
    }
}

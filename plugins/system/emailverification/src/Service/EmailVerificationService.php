<?php

namespace Joomla\Plugin\System\Emailverification\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Viglin\Component\Pushnotify\Site\Helper\NotificationSettingsHelper;

final class EmailVerificationService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_BLOCKED = 'blocked';
    public const GRACE_PERIOD_MINUTES = 4320;
    public const TOKEN_TTL_DAYS = 30;

    public const RESEND_REASON_OK = 'ok';
    public const RESEND_REASON_NO_STATE = 'no_state';
    public const RESEND_REASON_ALREADY_VERIFIED = 'already_verified';
    public const RESEND_REASON_COOLDOWN = 'cooldown';
    public const RESEND_REASON_SEND_FAILED = 'send_failed';
    public const RESEND_REASON_USER_NOT_FOUND = 'user_not_found';

    public static function ensureSchema(DatabaseInterface $db): void
    {
        $tableName = $db->replacePrefix('#__vigling_email_verifications');
        $db->setQuery('SHOW TABLES LIKE ' . $db->quote($tableName));

        if ($db->loadResult()) {
            return;
        }

        $db->setQuery(
            'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__vigling_email_verifications') . " (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `user_id` int NOT NULL,
                `email` varchar(255) NOT NULL,
                `status` enum('pending','verified','blocked') NOT NULL DEFAULT 'pending',
                `token_hash` char(64) DEFAULT NULL,
                `token_expires_at` datetime DEFAULT NULL,
                `grace_until` datetime NOT NULL,
                `verified_at` datetime DEFAULT NULL,
                `blocked_at` datetime DEFAULT NULL,
                `last_sent_at` datetime DEFAULT NULL,
                `resend_count` int NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_id` (`user_id`),
                KEY `idx_status_grace` (`status`,`grace_until`),
                KEY `idx_token_hash` (`token_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        )->execute();
    }

    public static function issueForNewUser(DatabaseInterface $db, int $userId, string $email, string $registerDateUtc, ?int $graceMinutes = null, ?int $tokenTtlDays = null): ?string
    {
        if ($userId <= 0 || $email === '') {
            return null;
        }

        self::ensureSchema($db);

        $email = trim(mb_strtolower($email));
        $registerDt = self::parseUtc($registerDateUtc) ?: self::nowUtc();
        $graceMinutes = max(1, (int) ($graceMinutes ?? self::getConfiguredGraceMinutes()));
        $tokenTtlDays = max(1, (int) ($tokenTtlDays ?? self::getConfiguredTokenTtlDays()));
        $graceUntil = $registerDt->modify('+' . $graceMinutes . ' minutes');
        $tokenExpiresAt = $registerDt->modify('+' . $tokenTtlDays . ' days');
        $rawToken = self::generateToken();
        $tokenHash = hash('sha256', $rawToken);
        $now = self::nowUtc()->format('Y-m-d H:i:s');

        $insertQuery = $db->getQuery(true)
            ->insert($db->quoteName('#__vigling_email_verifications'))
            ->columns([
                $db->quoteName('user_id'),
                $db->quoteName('email'),
                $db->quoteName('status'),
                $db->quoteName('token_hash'),
                $db->quoteName('token_expires_at'),
                $db->quoteName('grace_until'),
                $db->quoteName('last_sent_at'),
                $db->quoteName('resend_count'),
                $db->quoteName('created_at'),
                $db->quoteName('updated_at'),
            ])
            ->values(
                (int) $userId
                . ', ' . $db->quote($email)
                . ', ' . $db->quote(self::STATUS_PENDING)
                . ', ' . $db->quote($tokenHash)
                . ', ' . $db->quote($tokenExpiresAt->format('Y-m-d H:i:s'))
                . ', ' . $db->quote($graceUntil->format('Y-m-d H:i:s'))
                . ', ' . $db->quote($now)
                . ', 1'
                . ', ' . $db->quote($now)
                . ', ' . $db->quote($now)
            );

        $query = (string) $insertQuery;

        $query .= ' ON DUPLICATE KEY UPDATE '
            . $db->quoteName('email') . ' = VALUES(' . $db->quoteName('email') . '), '
            . $db->quoteName('status') . ' = IF(' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_VERIFIED) . ', ' . $db->quoteName('status') . ', VALUES(' . $db->quoteName('status') . ')), '
            . $db->quoteName('token_hash') . ' = VALUES(' . $db->quoteName('token_hash') . '), '
            . $db->quoteName('token_expires_at') . ' = VALUES(' . $db->quoteName('token_expires_at') . '), '
            . $db->quoteName('grace_until') . ' = VALUES(' . $db->quoteName('grace_until') . '), '
            . $db->quoteName('last_sent_at') . ' = VALUES(' . $db->quoteName('last_sent_at') . '), '
            . $db->quoteName('resend_count') . ' = IF(' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_VERIFIED) . ', ' . $db->quoteName('resend_count') . ', 1), '
            . $db->quoteName('updated_at') . ' = VALUES(' . $db->quoteName('updated_at') . ')';

        $db->setQuery($query)->execute();

        return $rawToken;
    }

    public static function getGracePeriodHumanLabel(): string
    {
        $minutes = max(1, (int) self::getConfiguredGraceMinutes());

        if ($minutes % 1440 === 0) {
            $days = (int) ($minutes / 1440);
            if ($days === 1) {
                return '1 день';
            }
            if ($days >= 2 && $days <= 4) {
                return $days . ' дня';
            }
            return $days . ' дней';
        }

        if ($minutes === 1) {
            return '1 минуту';
        }
        if ($minutes >= 2 && $minutes <= 4) {
            return $minutes . ' минуты';
        }
        return $minutes . ' минут';
    }

    public static function sendVerificationEmail(int $userId, string $name, string $email, string $rawToken): bool
    {
        if ($userId <= 0 || $email === '' || $rawToken === '') {
            return false;
        }

        $app = Factory::getApplication();
        $app->getLanguage()->load('plg_system_emailverification', JPATH_SITE . '/plugins/system/emailverification');
        $verifyUrl = rtrim(Uri::root(), '/') . '/index.php?option=com_users&task=profile.verifyEmail&token=' . urlencode($rawToken);

        $subject = Text::_('PLG_SYSTEM_EMAILVERIFICATION_MAIL_SUBJECT');
        $body = Text::sprintf(
            'PLG_SYSTEM_EMAILVERIFICATION_MAIL_BODY',
            $name !== '' ? $name : $email,
            htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8')
        );

        try {
            /** @var Mail $mailer */
            $mailer = Factory::getMailer();
            $mailer->isHtml(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->setSubject($subject);
            $mailer->setBody($body);
            $mailer->addRecipient($email);
            $mailer->setSender([$app->get('mailfrom'), $app->get('fromname')]);
            $result = $mailer->Send();

            if ($result === false) {
                Log::add('verification_email_send_failed user_id=' . $userId, Log::WARNING, 'emailverification');
                return false;
            }

            Log::add('verification_email_sent user_id=' . $userId, Log::INFO, 'emailverification');
            return true;
        } catch (\Throwable $e) {
            Log::add('verification_email_exception user_id=' . $userId . ' msg=' . $e->getMessage(), Log::WARNING, 'emailverification');
            return false;
        }
    }

    public static function verifyByToken(DatabaseInterface $db, string $rawToken): array
    {
        self::ensureSchema($db);

        $rawToken = trim($rawToken);

        if ($rawToken === '') {
            return ['ok' => false, 'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_TOKEN_INVALID'];
        }

        $tokenHash = hash('sha256', $rawToken);

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('ev.id'),
                $db->quoteName('ev.user_id'),
                $db->quoteName('ev.status'),
                $db->quoteName('ev.token_expires_at'),
            ])
            ->from($db->quoteName('#__vigling_email_verifications', 'ev'))
            ->where($db->quoteName('ev.token_hash') . ' = :tokenHash')
            ->where($db->quoteName('ev.status') . ' IN (' . $db->quote(self::STATUS_PENDING) . ', ' . $db->quote(self::STATUS_BLOCKED) . ')')
            ->setLimit(1)
            ->bind(':tokenHash', $tokenHash);

        $db->setQuery($query);
        $row = $db->loadAssoc();

        if (!$row) {
            return ['ok' => false, 'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_TOKEN_INVALID'];
        }

        $expires = self::parseUtc((string) ($row['token_expires_at'] ?? ''));

        if ($expires && $expires < self::nowUtc()) {
            return ['ok' => false, 'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_TOKEN_EXPIRED'];
        }

        $now = self::nowUtc()->format('Y-m-d H:i:s');
        $userId = (int) $row['user_id'];

        $db->transactionStart();

        try {
            $verificationId = (int) $row['id'];
            $updateVerification = $db->getQuery(true)
                ->update($db->quoteName('#__vigling_email_verifications'))
                ->set($db->quoteName('status') . ' = ' . $db->quote(self::STATUS_VERIFIED))
                ->set($db->quoteName('verified_at') . ' = ' . $db->quote($now))
                ->set($db->quoteName('token_hash') . ' = NULL')
                ->set($db->quoteName('token_expires_at') . ' = NULL')
                ->set($db->quoteName('updated_at') . ' = ' . $db->quote($now))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $verificationId, ParameterType::INTEGER);
            $db->setQuery($updateVerification)->execute();

            $updateUser = $db->getQuery(true)
                ->update($db->quoteName('#__users'))
                ->set($db->quoteName('block') . ' = 0')
                ->where($db->quoteName('id') . ' = :userId')
                ->bind(':userId', $userId, ParameterType::INTEGER);
            $db->setQuery($updateUser)->execute();

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            Log::add('verification_confirm_exception user_id=' . $userId . ' msg=' . $e->getMessage(), Log::ERROR, 'emailverification');

            return ['ok' => false, 'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_TOKEN_INVALID'];
        }

        Log::add('verification_confirmed user_id=' . $userId, Log::INFO, 'emailverification');

        return ['ok' => true, 'user_id' => $userId, 'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_VERIFY_SUCCESS'];
    }

    public static function getUserVerificationState(DatabaseInterface $db, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        self::ensureSchema($db);

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('status'),
                $db->quoteName('grace_until'),
                $db->quoteName('email'),
                $db->quoteName('last_sent_at'),
                $db->quoteName('resend_count'),
            ])
            ->from($db->quoteName('#__vigling_email_verifications'))
            ->where($db->quoteName('user_id') . ' = :userId')
            ->setLimit(1)
            ->bind(':userId', $userId, ParameterType::INTEGER);
        $db->setQuery($query);

        $row = $db->loadAssoc();

        return $row ?: null;
    }

    public static function enforceGraceForUser(DatabaseInterface $db, int $userId): bool
    {
        $state = self::getUserVerificationState($db, $userId);

        if (!$state || ($state['status'] ?? '') !== self::STATUS_PENDING) {
            return false;
        }

        $graceUntil = self::parseUtc((string) ($state['grace_until'] ?? ''));

        if (!$graceUntil || $graceUntil > self::nowUtc()) {
            return false;
        }

        return self::blockVerificationRecord($db, (int) $state['id'], $userId);
    }

    public static function enforceExpiredPendingBatch(DatabaseInterface $db, int $limit = 200): int
    {
        self::ensureSchema($db);

        $limit = max(1, (int) $limit);

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('user_id'),
            ])
            ->from($db->quoteName('#__vigling_email_verifications'))
            ->where($db->quoteName('status') . ' = ' . $db->quote(self::STATUS_PENDING))
            ->where($db->quoteName('grace_until') . ' < ' . $db->quote(self::nowUtc()->format('Y-m-d H:i:s')))
            ->order($db->quoteName('id') . ' ASC')
            ->setLimit($limit);
        $db->setQuery($query);
        $rows = $db->loadAssocList() ?: [];

        if ($rows === []) {
            return 0;
        }

        $blocked = 0;
        foreach ($rows as $row) {
            $verificationId = (int) ($row['id'] ?? 0);
            $userId = (int) ($row['user_id'] ?? 0);
            if ($verificationId <= 0 || $userId <= 0) {
                continue;
            }

            if (self::blockVerificationRecord($db, $verificationId, $userId)) {
                $blocked++;
            }
        }

        if ($blocked > 0) {
            Log::add('verification_grace_batch_blocked count=' . $blocked, Log::INFO, 'emailverification');
        }

        return $blocked;
    }

    public static function ensureEnvelopeForUserId(DatabaseInterface $db, int $userId, ?int $tokenTtlDays = null): array
    {
        if ($userId <= 0) {
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_USER_NOT_FOUND,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC',
                'created' => false,
            ];
        }

        $state = self::getUserVerificationState($db, $userId);
        if ($state) {
            return [
                'ok' => true,
                'reason_key' => self::RESEND_REASON_OK,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_SUCCESS',
                'created' => false,
            ];
        }

        $user = self::getUserById($db, $userId);
        if (!$user || empty($user['email'])) {
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_USER_NOT_FOUND,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC',
                'created' => false,
            ];
        }

        $rawToken = self::issueForNewUser(
            $db,
            (int) $user['id'],
            (string) $user['email'],
            (string) ($user['registerDate'] ?? ''),
            self::getConfiguredGraceMinutes(),
            $tokenTtlDays ?? self::getConfiguredTokenTtlDays()
        );

        if (!$rawToken) {
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_NO_STATE,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC',
                'created' => false,
            ];
        }

        $sent = self::sendVerificationEmail((int) $user['id'], (string) ($user['name'] ?? ''), (string) $user['email'], $rawToken);

        if (!$sent) {
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_SEND_FAILED,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_SEND_FAILED_NOTICE',
                'created' => true,
            ];
        }

        Log::add('verification_envelope_created_and_sent user_id=' . (int) $user['id'], Log::INFO, 'emailverification');

        return [
            'ok' => true,
            'reason_key' => self::RESEND_REASON_OK,
            'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_SUCCESS',
            'created' => true,
        ];
    }

    public static function resendForUserId(DatabaseInterface $db, int $userId, int $cooldownSeconds = 120, int $tokenTtlDays = 30): array
    {
        if (!self::isConfiguredResendEnabled()) {
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_COOLDOWN,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_LIMIT',
                'requires_verification' => true,
                'resend_allowed' => false,
            ];
        }
        $cooldownSeconds = self::getConfiguredResendCooldownSeconds($cooldownSeconds);
        $tokenTtlDays = self::getConfiguredTokenTtlDays($tokenTtlDays);
        $envelopeResult = self::ensureEnvelopeForUserId($db, $userId, $tokenTtlDays);
        if (!$envelopeResult['ok']) {
            Log::add('verification_resend_envelope_failed user_id=' . $userId . ' reason=' . (string) ($envelopeResult['reason_key'] ?? self::RESEND_REASON_NO_STATE), Log::WARNING, 'emailverification');
            return [
                'ok' => false,
                'reason_key' => (string) ($envelopeResult['reason_key'] ?? self::RESEND_REASON_NO_STATE),
                'message_key' => (string) ($envelopeResult['message_key'] ?? 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC'),
                'requires_verification' => true,
                'resend_allowed' => false,
            ];
        }
        if (!empty($envelopeResult['created'])) {
            Log::add('verification_resend_envelope_created user_id=' . $userId, Log::INFO, 'emailverification');
            return [
                'ok' => true,
                'reason_key' => self::RESEND_REASON_OK,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_SUCCESS',
                'requires_verification' => true,
                'resend_allowed' => true,
            ];
        }

        $state = self::getUserVerificationState($db, $userId);

        if (!$state) {
            Log::add('verification_resend_no_state user_id=' . $userId, Log::WARNING, 'emailverification');
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_NO_STATE,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC',
                'requires_verification' => true,
                'resend_allowed' => false,
            ];
        }

        $status = (string) ($state['status'] ?? '');

        if ($status === self::STATUS_VERIFIED) {
            Log::add('verification_resend_already_verified user_id=' . $userId, Log::INFO, 'emailverification');
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_ALREADY_VERIFIED,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_ALREADY_VERIFIED',
                'requires_verification' => false,
                'resend_allowed' => false,
            ];
        }

        $lastSentAt = self::parseUtc((string) ($state['last_sent_at'] ?? ''));

        if ($lastSentAt) {
            $availableAt = $lastSentAt->modify('+' . max(1, $cooldownSeconds) . ' seconds');

            if ($availableAt > self::nowUtc()) {
                Log::add('verification_resend_cooldown user_id=' . $userId, Log::INFO, 'emailverification');
                return [
                    'ok' => false,
                    'reason_key' => self::RESEND_REASON_COOLDOWN,
                    'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_LIMIT',
                    'requires_verification' => true,
                    'resend_allowed' => false,
                ];
            }
        }

        $rawToken = self::generateToken();
        $tokenHash = hash('sha256', $rawToken);
        $tokenExpiresAt = self::nowUtc()->modify('+' . max(1, $tokenTtlDays) . ' days')->format('Y-m-d H:i:s');
        $now = self::nowUtc()->format('Y-m-d H:i:s');

        $user = self::getUserById($db, $userId);

        if (!$user || empty($user['email'])) {
            Log::add('verification_resend_user_not_found user_id=' . $userId, Log::WARNING, 'emailverification');
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_USER_NOT_FOUND,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC',
                'requires_verification' => true,
                'resend_allowed' => false,
            ];
        }

        $verificationId = (int) $state['id'];
        $update = $db->getQuery(true)
            ->update($db->quoteName('#__vigling_email_verifications'))
            ->set($db->quoteName('token_hash') . ' = ' . $db->quote($tokenHash))
            ->set($db->quoteName('token_expires_at') . ' = ' . $db->quote($tokenExpiresAt))
            ->set($db->quoteName('last_sent_at') . ' = ' . $db->quote($now))
            ->set($db->quoteName('resend_count') . ' = ' . $db->quoteName('resend_count') . ' + 1')
            ->set($db->quoteName('updated_at') . ' = ' . $db->quote($now))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $verificationId, ParameterType::INTEGER);
        $db->setQuery($update)->execute();

        $sent = self::sendVerificationEmail((int) $user['id'], (string) ($user['name'] ?? ''), (string) $user['email'], $rawToken);

        if (!$sent) {
            Log::add('verification_resend_send_failed user_id=' . (int) $user['id'], Log::WARNING, 'emailverification');
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_SEND_FAILED,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_SEND_FAILED_NOTICE',
                'requires_verification' => true,
                'resend_allowed' => true,
            ];
        }

        Log::add('verification_email_resent user_id=' . (int) $user['id'], Log::INFO, 'emailverification');

        return [
            'ok' => true,
            'reason_key' => self::RESEND_REASON_OK,
            'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_SUCCESS',
            'requires_verification' => true,
            'resend_allowed' => true,
        ];
    }

    public static function resendByEmail(DatabaseInterface $db, string $email, int $cooldownSeconds = 120): array
    {
        $email = trim(mb_strtolower($email));

        if ($email === '') {
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_NO_STATE,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC',
                'requires_verification' => true,
                'resend_allowed' => false,
            ];
        }

        self::ensureSchema($db);

        $query = $db->getQuery(true)
            ->select($db->quoteName('u.id'))
            ->from($db->quoteName('#__users', 'u'))
            ->where('LOWER(' . $db->quoteName('u.email') . ') = ' . $db->quote($email))
            ->setLimit(1);

        $db->setQuery($query);
        $userId = (int) $db->loadResult();

        if ($userId <= 0) {
            return [
                'ok' => false,
                'reason_key' => self::RESEND_REASON_USER_NOT_FOUND,
                'message_key' => 'PLG_SYSTEM_EMAILVERIFICATION_RESEND_GENERIC',
                'requires_verification' => true,
                'resend_allowed' => false,
            ];
        }

        return self::resendForUserId($db, $userId, $cooldownSeconds, self::getConfiguredTokenTtlDays());
    }

    public static function isBlockedByUnverified(DatabaseInterface $db, string $login): bool
    {
        $login = trim(mb_strtolower($login));

        if ($login === '') {
            return false;
        }

        self::ensureSchema($db);

        $query = $db->getQuery(true)
            ->select($db->quoteName('u.id'))
            ->from($db->quoteName('#__users', 'u'))
            ->join('INNER', $db->quoteName('#__vigling_email_verifications', 'ev') . ' ON ' . $db->quoteName('ev.user_id') . ' = ' . $db->quoteName('u.id'))
            ->where('(LOWER(' . $db->quoteName('u.username') . ') = ' . $db->quote($login) . ' OR LOWER(' . $db->quoteName('u.email') . ') = ' . $db->quote($login) . ')')
            ->where($db->quoteName('u.block') . ' = 1')
            ->where($db->quoteName('ev.status') . ' = ' . $db->quote(self::STATUS_BLOCKED))
            ->setLimit(1);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    private static function generateToken(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return hash('sha256', uniqid('vigling_email_verify_', true) . microtime(true));
        }
    }

    private static function parseUtc(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function getUserById(DatabaseInterface $db, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('name'),
                $db->quoteName('email'),
                $db->quoteName('registerDate'),
            ])
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :userId')
            ->setLimit(1)
            ->bind(':userId', $userId, ParameterType::INTEGER);
        $db->setQuery($query);

        $row = $db->loadAssoc();

        return $row ?: null;
    }

    private static function blockVerificationRecord(DatabaseInterface $db, int $verificationId, int $userId): bool
    {
        if (!self::isConfiguredExpirationBlockEnabled()) {
            return false;
        }
        if ($verificationId <= 0 || $userId <= 0) {
            return false;
        }

        $now = self::nowUtc()->format('Y-m-d H:i:s');
        $db->transactionStart();

        try {
            $updateVerification = $db->getQuery(true)
                ->update($db->quoteName('#__vigling_email_verifications'))
                ->set($db->quoteName('status') . ' = ' . $db->quote(self::STATUS_BLOCKED))
                ->set($db->quoteName('blocked_at') . ' = ' . $db->quote($now))
                ->set($db->quoteName('updated_at') . ' = ' . $db->quote($now))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $verificationId, ParameterType::INTEGER)
                ->where($db->quoteName('status') . ' = ' . $db->quote(self::STATUS_PENDING));
            $db->setQuery($updateVerification)->execute();

            $updateUser = $db->getQuery(true)
                ->update($db->quoteName('#__users'))
                ->set($db->quoteName('block') . ' = 1')
                ->where($db->quoteName('id') . ' = :userId')
                ->bind(':userId', $userId, ParameterType::INTEGER);
            $db->setQuery($updateUser)->execute();

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            Log::add('verification_grace_block_exception user_id=' . $userId . ' msg=' . $e->getMessage(), Log::ERROR, 'emailverification');
            return false;
        }

        Log::add('verification_grace_expired_blocked user_id=' . $userId, Log::INFO, 'emailverification');

        return true;
    }

    private static function getConfiguredGraceMinutes(?int $fallback = null): int
    {
        self::loadSettingsHelper();
        if (class_exists(NotificationSettingsHelper::class)) {
            try {
                return NotificationSettingsHelper::getEmailActivationGraceMinutes();
            } catch (\Throwable $e) {
            }
        }
        return max(1, (int) ($fallback ?? self::GRACE_PERIOD_MINUTES));
    }

    private static function getConfiguredTokenTtlDays(?int $fallback = null): int
    {
        self::loadSettingsHelper();
        if (class_exists(NotificationSettingsHelper::class)) {
            try {
                return NotificationSettingsHelper::getEmailTokenTtlDays();
            } catch (\Throwable $e) {
            }
        }
        return max(1, (int) ($fallback ?? self::TOKEN_TTL_DAYS));
    }

    private static function getConfiguredResendCooldownSeconds(int $fallback): int
    {
        self::loadSettingsHelper();
        if (class_exists(NotificationSettingsHelper::class)) {
            try {
                return NotificationSettingsHelper::getEmailResendCooldownSeconds();
            } catch (\Throwable $e) {
            }
        }
        return max(1, $fallback);
    }

    private static function isConfiguredExpirationBlockEnabled(): bool
    {
        self::loadSettingsHelper();
        if (class_exists(NotificationSettingsHelper::class)) {
            try {
                return NotificationSettingsHelper::isEmailExpirationBlockEnabled();
            } catch (\Throwable $e) {
            }
        }
        return true;
    }

    private static function isConfiguredResendEnabled(): bool
    {
        self::loadSettingsHelper();
        if (class_exists(NotificationSettingsHelper::class)) {
            try {
                return NotificationSettingsHelper::isEmailResendEnabled();
            } catch (\Throwable $e) {
            }
        }
        return true;
    }

    private static function loadSettingsHelper(): void
    {
        if (class_exists(NotificationSettingsHelper::class)) {
            return;
        }
        $path = JPATH_SITE . '/components/com_pushnotify/src/Helper/NotificationSettingsHelper.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
}

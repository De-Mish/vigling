<?php

namespace Joomla\Plugin\System\Viglingrecaptcha\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;

final class RecaptchaVerifier
{
    public const ACTION_REGISTRATION_SUBMIT = 'registration_submit';
    public const ACTION_QUICKAUTH_REGISTER = 'quickauth_register';
    public const ACTION_BOOKING_QUICKAUTH_REGISTER = 'booking_quickauth_register';

    public static function getFrontendConfig(): array
    {
        $config = self::getConfig();

        return [
            'enabled' => $config['enabled'] && $config['site_key'] !== '' && $config['secret_key'] !== '',
            'site_key' => $config['site_key'],
        ];
    }

    public static function isContextProtected(string $context): bool
    {
        $config = self::getConfig();

        if (!$config['enabled']) {
            return false;
        }

        if ($context === 'registration') {
            return $config['protect_registration'];
        }

        if ($context === 'quickauth_register') {
            return $config['protect_quickauth_register'];
        }

        if ($context === 'booking_quickauth_register') {
            return $config['protect_booking_quickauth_register'];
        }

        return false;
    }

    public static function verify(string $token, string $expectedAction, string $context, string $clientIp = ''): array
    {
        $config = self::getConfig();

        if (!$config['enabled'] || !self::isContextProtected($context)) {
            return self::allow('disabled_or_not_protected');
        }

        if ($config['site_key'] === '' || $config['secret_key'] === '') {
            self::log('warning', Text::_('PLG_SYSTEM_VIGLINGRECAPTCHA_LOG_CONFIG_MISSING'));
            return self::allow('config_missing');
        }

        if (trim($token) === '') {
            return self::deny('missing_token', 'PLG_SYSTEM_VIGLINGRECAPTCHA_ERR_HUMAN_CHECK');
        }

        $verifyResult = self::verifyWithGoogle($config, $token, $clientIp);

        if (!$verifyResult['ok']) {
            if ($verifyResult['transient']) {
                $message = 'recaptcha_verify_transient_failure: ' . ($verifyResult['debug'] ?? 'unknown');
                self::log('warning', $message);

                if ($config['fail_policy'] === 'open') {
                    return self::allow('transient_fail_open');
                }

                return self::deny('verify_unreachable', 'PLG_SYSTEM_VIGLINGRECAPTCHA_ERR_VERIFY_TEMP');
            }

            return self::deny('verify_rejected', 'PLG_SYSTEM_VIGLINGRECAPTCHA_ERR_HUMAN_CHECK');
        }

        $payload = $verifyResult['payload'];
        $score = isset($payload['score']) ? (float) $payload['score'] : 0.0;

        if ($score < $config['score_threshold']) {
            self::log('debug', 'recaptcha_low_score: ' . $score);
            return self::deny('low_score', 'PLG_SYSTEM_VIGLINGRECAPTCHA_ERR_HUMAN_CHECK');
        }

        $actualAction = isset($payload['action']) ? (string) $payload['action'] : '';
        if ($actualAction !== $expectedAction) {
            self::log('debug', 'recaptcha_action_mismatch: expected=' . $expectedAction . ', actual=' . $actualAction);
            return self::deny('action_mismatch', 'PLG_SYSTEM_VIGLINGRECAPTCHA_ERR_HUMAN_CHECK');
        }

        $responseHostname = self::normalizeHost((string) ($payload['hostname'] ?? ''));
        $requestHostname = self::normalizeHost((string) Uri::getInstance()->getHost());
        if ($responseHostname === '' || $requestHostname === '' || $responseHostname !== $requestHostname) {
            self::log('warning', 'recaptcha_hostname_mismatch: expected=' . $requestHostname . ', actual=' . $responseHostname);
            return self::deny('hostname_mismatch', 'PLG_SYSTEM_VIGLINGRECAPTCHA_ERR_HUMAN_CHECK');
        }

        if (!self::isChallengeRecent((string) ($payload['challenge_ts'] ?? ''))) {
            self::log('debug', 'recaptcha_stale_challenge');
            return self::deny('stale_challenge', 'PLG_SYSTEM_VIGLINGRECAPTCHA_ERR_HUMAN_CHECK');
        }

        return self::allow('verified');
    }

    private static function verifyWithGoogle(array $config, string $token, string $clientIp): array
    {
        $timeoutMs = max(1000, (int) $config['verify_timeout_ms']);

        try {
            $http = HttpFactory::getHttp(['timeout' => (int) ceil($timeoutMs / 1000)]);

            $requestPayload = [
                'secret' => $config['secret_key'],
                'response' => $token,
            ];

            if ($clientIp !== '') {
                $requestPayload['remoteip'] = $clientIp;
            }

            $response = $http->post(
                'https://www.google.com/recaptcha/api/siteverify',
                http_build_query($requestPayload),
                ['Content-Type' => 'application/x-www-form-urlencoded']
            );
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'transient' => true,
                'debug' => $e->getMessage(),
            ];
        }

        $statusCode = (int) ($response->code ?? 0);

        if ($statusCode >= 500 || $statusCode === 0) {
            return [
                'ok' => false,
                'transient' => true,
                'debug' => 'http_status_' . $statusCode,
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'ok' => false,
                'transient' => false,
                'debug' => 'http_status_' . $statusCode,
            ];
        }

        $payload = json_decode((string) ($response->body ?? ''), true);

        if (!is_array($payload)) {
            return [
                'ok' => false,
                'transient' => true,
                'debug' => 'invalid_json',
            ];
        }

        if (!empty($payload['success'])) {
            return [
                'ok' => true,
                'transient' => false,
                'payload' => $payload,
            ];
        }

        $errorCodes = array_map('strval', (array) ($payload['error-codes'] ?? []));

        $definitiveErrors = [
            'missing-input-response',
            'invalid-input-response',
            'timeout-or-duplicate',
        ];

        foreach ($errorCodes as $code) {
            if (in_array($code, $definitiveErrors, true)) {
                return [
                    'ok' => false,
                    'transient' => false,
                    'debug' => 'error_code_' . $code,
                ];
            }
        }

        return [
            'ok' => false,
            'transient' => true,
            'debug' => 'error_codes_' . implode(',', $errorCodes),
        ];
    }

    private static function getConfig(): array
    {
        $plugin = PluginHelper::getPlugin('system', 'viglingrecaptcha');
        $params = [];

        if ($plugin && isset($plugin->params)) {
            $decoded = json_decode((string) $plugin->params, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        return [
            'enabled' => !empty($params['enabled']),
            'site_key' => trim((string) ($params['site_key'] ?? '')),
            'secret_key' => trim((string) ($params['secret_key'] ?? '')),
            'score_threshold' => max(0.0, min(1.0, (float) ($params['score_threshold'] ?? 0.5))),
            'verify_timeout_ms' => max(1000, (int) ($params['verify_timeout_ms'] ?? 2500)),
            'fail_policy' => ((string) ($params['fail_policy'] ?? 'open')) === 'closed' ? 'closed' : 'open',
            'protect_registration' => !empty($params['protect_registration']),
            'protect_quickauth_register' => !empty($params['protect_quickauth_register']),
            'protect_booking_quickauth_register' => !empty($params['protect_booking_quickauth_register']),
            'log_level' => self::normalizeLogLevel((string) ($params['log_level'] ?? 'warning')),
        ];
    }

    private static function allow(string $reason): array
    {
        return [
            'allowed' => true,
            'reason' => $reason,
            'message' => '',
        ];
    }

    private static function deny(string $reason, string $messageKey): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'message' => self::translate($messageKey),
        ];
    }

    private static function translate(string $key): string
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load('plg_system_viglingrecaptcha', JPATH_SITE . '/plugins/system/viglingrecaptcha');
        $translated = Text::_($key);

        if ($translated === $key) {
            if ($key === 'PLG_SYSTEM_VIGLINGRECAPTCHA_ERR_VERIFY_TEMP') {
                return 'Временная ошибка проверки безопасности. Повторите попытку позже.';
            }

            return 'Подтвердите, что вы не робот';
        }

        return $translated;
    }

    private static function normalizeLogLevel(string $value): string
    {
        if ($value === 'debug' || $value === 'error') {
            return $value;
        }

        return 'warning';
    }

    private static function log(string $level, string $message): void
    {
        $config = self::getConfig();

        $order = ['debug' => 0, 'warning' => 1, 'error' => 2];
        $current = $order[$config['log_level']] ?? 1;
        $incoming = $order[$level] ?? 1;

        if ($incoming < $current) {
            return;
        }

        $logLevel = Log::WARNING;

        if ($level === 'debug') {
            $logLevel = Log::DEBUG;
        }

        if ($level === 'error') {
            $logLevel = Log::ERROR;
        }

        Log::add($message, $logLevel, 'viglingrecaptcha');
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host);

        $slashPos = strpos($host, '/');
        if ($slashPos !== false) {
            $host = substr($host, 0, $slashPos);
        }

        $colonPos = strpos($host, ':');
        if ($colonPos !== false) {
            $host = substr($host, 0, $colonPos);
        }

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }

    private static function isChallengeRecent(string $challengeTs): bool
    {
        if ($challengeTs === '') {
            return false;
        }

        try {
            $challenge = new \DateTimeImmutable($challengeTs);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $age = $now->getTimestamp() - $challenge->getTimestamp();

            return $age >= 0 && $age <= 120;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

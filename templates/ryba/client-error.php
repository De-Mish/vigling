<?php
/**
 * Accepts browser JS error reports. No personal data should be stored.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	echo '{"ok":false}';
	exit;
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$originHost = $origin !== '' ? (string) (parse_url($origin, PHP_URL_HOST) ?: '') : '';
if ($originHost !== '' && strcasecmp($originHost, $host) !== 0) {
	http_response_code(403);
	echo '{"ok":false}';
	exit;
}

$raw = (string) file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
	echo '{"ok":false}';
	exit;
}

$redact = static function (string $value): string {
	$value = preg_replace('/password[^=\s]*=[^\s&]*/i', 'password=***', $value) ?? $value;
	$value = preg_replace('/\+7[\d\s\-()]{8,}/', '+7***', $value) ?? $value;
	return substr($value, 0, 2000);
};

$message = $redact(trim((string) ($data['message'] ?? '')));
$url = $redact(trim((string) ($data['url'] ?? '')));
$stack = $redact(trim((string) ($data['stack'] ?? '')));
if ($message === '') {
	echo '{"ok":true}';
	exit;
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$key = hash('sha256', $message . '|' . $url . '|' . $ip);
$cookieName = 'vg_jserr';
if ((string) ($_COOKIE[$cookieName] ?? '') === $key) {
	echo '{"ok":true,"throttled":true}';
	exit;
}
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie($cookieName, $key, [
	'expires' => time() + 86400,
	'path' => '/',
	'secure' => $secure,
	'httponly' => true,
	'samesite' => 'Lax',
]);

$siteRoot = dirname(__DIR__, 2);
$logDir = $siteRoot . '/administrator/logs';
if (!is_dir($logDir) || !is_writable($logDir)) {
	$logDir = sys_get_temp_dir();
}

$line = date('c') . "\t" . json_encode([
	'message' => $message,
	'url' => $url,
	'stack' => $stack,
	'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
@file_put_contents($logDir . '/client-js.log', $line, FILE_APPEND | LOCK_EX);

echo '{"ok":true}';

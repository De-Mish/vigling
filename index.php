<?php

/**
 * @package    Joomla.Site
 *
 * @copyright  (C) 2005 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// NOTE: This file should remain compatible with PHP 5.2 to allow us to run our PHP minimum check and show a friendly error message

// Define the application's minimum supported PHP version as a constant so it can be referenced within the application.
define('JOOMLA_MINIMUM_PHP', '8.3.0');

if (version_compare(PHP_VERSION, JOOMLA_MINIMUM_PHP, '<')) {
    die(
        str_replace(
            '{{phpversion}}',
            JOOMLA_MINIMUM_PHP,
            file_get_contents(dirname(__FILE__) . '/includes/incompatible.html')
        )
    );
}

/**
 * Constant that is checked in included files to prevent direct access.
 * define() is used rather than "const" to not error for PHP 5.2 and lower
 */
define('_JEXEC', 1);

// Load global path definitions
if (file_exists(__DIR__ . '/defines.php')) {
    include_once __DIR__ . '/defines.php';
}

require_once __DIR__ . '/includes/defines.php';

// Check the existence of an update-extraction config file
if (
    !empty($_GET['jautoupdate'])
    && is_file(JPATH_ADMINISTRATOR . '/components/com_joomlaupdate/update.php')
) {
    // Load extraction script and...
    require_once JPATH_ADMINISTRATOR . '/components/com_joomlaupdate/extract.php';

    // ... die
    die();
}

// Run the application - All executable code should be triggered through this file
if (PHP_SAPI !== 'cli') {
    $viglingForwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $viglingHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $viglingForwardedProto === 'https'
        || (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    );
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy-Report-Only: default-src 'self' https: data: blob: 'unsafe-inline' 'unsafe-eval'; frame-ancestors 'self'");
        if ($viglingHttps) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
    register_shutdown_function(static function () {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    });
}
require_once __DIR__ . '/includes/app.php';

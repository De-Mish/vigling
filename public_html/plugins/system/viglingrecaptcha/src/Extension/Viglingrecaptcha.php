<?php

namespace Joomla\Plugin\System\Viglingrecaptcha\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Plugin\System\Viglingrecaptcha\Service\RecaptchaVerifier;

final class Viglingrecaptcha extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeCompileHead' => 'onBeforeCompileHead',
        ];
    }

    public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
    {
        $this->ensureVerifierLoaded();

        $app = $event->getApplication();
        if (!is_object($app)) {
            try {
                $app = Factory::getApplication();
            } catch (\Throwable $e) {
                return;
            }
        }

        if (!is_object($app) || !method_exists($app, 'isClient') || $app->isClient('administrator')) {
            return;
        }

        if (!$this->shouldInjectForCurrentRequest()) {
            return;
        }

        $config = RecaptchaVerifier::getFrontendConfig();

        if (!$config['enabled']) {
            return;
        }

        $doc = $app->getDocument();

        $siteKey = (string) $config['site_key'];
        $doc->addScript('https://www.google.com/recaptcha/api.js?render=' . rawurlencode($siteKey), ['defer' => true], ['crossorigin' => 'anonymous']);

        $script = <<<JS
(function () {
    if (window.ViglingRecaptcha && typeof window.ViglingRecaptcha.getToken === 'function') {
        return;
    }

    var enabled = true;
    var siteKey = %s;

    function unavailableError() {
        return new Error('reCAPTCHA is not loaded');
    }

    window.ViglingRecaptcha = {
        isEnabled: function () {
            return enabled;
        },
        getToken: function (action) {
            if (!enabled) {
                return Promise.resolve('');
            }

            return new Promise(function (resolve, reject) {
                if (typeof window.grecaptcha === 'undefined' || typeof window.grecaptcha.ready !== 'function') {
                    reject(unavailableError());
                    return;
                }

                window.grecaptcha.ready(function () {
                    try {
                        window.grecaptcha.execute(siteKey, { action: action || 'submit' })
                            .then(function (token) {
                                resolve(token || '');
                            })
                            .catch(function (error) {
                                reject(error || unavailableError());
                            });
                    } catch (error) {
                        reject(error || unavailableError());
                    }
                });
            });
        }
    };
})();
JS;

        $doc->addScriptDeclaration(sprintf($script, json_encode($siteKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    private function shouldInjectForCurrentRequest(): bool
    {
        $app = null;
        try {
            $app = $this->getApplication();
        } catch (\Throwable $e) {
            $app = null;
        }
        if (!is_object($app)) {
            try {
                $app = Factory::getApplication();
            } catch (\Throwable $e) {
                return false;
            }
        }
        if (!is_object($app) || !method_exists($app, 'getInput')) {
            return false;
        }
        $input = $app->getInput();
        $option = $input->getCmd('option', '');
        $view = $input->getCmd('view', '');

        if (RecaptchaVerifier::isContextProtected('registration') && $option === 'com_users' && $view === 'registration') {
            return true;
        }

        if (!method_exists($app, 'getIdentity') || !$app->getIdentity()->guest) {
            return false;
        }

        if (RecaptchaVerifier::isContextProtected('quickauth_register')) {
            return true;
        }

        return RecaptchaVerifier::isContextProtected('booking_quickauth_register')
            && $option === 'com_users'
            && $view === 'profile'
            && (int) $input->getInt('user_id', 0) > 0
            && trim((string) Uri::getInstance()->getPath()) !== '';
    }

    private function ensureVerifierLoaded(): void
    {
        if (class_exists(RecaptchaVerifier::class)) {
            return;
        }

        $serviceFile = __DIR__ . '/../Service/RecaptchaVerifier.php';
        if (is_file($serviceFile)) {
            require_once $serviceFile;
        }
    }
}

<?php

/**
 * @package     Joomla.Site
 * @subpackage  com_users
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Users\Site\Controller;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Registration controller class for Users.
 *
 * @since  1.6
 */
class UserController extends BaseController
{
    /**
     * Method to log in a user.
     *
     * @return  void
     *
     * @since   1.6
     */
    public function login()
    {
        $this->checkToken('post');

        $input = $this->input->getInputForRequestMethod();

        // Populate the data array:
        $data = [];

        $data['return']    = base64_decode($input->get('return', '', 'BASE64'));
        $data['username']  = $input->get('username', '', 'USERNAME');
        $data['password']  = $input->get('password', '', 'RAW');
        $data['secretkey'] = $input->get('secretkey', '', 'RAW');

        // Check for a simple menu item id
        if (is_numeric($data['return'])) {
            $itemId         = (int) $data['return'];
            $data['return'] = 'index.php?Itemid=' . $itemId;

            if (Multilanguage::isEnabled()) {
                $language = $this->getModel('Login', 'Site')->getMenuLanguage($itemId);

                if ($language !== '*') {
                    $data['return'] .= '&lang=' . $language;
                }
            }
        } elseif (!Uri::isInternal($data['return'])) {
            // Don't redirect to an external URL.
            $data['return'] = '';
        }

        // Set the return URL if empty.
        if (empty($data['return'])) {
            $data['return'] = 'index.php?option=com_users&view=profile';
        }

        // Set the return URL in the user state to allow modification by plugins
        $this->app->setUserState('users.login.form.return', $data['return']);

        // Get the log in options.
        $options             = [];
        $options['remember'] = $this->input->getBool('remember', false);
        $options['return']   = $data['return'];

        // Get the log in credentials.
        $credentials              = [];
        $credentials['username']  = $data['username'];
        $credentials['password']  = $data['password'];
        $credentials['secretkey'] = $data['secretkey'];

        $throttleName = (string) $data['username'];

        if ($throttleName !== '' && $this->loginThrottled($throttleName)) {
            $this->app->enqueueMessage('Слишком много попыток входа. Попробуйте через 15 минут.', 'error');
            $data['remember']  = (int) $options['remember'];
            $data['username']  = '';
            $data['password']  = '';
            $data['secretkey'] = '';
            $this->app->setUserState('users.login.form.data', $data);
            $this->app->redirect(Route::_('index.php?option=com_users&view=login', false));
        }

        // Perform the log in.
        if (true !== $this->app->login($credentials, $options)) {
            // Login failed !
            $this->loginThrottleHit($throttleName);
            // Clear user name, password and secret key before sending the login form back to the user.
            $data['remember']  = (int) $options['remember'];
            $data['username']  = '';
            $data['password']  = '';
            $data['secretkey'] = '';
            $this->app->setUserState('users.login.form.data', $data);
            $this->app->redirect(Route::_('index.php?option=com_users&view=login', false));
        }

        // Success
        $this->loginThrottleClear($throttleName);

        if ($options['remember']) {
            $this->app->setUserState('rememberLogin', true);
        }

        $this->app->setUserState('users.login.form.data', []);

        $this->app->redirect(Route::_($this->app->getUserState('users.login.form.return'), false));
    }

    /**
     * Login brute-force limit. Uses the same counters (files, 15 minutes, 5 failures per
     * account or per IP) as the quick-login plugin, so both login forms share one limit.
     */
    private function loginThrottled(string $username): bool
    {
        return \count($this->loginAttempts('user:' . strtolower($username))) >= 5
            || \count($this->loginAttempts('ip:' . $this->loginClientIp())) >= 5;
    }

    private function loginThrottleHit(string $username): void
    {
        if ($username === '') {
            return;
        }

        foreach (['user:' . strtolower($username), 'ip:' . $this->loginClientIp()] as $key) {
            $rows   = $this->loginAttempts($key);
            $rows[] = time();
            @file_put_contents($this->loginAttemptFile($key), json_encode(array_values($rows)), LOCK_EX);
        }
    }

    private function loginThrottleClear(string $username): void
    {
        if ($username === '') {
            return;
        }

        $file = $this->loginAttemptFile('user:' . strtolower($username));

        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function loginClientIp(): string
    {
        $ip        = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

        if ($forwarded !== '') {
            $candidate = trim(explode(',', $forwarded)[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }

        return $ip !== '' ? $ip : 'unknown';
    }

    private function loginAttemptFile(string $key): string
    {
        $dir = (\defined('JPATH_CACHE') ? JPATH_CACHE : sys_get_temp_dir()) . '/vigling-login-limit';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * @return  int[]  Timestamps of failures in the last 15 minutes.
     */
    private function loginAttempts(string $key): array
    {
        $file = $this->loginAttemptFile($key);

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        if (!\is_array($decoded)) {
            return [];
        }

        $now  = time();
        $rows = [];

        foreach ($decoded as $ts) {
            $ts = (int) $ts;

            if ($ts > 0 && ($now - $ts) < 900) {
                $rows[] = $ts;
            }
        }

        return $rows;
    }

    /**
     * Method to log out a user.
     *
     * @return  void
     *
     * @since   1.6
     */
    public function logout()
    {
        $this->checkToken('request');

        $app = $this->app;

        // Prepare the logout options.
        $options = [
            'clientid' => $app->get('shared_session', '0') ? null : 0,
        ];

        // Perform the log out.
        $error = $app->logout(null, $options);
        $input = $app->getInput()->getInputForRequestMethod();

        // Check if the log out succeeded.
        if ($error instanceof \Exception) {
            $app->redirect(Route::_('index.php?option=com_users&view=login', false));
        }

        // Get the return URL from the request and validate that it is internal.
        $return = $input->get('return', '', 'BASE64');
        $return = base64_decode($return);

        // Check for a simple menu item id
        if (is_numeric($return)) {
            $itemId = (int) $return;
            $return = 'index.php?Itemid=' . $itemId;

            if (Multilanguage::isEnabled()) {
                $language = $this->getModel('Login', 'Site')->getMenuLanguage($itemId);

                if ($language !== '*') {
                    $return .= '&lang=' . $language;
                }
            }
        } elseif (!Uri::isInternal($return)) {
            $return = '';
        }

        // In case redirect url is not set, redirect user to homepage
        if (empty($return)) {
            $return = Uri::root();
        }

        // Show a message when a user is logged out.
        $app->enqueueMessage(Text::_('COM_USERS_FRONTEND_LOGOUT_SUCCESS'), 'message');

        // Redirect the user.
        $app->redirect(Route::_($return, false));
    }

    /**
     * Method to logout directly and redirect to page.
     *
     * @return  void
     *
     * @since   3.5
     */
    public function menulogout()
    {
        // Get the ItemID of the page to redirect after logout
        $app    = $this->app;
        $active = $app->getMenu()->getActive();
        $itemid = $active ? $active->getParams()->get('logout') : 0;

        // Get the language of the page when multilang is on
        if (Multilanguage::isEnabled()) {
            if ($itemid) {
                $language = $this->getModel('Login', 'Site')->getMenuLanguage($itemid);

                // URL to redirect after logout
                $url = 'index.php?Itemid=' . $itemid . ($language !== '*' ? '&lang=' . $language : '');
            } else {
                // Logout is set to default. Get the home page ItemID
                $lang_code = $app->getInput()->cookie->getString(ApplicationHelper::getHash('language'));
                $item      = $app->getMenu()->getDefault($lang_code);
                $itemid    = $item->id;

                // Redirect to Home page after logout
                $url = 'index.php?Itemid=' . $itemid;
            }
        } else {
            // URL to redirect after logout, default page if no ItemID is set
            $url = $itemid ? 'index.php?Itemid=' . $itemid : Uri::root();
        }

        // Logout and redirect
        $this->setRedirect(Route::_('index.php?option=com_users&task=user.logout&' . Session::getFormToken() . '=1&return=' . base64_encode($url), false));
    }

    /**
     * Method to request a username reminder.
     *
     * @return  boolean
     *
     * @since   1.6
     */
    public function remind()
    {
        // Check the request token.
        $this->checkToken('post');

        $app   = $this->app;

        /** @var \Joomla\Component\Users\Site\Model\RemindModel $model */
        $model = $this->getModel('Remind', 'Site');
        $data  = $this->input->post->get('jform', [], 'array');

        // Submit the username remind request.
        $return = $model->processRemindRequest($data);

        // Check for a hard error.
        if ($return instanceof \Exception) {
            // Get the error message to display.
            $message = $app->get('error_reporting')
                ? $return->getMessage()
                : Text::_('COM_USERS_REMIND_REQUEST_ERROR');

            // Go back to the complete form.
            $this->setRedirect(Route::_('index.php?option=com_users&view=remind', false), $message, 'error');

            return false;
        }

        if ($return === false) {
            // Go back to the complete form.
            $message = Text::sprintf('COM_USERS_REMIND_REQUEST_FAILED', $model->getError());
            $this->setRedirect(Route::_('index.php?option=com_users&view=remind', false), $message, 'notice');

            return false;
        }

        // Proceed to the login form.
        $message = Text::_('COM_USERS_REMIND_REQUEST_SUCCESS');
        $this->setRedirect(Route::_('index.php?option=com_users&view=login', false), $message);

        return true;
    }

    /**
     * Method to resend a user.
     *
     * @return  void
     *
     * @since   1.6
     */
    public function resend()
    {
        // Check for request forgeries
        // $this->checkToken('post');
    }
}
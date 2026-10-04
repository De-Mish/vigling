<?php

namespace Joomla\Component\Users\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\UserFactoryAwareInterface;
use Joomla\CMS\User\UserFactoryAwareTrait;

class RegistrationController extends BaseController implements UserFactoryAwareInterface
{
    use UserFactoryAwareTrait;

    public function activate()
    {
        $user    = $this->app->getIdentity();
        $input   = $this->input;
        $uParams = ComponentHelper::getParams('com_users');

        if ($uParams->get('useractivation') != 2 && $user->id) {
            $this->setRedirect('index.php');
            return true;
        }

        if ($uParams->get('useractivation') == 0 || $uParams->get('allowUserRegistration') == 0) {
            throw new \Exception(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        /** @var \Joomla\Component\Users\Site\Model\RegistrationModel $model */
        $model = $this->getModel('Registration', 'Site');
        $token = $input->getAlnum('token');

        if ($token === null || \strlen($token) !== 32) {
            throw new \Exception(Text::_('JINVALID_TOKEN'), 403);
        }

        $userIdToActivate = $model->getUserIdFromToken($token);

        if (!$userIdToActivate) {
            $this->setMessage(Text::_('COM_USERS_ACTIVATION_TOKEN_NOT_FOUND'));
            $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));
            return false;
        }

        $userToActivate = $this->getUserFactory()->loadUserById($userIdToActivate);

        if (($uParams->get('useractivation') == 2) && $userToActivate->getParam('activate', 0)) {
            if (!$user->authorise('core.create', 'com_users') || !$user->authorise('core.manage', 'com_users')) {
                $activationUrl = 'index.php?option=com_users&task=registration.activate&token=' . $token;
                $loginUrl      = 'index.php?option=com_users&view=login&return=' . base64_encode($activationUrl);
                $message = Text::_('COM_USERS_REGISTRATION_ACL_ADMIN_ACTIVATION_PERMISSIONS');
                if ($user->guest) {
                    $message = Text::_('COM_USERS_REGISTRATION_ACL_ADMIN_ACTIVATION');
                }
                $this->setMessage($message);
                $this->setRedirect(Route::_($loginUrl, false));
                return false;
            }
        }

        $return = $model->activate($token);

        if ($return === false) {
            $this->setMessage(Text::sprintf('COM_USERS_REGISTRATION_SAVE_FAILED', $model->getError()), 'error');
            $this->setRedirect('index.php');
            return false;
        }

        $useractivation = $uParams->get('useractivation');

        if ($useractivation == 0) {
            $this->setMessage(Text::_('COM_USERS_REGISTRATION_SAVE_SUCCESS'));
            $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));
        } elseif ($useractivation == 1) {
            $this->setMessage(Text::_('COM_USERS_REGISTRATION_ACTIVATE_SUCCESS'));
            $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));
        } elseif ($return->getParam('activate')) {
            $this->setMessage(Text::_('COM_USERS_REGISTRATION_VERIFY_SUCCESS'));
            $this->setRedirect(Route::_('index.php?option=com_users&view=registration&layout=complete', false));
        } else {
            $this->setMessage(Text::_('COM_USERS_REGISTRATION_ADMINACTIVATE_SUCCESS'));
            $this->setRedirect(Route::_('index.php?option=com_users&view=registration&layout=complete', false));
        }

        return true;
    }

    public function register()
    {
        $this->checkToken();

        if (ComponentHelper::getParams('com_users')->get('allowUserRegistration') == 0) {
            $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));
            return false;
        }

        $app   = $this->app;
        /** @var \Joomla\Component\Users\Site\Model\RegistrationModel $model */
        $model = $this->getModel('Registration', 'Site');
        $requestData = $this->input->post->get('jform', [], 'array');

        if (!$this->verifyRecaptchaBeforeValidation($requestData)) {
            $app->setUserState('com_users.registration.data', $requestData);
            $this->setRedirect(Route::_('index.php?option=com_users&view=registration', false));
            return false;
        }

        $form = $model->getForm();

        if (!$form) {
            throw new \Exception($model->getError(), 500);
        }

        $data = $model->validate($form, $requestData);

        if ($data === false) {
            $errors = $model->getErrors();
            for ($i = 0, $n = \count($errors); $i < $n && $i < 3; $i++) {
                if ($errors[$i] instanceof \Exception) {
                    $app->enqueueMessage($errors[$i]->getMessage(), CMSWebApplicationInterface::MSG_ERROR);
                } else {
                    $app->enqueueMessage($errors[$i], CMSWebApplicationInterface::MSG_ERROR);
                }
            }
            $filteredData = $form->filter($requestData);
            foreach ($form->getFieldset() as $field) {
                if ($field->type === 'Calendar') {
                    $fieldName = $field->fieldname;
                    if ($field->group) {
                        if (isset($filteredData[$field->group][$fieldName])) {
                            $requestData[$field->group][$fieldName] = $filteredData[$field->group][$fieldName];
                        }
                    } else {
                        if (isset($filteredData[$fieldName])) {
                            $requestData[$fieldName] = $filteredData[$fieldName];
                        }
                    }
                }
            }
            $app->setUserState('com_users.registration.data', $requestData);
            $this->setRedirect(Route::_('index.php?option=com_users&view=registration', false));
            return false;
        }

        $data = $this->normalizeForMailTemplate($data);
        $return = $model->register($data);

        if ($return === false) {
            $app->setUserState('com_users.registration.data', $data);
            $this->setMessage($model->getError(), 'error');
            $this->setRedirect(Route::_('index.php?option=com_users&view=registration', false));
            return false;
        }

        $app->setUserState('com_users.registration.data', null);
        $this->stripMailSendWarningsFromQueue($app);

        if ($return === 'adminactivate') {
            $this->setMessage(Text::_('COM_USERS_REGISTRATION_COMPLETE_VERIFY'));
            $this->setRedirect(Route::_('index.php?option=com_users&view=registration&layout=complete', false));
        } elseif ($return === 'useractivate') {
            $this->setMessage(Text::_('COM_USERS_REGISTRATION_COMPLETE_ACTIVATE'));
            $this->setRedirect(Route::_('index.php?option=com_users&view=registration&layout=complete', false));
        } else {
            $jform = $this->input->post->get('jform', [], 'array');
            $email = isset($jform['email1']) ? trim((string) $jform['email1']) : '';
            $successMessage = 'Учетная запись создана. Теперь вы можете войти в систему, используя email и пароль, введенные при регистрации.';
            if ($email !== '') {
                $successMessage = 'Учетная запись создана. Теперь вы можете войти в систему, используя email ' . $email . ' и пароль, введенные при регистрации.';
            }
            $this->setMessage($successMessage);
            $username = isset($jform['username']) ? trim((string) $jform['username']) : '';
            $password = isset($jform['password1']) ? (string) $jform['password1'] : '';
            $redirectUrl = $this->resolveRegistrationReturnUrl();

            // Fallback to the actually saved account data in case hidden username was not posted.
            $registeredUserId = is_scalar($return) ? (int) $return : 0;
            if ($registeredUserId > 0) {
                $registeredUser = $this->getUserFactory()->loadUserById($registeredUserId);
                if ($username === '') {
                    $username = trim((string) ($registeredUser->username ?? ''));
                }
                if ($username === '' && $email !== '') {
                    $username = $email;
                }
            }

            if ($username !== '' && $password !== '') {
                $credentials = ['username' => $username, 'password' => $password];
                if (true === $this->app->login($credentials, ['remember' => true])) {
                    $this->setRedirect(Route::_($redirectUrl, false));
                } else {
                    $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));
                }
            } else {
                $this->setRedirect(Route::_('index.php?option=com_users&view=login', false));
            }
        }

        return true;
    }

    private function verifyRecaptchaBeforeValidation(array $requestData): bool
    {
        $verifierClass = $this->getRecaptchaVerifierClass();

        if ($verifierClass === null) {
            return true;
        }

        $token = '';
        if (isset($requestData['recaptcha_token']) && is_scalar($requestData['recaptcha_token'])) {
            $token = trim((string) $requestData['recaptcha_token']);
        }
        if ($token === '') {
            $token = trim((string) $this->input->post->getString('recaptcha_token', ''));
        }

        $action = '';
        if (isset($requestData['recaptcha_action']) && is_scalar($requestData['recaptcha_action'])) {
            $action = trim((string) $requestData['recaptcha_action']);
        }
        if ($action === '') {
            $action = trim((string) $this->input->post->getString('recaptcha_action', ''));
        }

        $expectedAction = $verifierClass::ACTION_REGISTRATION_SUBMIT;
        if ($action === '') {
            $action = $expectedAction;
        }

        $result = $verifierClass::verify(
            $token,
            $expectedAction,
            'registration',
            (string) $this->input->server->getString('REMOTE_ADDR', '')
        );

        if (!empty($result['allowed'])) {
            return true;
        }

        $message = trim((string) ($result['message'] ?? ''));
        if ($message === '') {
            $message = 'Подтвердите, что вы не робот';
        }
        $this->setMessage($message, 'error');

        return false;
    }

    private function getRecaptchaVerifierClass(): ?string
    {
        $class = '\\Joomla\\Plugin\\System\\Viglingrecaptcha\\Service\\RecaptchaVerifier';

        if (class_exists($class)) {
            return $class;
        }

        $servicePath = JPATH_SITE . '/plugins/system/viglingrecaptcha/src/Service/RecaptchaVerifier.php';
        if (!is_file($servicePath)) {
            return null;
        }

        require_once $servicePath;

        return class_exists($class) ? $class : null;
    }

    private function resolveRegistrationReturnUrl(): string
    {
        $return = $this->input->post->get('return', $this->input->get('return', '', 'RAW'), 'RAW');
        $return = is_string($return) ? trim($return) : '';
        if ($return !== '') {
            $decoded = @base64_decode($return, true);
            if ($decoded !== false && trim($decoded) !== '') {
                $return = trim($decoded);
            }
        }
        if ($return === '') {
            return 'index.php?option=com_users&view=profile';
        }
        if (strpos($return, '/') === 0 || strpos($return, 'index.php') === 0) {
            return $return;
        }
        if (strpos($return, 'http') === 0 && \Joomla\CMS\Uri\Uri::isInternal($return)) {
            return $return;
        }
        if (strpos($return, 'http') !== 0) {
            return $return;
        }
        return 'index.php?option=com_users&view=profile';
    }

    private function stripMailSendWarningsFromQueue($app): void
    {
        $queue = $app->getMessageQueue(true);

        if (!is_array($queue) || $queue === []) {
            return;
        }

        foreach ($queue as $message) {
            $text = strtolower((string) ($message['message'] ?? ''));
            $type = (string) ($message['type'] ?? 'message');

            // Hide noisy mail transport warnings from registration flow in local/dev.
            if (
                str_contains($text, 'при отправке письма') ||
                str_contains($text, 'could not instantiate mail function') ||
                str_contains($text, 'jerror_sending_email')
            ) {
                continue;
            }

            $app->enqueueMessage((string) ($message['message'] ?? ''), $type);
        }
    }

    private function normalizeForMailTemplate(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = $this->normalizeValue($value);
        }

        return $data;
    }

    private function normalizeValue($value)
    {
        if (is_null($value) || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalizeValue($v);
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            return $encoded !== false ? $encoded : (string) get_class($value);
        }

        return (string) $value;
    }
}

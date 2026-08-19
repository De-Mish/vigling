<?php

namespace Joomla\Plugin\User\Registrationtype\Extension;

\defined('_JEXEC') or die;

use Joomla\Filesystem\Folder;
use Joomla\CMS\Event\User\AfterSaveEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

final class Registrationtype extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    private const GROUP_MASTER = 3;
    private const MAX_UPLOAD_SIZE_BYTES = 26214400; // 25MB
    private const MAX_PORTFOLIO_FILES = 10;

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onUserAfterSave'      => ['onUserAfterSave', 100],
        ];
    }

    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $form = $event->getForm();
        if (!($form instanceof Form) || $form->getName() !== 'com_users.registration') {
            return;
        }
        $this->loadLanguage();
        FormHelper::addFormPath(JPATH_PLUGINS . '/user/registrationtype/forms');
        $form->loadFile('registration', true);
    }

    public function onUserAfterSave(AfterSaveEvent $event): void
    {
        $user   = $event->getUser();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || !$event->getSavingResult()) {
            return;
        }
        $app = $this->getApplication();
        if ($app->isClient('administrator')) {
            return;
        }

        // Ensure email-verification envelope exists for every newly created site user,
        // regardless of which registration flow/controller created the account.
        if ($event->getIsNew()) {
            $this->createEmailVerificationEnvelope($user);
        }

        $input = $app->getInput();
        $method = strtoupper($input->getMethod() ?? '');
        $option = $input->getCmd('option');
        $task   = $input->getCmd('task');

        if ($method !== 'POST') {
            return;
        }
        $isRegistration = $option === 'com_users' && ($task === 'registration.register' || $task === 'register');
        $isProfileSave = $option === 'com_users' && $task === 'profile.save';
        if (!$isRegistration && $option === 'com_ajax' && $input->post->get('action') === 'register' && \is_array($input->post->get('jform', []))) {
            $isRegistration = true;
        }
        if (!$isRegistration && !$isProfileSave) {
            return;
        }

        $jform = $input->post->get('jform', [], 'array');
        $registrationType = isset($jform['registration_type']) ? (string) $jform['registration_type'] : 'client';
        $deletedPortfolio = [];
        if ($isProfileSave && isset($jform['portfolio_deleted']) && is_scalar($jform['portfolio_deleted'])) {
            $deletedPortfolio = array_values(array_unique(array_filter(array_map(static function ($part) {
                return basename(trim((string) $part));
            }, explode(',', (string) $jform['portfolio_deleted'])))));
        }
        $existingPortfolio = $isProfileSave ? $this->getExistingPortfolioFiles($userId) : [];
        $uploadedFields = $this->processRegistrationUploads($userId, $existingPortfolio, $deletedPortfolio);

        if (!empty($uploadedFields['avatar'])) {
            $jform['avatar'] = $uploadedFields['avatar'];
        }

        if (array_key_exists('portfolio_field', $uploadedFields)) {
            $jform['portfolio_field'] = $uploadedFields['portfolio_field'];
        }

        $profile = isset($jform['profile']) && \is_array($jform['profile']) ? $jform['profile'] : [];

        $this->saveRegistrationToCustomFields($userId, $jform, $profile);

        $db = $this->getDatabase();
        if (!empty($profile)) {
            $profileKeys = array_keys($profile);
            $keysQuoted = array_map(function ($k) use ($db) {
                return $db->quote('profile.' . $k);
            }, $profileKeys);
            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName('#__user_profiles'))
                        ->where($db->quoteName('user_id') . ' = :uid')
                        ->where($db->quoteName('profile_key') . ' IN (' . implode(',', $keysQuoted) . ')')
                        ->bind(':uid', $userId, ParameterType::INTEGER)
                )->execute();
            } catch (\Throwable $e) {
            }
            $orderingQuery = $db->getQuery(true)
                ->select('COALESCE(MAX(' . $db->quoteName('ordering') . '), 0)')
                ->from($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = :uid')
                ->bind(':uid', $userId, ParameterType::INTEGER);
            $db->setQuery($orderingQuery);
            $order = (int) $db->loadResult();
            foreach ($profile as $k => $v) {
                $order++;
                $key = 'profile.' . $k;
                try {
                    $db->setQuery(
                        $db->getQuery(true)
                            ->insert($db->quoteName('#__user_profiles'))
                            ->columns([$db->quoteName('user_id'), $db->quoteName('profile_key'), $db->quoteName('profile_value'), $db->quoteName('ordering')])
                            ->values((int) $userId . ',' . $db->quote($key) . ',' . $db->quote(json_encode($v)) . ',' . $order)
                    )->execute();
                } catch (\Throwable $e) {
                }
            }
        }

        if (!$isRegistration || ($registrationType !== 'master' && $registrationType !== 'zatochka_remont')) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__user_usergroup_map'))
                ->where($db->quoteName('user_id') . ' = :uid')
                ->bind(':uid', $userId, ParameterType::INTEGER)
        )->execute();

        $db->setQuery(
            $db->getQuery(true)
                ->insert($db->quoteName('#__user_usergroup_map'))
                ->columns([$db->quoteName('user_id'), $db->quoteName('group_id')])
                ->values((int) $userId . ', ' . self::GROUP_MASTER)
        )->execute();
    }

    private function saveRegistrationToCustomFields(int $userId, array $jform, array $profile): void
    {
        $isMasterValue = $this->resolveMasterTypeForSave($userId, $jform);
        $valuesByCfName = [
            'firstname' => $this->extractStringValue($jform, 'name'),
            'lastname' => $this->extractStringValue($profile, 'lastname'),
            'secondname' => $this->extractStringValue($profile, 'patronymic'),
            'telefon' => $this->extractStringValue($profile, 'phone'),
            'avatar' => $this->extractStringValue($jform, 'avatar'),
            'sity' => $this->extractStringValue($profile, 'city'),
            'area' => $this->extractStringValue($profile, 'region'),
            'street' => $this->extractStringValue($profile, 'address1'),
            'house_number' => $this->extractStringValue($profile, 'address2'),
            'link' => $this->extractStringValue($profile, 'website'),
            'o_sebe' => $this->extractStringValue($profile, 'aboutme'),
        ];
        if ($isMasterValue !== '') {
            $valuesByCfName['is_master'] = $isMasterValue;
        }

        if (isset($jform['vyberite_spetsialnos'])) {
            $valuesByCfName['vyberite_spetsialnos'] = $this->encodeJsonValue($jform['vyberite_spetsialnos']);
        }
        if (isset($jform['work_day'])) {
            $valuesByCfName['work_day'] = $this->encodeJsonValue($jform['work_day']);
        }
        if (isset($jform['work_from'])) {
            $valuesByCfName['work_from'] = $this->extractStringValue($jform, 'work_from');
        }
        if (isset($jform['work_to'])) {
            $valuesByCfName['work_to'] = $this->extractStringValue($jform, 'work_to');
        }
        if (isset($jform['prices'])) {
            $valuesByCfName['prices'] = $this->extractStringValue($jform, 'prices');
        }
        if (isset($jform['stock_prices'])) {
            $valuesByCfName['stock_prices'] = $this->extractStringValue($jform, 'stock_prices');
        }
        if (isset($jform['portfolio_field'])) {
            $valuesByCfName['portfolio_field'] = $this->extractStringValue($jform, 'portfolio_field');
        }

        $valuesByCfName = array_filter($valuesByCfName, static function ($v) {
            return $v !== '';
        });
        if (empty($valuesByCfName)) {
            return;
        }
        $db = $this->getDatabase();
        $names = array_keys($valuesByCfName);
        $namesQuoted = array_map([$db, 'quote'], $names);
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id') . ',' . $db->quoteName('name'))
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
                ->where($db->quoteName('name') . ' IN (' . implode(',', $namesQuoted) . ')')
        );
        $rows = $db->loadObjectList() ?: [];
        $fieldIdByName = [];
        foreach ($rows as $r) {
            $fieldIdByName[$r->name] = (int) $r->id;
        }
        foreach ($valuesByCfName as $cfName => $value) {
            $fieldId = $fieldIdByName[$cfName] ?? 0;
            if ($fieldId <= 0) {
                continue;
            }
            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName('#__fields_values'))
                        ->where($db->quoteName('field_id') . ' = :fid')
                        ->where($db->quoteName('item_id') . ' = :iid')
                        ->bind(':fid', $fieldId, ParameterType::INTEGER)
                        ->bind(':iid', $userId, ParameterType::INTEGER)
                )->execute();
                $db->setQuery(
                    $db->getQuery(true)
                        ->insert($db->quoteName('#__fields_values'))
                        ->columns([$db->quoteName('field_id'), $db->quoteName('item_id'), $db->quoteName('value')])
                        ->values((int) $fieldId . ',' . (int) $userId . ',' . $db->quote($value))
                )->execute();
            } catch (\Throwable $e) {
            }
        }
    }

    private function extractStringValue(array $source, string $key): string
    {
        if (!isset($source[$key])) {
            return '';
        }

        $value = $source[$key];

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function encodeJsonValue($value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function processRegistrationUploads(int $userId, array $existingPortfolio = [], array $deletedPortfolio = []): array
    {
        $result = [
            'avatar' => '',
            'portfolio_field' => '',
        ];

        if (empty($_FILES['jform']) || !is_array($_FILES['jform'])) {
            return $result;
        }

        $files = $_FILES['jform'];
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        $avatarName = $this->getUploadScalar($files, 'name', 'upload_avatar');
        $avatarTmp = $this->getUploadScalar($files, 'tmp_name', 'upload_avatar');
        $avatarErr = (int) $this->getUploadScalar($files, 'error', 'upload_avatar');
        $avatarSize = (int) $this->getUploadScalar($files, 'size', 'upload_avatar');

        if (
            $avatarName !== ''
            && $avatarTmp !== ''
            && $avatarErr === \UPLOAD_ERR_OK
            && $avatarSize > 0
            && $avatarSize <= self::MAX_UPLOAD_SIZE_BYTES
            && is_uploaded_file($avatarTmp)
        ) {
            $ext = strtolower(pathinfo($avatarName, \PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt, true)) {
                $avatarDir = JPATH_ROOT . '/images/profiler';
                Folder::create($avatarDir);

                $avatarFile = 'avatar_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $avatarPath = $avatarDir . '/' . $avatarFile;

                if (@move_uploaded_file($avatarTmp, $avatarPath)) {
                    $result['avatar'] = 'images/profiler/' . $avatarFile;
                }
            }
        }

        $portfolioNames = $this->getUploadArray($files, 'name', 'upload_portfolio_field');
        $portfolioTmpNames = $this->getUploadArray($files, 'tmp_name', 'upload_portfolio_field');
        $portfolioErrors = $this->getUploadArray($files, 'error', 'upload_portfolio_field');
        $portfolioSizes = $this->getUploadArray($files, 'size', 'upload_portfolio_field');

        if ($portfolioNames !== [] && $portfolioTmpNames !== [] && $portfolioErrors !== []) {
            $portfolioDir = JPATH_ROOT . '/images/portfolio';
            Folder::create($portfolioDir);

            $saved = [];
            foreach ($existingPortfolio as $existingFile) {
                $existingFile = basename(trim((string) $existingFile));
                if ($existingFile === '' || in_array($existingFile, $deletedPortfolio, true)) {
                    continue;
                }
                $saved[] = $existingFile;
            }
            $saved = array_values(array_unique($saved));
            foreach ($portfolioNames as $idx => $name) {
                if (count($saved) >= self::MAX_PORTFOLIO_FILES) {
                    break;
                }

                $name = is_scalar($name) ? trim((string) $name) : '';
                $tmp = isset($portfolioTmpNames[$idx]) && is_scalar($portfolioTmpNames[$idx]) ? (string) $portfolioTmpNames[$idx] : '';
                $err = isset($portfolioErrors[$idx]) ? (int) $portfolioErrors[$idx] : \UPLOAD_ERR_NO_FILE;
                $size = isset($portfolioSizes[$idx]) ? (int) $portfolioSizes[$idx] : 0;

                if (
                    $name === ''
                    || $tmp === ''
                    || $err !== \UPLOAD_ERR_OK
                    || $size <= 0
                    || $size > self::MAX_UPLOAD_SIZE_BYTES
                    || !is_uploaded_file($tmp)
                ) {
                    continue;
                }

                $ext = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    continue;
                }

                $portfolioFile = 'portfolio_field' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $portfolioPath = $portfolioDir . '/' . $portfolioFile;

                if (@move_uploaded_file($tmp, $portfolioPath)) {
                    $saved[] = $portfolioFile;
                }
            }

            $saved = array_values(array_unique($saved));
            $result['portfolio_field'] = $saved !== [] ? json_encode($saved, JSON_UNESCAPED_UNICODE) : '';
        } elseif ($existingPortfolio !== []) {
            $saved = [];
            foreach ($existingPortfolio as $existingFile) {
                $existingFile = basename(trim((string) $existingFile));
                if ($existingFile === '' || in_array($existingFile, $deletedPortfolio, true)) {
                    continue;
                }
                $saved[] = $existingFile;
            }
            $saved = array_values(array_unique($saved));
            $result['portfolio_field'] = $saved !== [] ? json_encode($saved, JSON_UNESCAPED_UNICODE) : '';
        }

        return $result;
    }

    private function getUploadScalar(array $files, string $bucket, string $key): string
    {
        if (!isset($files[$bucket]) || !is_array($files[$bucket]) || !array_key_exists($key, $files[$bucket])) {
            return '';
        }

        $value = $files[$bucket][$key];
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function getUploadArray(array $files, string $bucket, string $key): array
    {
        if (!isset($files[$bucket]) || !is_array($files[$bucket]) || !isset($files[$bucket][$key]) || !is_array($files[$bucket][$key])) {
            return [];
        }

        return $files[$bucket][$key];
    }

    private function resolveMasterTypeForSave(int $userId, array $jform): string
    {
        if (isset($jform['registration_type']) && is_scalar($jform['registration_type'])) {
            $registrationType = trim((string) $jform['registration_type']);
            if ($registrationType === 'master') {
                return '1';
            }
            if ($registrationType === 'zatochka_remont') {
                return '2';
            }
            return '0';
        }

        if (isset($jform['is_master']) && is_scalar($jform['is_master'])) {
            return trim((string) $jform['is_master']);
        }

        return $this->getCurrentCustomFieldValue($userId, 'is_master');
    }

    private function getCurrentCustomFieldValue(int $userId, string $fieldName): string
    {
        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->select('fv.value')
                ->from($db->quoteName('#__fields_values', 'fv'))
                ->innerJoin($db->quoteName('#__fields', 'f') . ' ON f.id = fv.field_id')
                ->where('f.context = ' . $db->quote('com_users.user'))
                ->where('f.name = ' . $db->quote($fieldName))
                ->where('fv.item_id = ' . (int) $userId)
                ->setLimit(1)
        );
        $value = $db->loadResult();

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function getExistingPortfolioFiles(int $userId): array
    {
        $raw = $this->getCurrentCustomFieldValue($userId, 'portfolio_field');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $files = [];
        if (is_array($decoded)) {
            $iter = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($decoded));
            foreach ($iter as $item) {
                if (!is_scalar($item)) {
                    continue;
                }
                $file = basename(trim((string) $item));
                if ($file !== '') {
                    $files[] = $file;
                }
            }
        } else {
            $file = basename(trim((string) $raw));
            if ($file !== '') {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private function createEmailVerificationEnvelope(array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = trim((string) ($user['email'] ?? ''));

        if ($userId <= 0 || $email === '') {
            return;
        }

        $servicePath = JPATH_SITE . '/plugins/system/emailverification/src/Service/EmailVerificationService.php';

        if (!is_file($servicePath)) {
            return;
        }

        require_once $servicePath;

        if (!class_exists('\\Joomla\\Plugin\\System\\Emailverification\\Service\\EmailVerificationService')) {
            return;
        }

        $this->getApplication()->getLanguage()->load('plg_system_emailverification', JPATH_SITE . '/plugins/system/emailverification');

        try {
            $serviceClass = '\\Joomla\\Plugin\\System\\Emailverification\\Service\\EmailVerificationService';
            $rawToken = $serviceClass::issueForNewUser(
                $this->getDatabase(),
                $userId,
                $email,
                (string) ($user['registerDate'] ?? '')
            );

            if (!$rawToken) {
                return;
            }

            $sent = $serviceClass::sendVerificationEmail(
                $userId,
                trim((string) ($user['name'] ?? '')),
                $email,
                $rawToken
            );

            if (!$sent) {
                Log::add(
                    'registrationtype_emailverification_send_failed user_id=' . $userId . ' email=' . $email,
                    Log::WARNING,
                    'emailverification'
                );
            }
        } catch (\Throwable $e) {
            Log::add(
                'registrationtype_emailverification_exception user_id=' . $userId . ' email=' . $email . ' msg=' . $e->getMessage(),
                Log::WARNING,
                'emailverification'
            );
        }
    }
}

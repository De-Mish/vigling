<?php

namespace Joomla\Plugin\User\Vigling\Extension;

use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\User\AfterSaveEvent;
use Joomla\CMS\Event\User\BeforeSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\Folder;
use Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper;
use Joomla\Plugin\User\Vigling\Service\UserCoursesService;
use Joomla\Plugin\User\Vigling\Service\UserSearchesService;
use Joomla\Plugin\User\Vigling\Service\UserServicesService;

\defined('_JEXEC') or die;

final class Vigling extends CMSPlugin implements SubscriberInterface
{
    private const ENCODED_FIELDS = ['prices', 'stock_prices', 'work_day', 'vyberite_spetsialnos'];
    private const VIGLING_MARKER = "--- Расшифровано плагином Vigling ---\n";
    private const MAX_UPLOAD_SIZE_BYTES = 26214400; // 25MB
    private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm' => ['onContentPrepareForm', 100],
            'onUserBeforeSave' => ['onUserBeforeSave', 50],
            // Run after Joomla's custom-fields save path, then persist schedule from the raw profile POST.
            'onUserAfterSave' => ['onUserAfterSave', -100],
        ];
    }

    public function onUserBeforeSave(BeforeSaveEvent $event): void
    {
        try {
            $app = Factory::getApplication();
        } catch (\Throwable $e) {
            return;
        }

        if (!$app->isClient('site')) {
            return;
        }

        $user = $event->getUser();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            return;
        }

        $input = $app->getInput();
        $option = $input->getCmd('option');
        $task = $input->post->getCmd('task');
        if ($option !== 'com_users' || !\in_array($task, ['registration.register'], true)) {
            return;
        }

        $consent = trim((string) $input->post->get('privacy_consent', '', 'string'));
        if ($consent === '1') {
            return;
        }

        throw new \InvalidArgumentException(
            'Для завершения регистрации необходимо принять условия Политики конфиденциальности'
        );
    }

    public function onUserAfterSave(AfterSaveEvent $event): void
    {
        if (!$event->getSavingResult()) {
            return;
        }

        $user = $event->getUser();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $this->saveScheduleFieldsFromPost($userId);
        $this->saveSocialLinkFieldsFromPost($userId);
        $this->validateVkProfileWebsite($userId);

        $jform = $this->getPostedJform();
        $newPricesPayload = $this->getJformString($jform, 'vigling_services_payload');
        $newStockPayload = $this->getJformString($jform, 'vigling_stock_services_payload');
        $newCoursesPayload = $this->getJformString($jform, 'vigling_courses_payload');
        $newSearchesPayload = $this->getJformString($jform, 'vigling_searches_payload');
        if ($newPricesPayload === null && $newStockPayload === null && $newCoursesPayload === null && $newSearchesPayload === null) {
            return;
        }

        $hasPrices = $newPricesPayload !== null;
        $hasStockPrices = $newStockPayload !== null;
        $hasCourses = $newCoursesPayload !== null;
        $hasSearches = $newSearchesPayload !== null;
        if (!$hasPrices && !$hasStockPrices && !$hasCourses && !$hasSearches) {
            return;
        }

        $db = null;
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->transactionStart();

            if ($hasPrices) {
                UserServicesService::syncUserServicePayloadToTable($db, $userId, (string) $newPricesPayload, 'prices', '#__vigling_user_services');
            }

            if ($hasStockPrices) {
                UserServicesService::syncUserServicePayloadToTable($db, $userId, (string) $newStockPayload, 'stock_prices', '#__vigling_user_stock_services');
            }

            if ($hasCourses) {
                $newCoursesPayload = $this->mergeCourseMediaUploads($userId, (string) $newCoursesPayload);
                UserCoursesService::syncUserCoursesPayloadToTables($db, $userId, (string) $newCoursesPayload);
            }

            if ($hasSearches) {
                $newSearchesPayload = $this->mergeSearchMediaUploads($userId, (string) $newSearchesPayload);
                UserSearchesService::syncUserSearchesPayloadToTables($db, $userId, (string) $newSearchesPayload);
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            try {
                if ($db instanceof DatabaseInterface) {
                    $db->transactionRollback();
                }
            } catch (\Throwable $ignored) {
            }

            try {
                $message = trim((string) $e->getMessage());
                Factory::getApplication()->enqueueMessage(
                    $message !== '' ? $message : 'Не удалось сохранить изменения профиля',
                    'error'
                );
            } catch (\Throwable $ignored) {
            }

            Log::add(
                'Vigling user services sync failed for user_id=' . $userId . ': ' . $e->getMessage(),
                Log::ERROR,
                'plg_user_vigling'
            );
        }
    }

    private function saveScheduleFieldsFromPost(int $userId): void
    {
        $rawComFields = isset($_POST['jform']['com_fields']) && \is_array($_POST['jform']['com_fields'])
            ? $_POST['jform']['com_fields']
            : [];
        $rawScheduleDays = isset($_POST['jform']['vigling_schedule_days']) && \is_array($_POST['jform']['vigling_schedule_days'])
            ? $_POST['jform']['vigling_schedule_days']
            : null;

        if (empty($rawComFields) && $rawScheduleDays === null) {
            return;
        }

        $toSave = [];

        if ($rawScheduleDays !== null) {
            $clean = array_values(array_unique(array_filter(
                array_map('intval', $rawScheduleDays),
                static fn (int $d): bool => $d >= 1 && $d <= 7
            )));
            sort($clean);
            $toSave['work_day'] = json_encode(array_map('strval', $clean));
        } elseif (\array_key_exists('work_day', $rawComFields)) {
            $val = trim((string) ($rawComFields['work_day'] ?? ''));
            if ($val !== '') {
                $decoded = json_decode($val, true);
                if (\is_array($decoded)) {
                    $clean = array_values(array_unique(array_filter(
                        array_map('intval', $decoded),
                        static fn (int $d): bool => $d >= 1 && $d <= 7
                    )));
                    sort($clean);
                    $toSave['work_day'] = json_encode(array_map('strval', $clean));
                }
            } else {
                $toSave['work_day'] = '';
            }
        }

        foreach (['work_from', 'work_to'] as $fname) {
            if (\array_key_exists($fname, $rawComFields)) {
                $val = trim((string) ($rawComFields[$fname] ?? ''));
                if ($val === '' || preg_match('/^\d{2}:\d{2}$/', $val)) {
                    $toSave[$fname] = $val;
                }
            }
        }

        if (empty($toSave)) {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $q = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('name')])
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
                ->where($db->quoteName('name') . ' IN (' . implode(',', array_map([$db, 'quote'], array_keys($toSave))) . ')');
            $db->setQuery($q);
            $fieldRows = $db->loadObjectList('name') ?: [];

            foreach ($toSave as $fieldName => $fieldValue) {
                if (!isset($fieldRows[$fieldName])) {
                    continue;
                }
                $fieldId = (int) $fieldRows[$fieldName]->id;

                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName('#__fields_values'))
                        ->where($db->quoteName('field_id') . ' = ' . $fieldId)
                        ->where($db->quoteName('item_id') . ' = ' . $userId)
                )->execute();

                if ($fieldValue !== '') {
                    $db->setQuery(
                        $db->getQuery(true)
                            ->insert($db->quoteName('#__fields_values'))
                            ->columns([$db->quoteName('field_id'), $db->quoteName('item_id'), $db->quoteName('value')])
                            ->values($fieldId . ', ' . $userId . ', ' . $db->quote($fieldValue))
                    )->execute();
                }
            }
        } catch (\Throwable $e) {
            Log::add(
                'Vigling schedule fields save failed for user_id=' . $userId . ': ' . $e->getMessage(),
                Log::ERROR,
                'plg_user_vigling'
            );
        }
    }

    private function saveSocialLinkFieldsFromPost(int $userId): void
    {
        $rawComFields = isset($_POST['jform']['com_fields']) && \is_array($_POST['jform']['com_fields'])
            ? $_POST['jform']['com_fields']
            : [];

        $patterns = [
            'telegram' => '#^https?://(www\.)?t\.me/.+#i',
            'max'      => '#^https?://(www\.)?max\.ru/.+#i',
        ];
        $invalidLabels = [
            'telegram' => 'Телеграм',
            'max'      => 'Макс',
        ];
        $expected = [
            'telegram' => 'https://t.me/',
            'max'      => 'https://max.ru/',
        ];
        $toSave = [];
        $errors = [];
        foreach ($patterns as $fname => $pattern) {
            if (!\array_key_exists($fname, $rawComFields)) {
                continue;
            }
            $val = trim((string) ($rawComFields[$fname] ?? ''));
            if ($val === '') {
                $toSave[$fname] = '';
                continue;
            }
            if (mb_strlen($val) > 500) {
                $val = mb_substr($val, 0, 500);
            }
            if (!preg_match($pattern, $val)) {
                $errors[] = $invalidLabels[$fname] . ': ссылка должна начинаться с ' . $expected[$fname];
                continue;
            }
            $toSave[$fname] = $val;
        }

        if ($errors !== []) {
            try {
                Factory::getApplication()->enqueueMessage(implode("\n", $errors), 'warning');
            } catch (\Throwable $ignored) {
            }
        }

        if (empty($toSave)) {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $q = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('name')])
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
                ->where($db->quoteName('name') . ' IN (' . implode(',', array_map([$db, 'quote'], array_keys($toSave))) . ')');
            $db->setQuery($q);
            $fieldRows = $db->loadObjectList('name') ?: [];

            foreach ($toSave as $fieldName => $fieldValue) {
                if (!isset($fieldRows[$fieldName])) {
                    continue;
                }
                $fieldId = (int) $fieldRows[$fieldName]->id;

                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName('#__fields_values'))
                        ->where($db->quoteName('field_id') . ' = ' . $fieldId)
                        ->where($db->quoteName('item_id') . ' = ' . $userId)
                )->execute();

                if ($fieldValue !== '') {
                    $db->setQuery(
                        $db->getQuery(true)
                            ->insert($db->quoteName('#__fields_values'))
                            ->columns([$db->quoteName('field_id'), $db->quoteName('item_id'), $db->quoteName('value')])
                            ->values($fieldId . ', ' . $userId . ', ' . $db->quote($fieldValue))
                    )->execute();
                }
            }
        } catch (\Throwable $e) {
            Log::add(
                'Vigling social link fields save failed for user_id=' . $userId . ': ' . $e->getMessage(),
                Log::ERROR,
                'plg_user_vigling'
            );
        }
    }

    private function validateVkProfileWebsite(int $userId): void
    {
        $profile = isset($_POST['jform']['profile']) && \is_array($_POST['jform']['profile'])
            ? $_POST['jform']['profile']
            : [];
        if (!\array_key_exists('website', $profile)) {
            return;
        }
        $val = trim((string) ($profile['website'] ?? ''));
        if ($val === '') {
            return;
        }
        if (preg_match('#^https?://(www\.|m\.)?vk\.com/.+#i', $val)) {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__user_profiles'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId)
                    ->where($db->quoteName('profile_key') . ' = ' . $db->quote('profile.website'))
            )->execute();

            Factory::getApplication()->enqueueMessage(
                'Вконтакте: ссылка должна начинаться с https://vk.com/',
                'warning'
            );
        } catch (\Throwable $e) {
            Log::add(
                'Vigling VK website validation failed for user_id=' . $userId . ': ' . $e->getMessage(),
                Log::ERROR,
                'plg_user_vigling'
            );
        }
    }

    /**
     * Joomla's array input filter can drop JSON-like hidden values in some registration paths.
     * Keep a raw POST fallback for internal payload fields generated by our own templates.
     *
     * @return array<string,mixed>
     */
    private function getPostedJform(): array
    {
        $jform = Factory::getApplication()->input->post->get('jform', [], 'array');
        if (!\is_array($jform)) {
            $jform = [];
        }

        $rawJform = $_POST['jform'] ?? [];
        if (\is_array($rawJform)) {
            foreach (['vigling_services_payload', 'vigling_stock_services_payload', 'vigling_courses_payload', 'vigling_searches_payload'] as $key) {
                if ((!isset($jform[$key]) || (string) $jform[$key] === '') && isset($rawJform[$key]) && \is_scalar($rawJform[$key])) {
                    $jform[$key] = (string) $rawJform[$key];
                }
            }
        }

        return $jform;
    }

    /**
     * @param array<string,mixed> $jform
     */
    private function getJformString(array $jform, string $key): ?string
    {
        if (!isset($jform[$key]) || !\is_scalar($jform[$key])) {
            return null;
        }

        return (string) $jform[$key];
    }

    private function mergeCourseMediaUploads(int $userId, string $payloadJson): string
    {
        $payloadJson = trim($payloadJson);
        if ($payloadJson === '') {
            return $payloadJson;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            return $payloadJson;
        }

        if (empty($_FILES['jform']) || !is_array($_FILES['jform'])) {
            return $payloadJson;
        }

        $files = $_FILES['jform'];
        $names = $this->getUploadArray($files, 'name', 'upload_course_media');
        $tmpNames = $this->getUploadArray($files, 'tmp_name', 'upload_course_media');
        $errors = $this->getUploadArray($files, 'error', 'upload_course_media');
        $sizes = $this->getUploadArray($files, 'size', 'upload_course_media');
        if ($names === [] || $tmpNames === [] || $errors === []) {
            return $payloadJson;
        }

        $courseDir = JPATH_ROOT . '/images/course';
        Folder::create($courseDir);

        foreach ($payload['items'] as $idx => &$item) {
            if (!is_array($item)) {
                continue;
            }

            $name = isset($names[$idx]) && is_scalar($names[$idx]) ? trim((string) $names[$idx]) : '';
            $tmp = isset($tmpNames[$idx]) && is_scalar($tmpNames[$idx]) ? (string) $tmpNames[$idx] : '';
            $err = isset($errors[$idx]) ? (int) $errors[$idx] : \UPLOAD_ERR_NO_FILE;
            $size = isset($sizes[$idx]) ? (int) $sizes[$idx] : 0;

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
            if (!in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
                continue;
            }

            $fileName = 'course_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $targetPath = $courseDir . '/' . $fileName;

            if (@move_uploaded_file($tmp, $targetPath)) {
                $item['media_path'] = 'images/course/' . $fileName;
            }
        }
        unset($item);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $payloadJson;
    }

    private function mergeSearchMediaUploads(int $userId, string $payloadJson): string
    {
        $payloadJson = trim($payloadJson);
        if ($payloadJson === '') {
            return $payloadJson;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            return $payloadJson;
        }

        if (empty($_FILES['jform']) || !is_array($_FILES['jform'])) {
            return $payloadJson;
        }

        $files = $_FILES['jform'];
        $names = $this->getUploadArray($files, 'name', 'upload_search_media');
        $tmpNames = $this->getUploadArray($files, 'tmp_name', 'upload_search_media');
        $errors = $this->getUploadArray($files, 'error', 'upload_search_media');
        $sizes = $this->getUploadArray($files, 'size', 'upload_search_media');
        if ($names === [] || $tmpNames === [] || $errors === []) {
            return $payloadJson;
        }

        $searchDir = JPATH_ROOT . '/images/search';
        Folder::create($searchDir);

        foreach ($payload['items'] as $idx => &$item) {
            if (!is_array($item)) {
                continue;
            }

            $name = isset($names[$idx]) && is_scalar($names[$idx]) ? trim((string) $names[$idx]) : '';
            $tmp = isset($tmpNames[$idx]) && is_scalar($tmpNames[$idx]) ? (string) $tmpNames[$idx] : '';
            $err = isset($errors[$idx]) ? (int) $errors[$idx] : \UPLOAD_ERR_NO_FILE;
            $size = isset($sizes[$idx]) ? (int) $sizes[$idx] : 0;

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
            if (!in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
                continue;
            }

            $fileName = 'search_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $targetPath = $searchDir . '/' . $fileName;

            if (@move_uploaded_file($tmp, $targetPath)) {
                $item['media_path'] = 'images/search/' . $fileName;
            }
        }
        unset($item);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $payloadJson;
    }

    private function getUploadArray(array $files, string $bucket, string $key): array
    {
        if (!isset($files[$bucket]) || !is_array($files[$bucket]) || !isset($files[$bucket][$key]) || !is_array($files[$bucket][$key])) {
            return [];
        }

        return $files[$bucket][$key];
    }

    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $form = $event->getForm();
        if (!($form instanceof Form)) {
            return;
        }

        if ($form->getName() !== 'com_users.user') {
            return;
        }

        $this->loadLanguage();
        FormHelper::addFieldPrefix('Joomla\\Plugin\\User\\Vigling\\Field');
        FormHelper::addFormPath(JPATH_PLUGINS . '/user/vigling/forms');
        $form->loadFile('user', true);

        $this->normalizeComFieldsOnForm($form);
    }

    private function normalizeComFieldsOnForm(Form $form): void
    {
        $fieldset = $form->getFieldset('com_fields');
        if (empty($fieldset)) {
            return;
        }

        $formUserId = (int) $form->getValue('id');
        if ($formUserId <= 0) {
            $formUserId = (int) Factory::getApplication()->input->getInt('id', 0);
        }

        foreach ($fieldset as $field) {
            $name = $field->getAttribute('name');
            if ($name === null || $name === '') {
                continue;
            }
            $shortName = $name;
            if (preg_match('/com_fields\[([^\]]+)\]/', $name, $m)) {
                $shortName = $m[1];
            }
            $value = $form->getValue($name, 'com_fields');
            if (\is_array($value)) {
                $normalized = implode(', ', array_map(function ($v) {
                    return \is_scalar($v) ? (string) $v : json_encode($v);
                }, $value));
                $form->setValue($name, 'com_fields', $normalized);
                continue;
            }
            if (\is_string($value) && \in_array($shortName, self::ENCODED_FIELDS, true)) {
                $decoded = $this->formatJsnEncodedValue($shortName, $value, $formUserId);
                if ($decoded !== null) {
                    $form->setValue($name, 'com_fields', self::VIGLING_MARKER . $decoded);
                }
            }
        }
    }

    private function formatJsnEncodedValue(string $fieldName, string $value, int $userId = 0): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($fieldName === 'prices') {
            if ($userId > 0) {
                $fromNewModel = $this->formatPricesFromNewModel($userId);
                if ($fromNewModel !== null) {
                    return $fromNewModel;
                }
                return 'Нет данных в новой таблице услуг (#__vigling_user_services).';
            }
            return null;
        }
        if ($fieldName === 'stock_prices') {
            if ($userId > 0) {
                $fromNewModel = $this->formatPricesFromNewModel($userId, '#__vigling_user_stock_services');
                if ($fromNewModel !== null) {
                    return $fromNewModel;
                }
                return 'Нет данных в новой таблице акционных услуг (#__vigling_user_stock_services).';
            }
            return null;
        }
        if ($fieldName === 'work_day') {
            return $this->formatWorkDayValue($value);
        }
        if ($fieldName === 'vyberite_spetsialnos') {
            return $this->formatVyberiteSpetsialnosValue($value);
        }
        return null;
    }

    private function formatPricesFromNewModel(int $userId, string $userServicesTable = '#__vigling_user_services'): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $structured = $userServicesTable === '#__vigling_user_stock_services'
            ? JsnDecodeHelper::getUserStockServicesStructured($userId)
            : JsnDecodeHelper::getUserServicesStructured($userId);

        if ($structured === []) {
            return null;
        }

        $lines = [];
        foreach ($structured as $category) {
            $title = (string) ($category['title'] ?? 'Услуги');
            $items = is_array($category['items'] ?? null) ? $category['items'] : [];
            if ($items !== []) {
                $lines[] = $title . ': ' . implode('; ', $items);
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    private function formatWorkDayValue(string $raw): string
    {
        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return $raw;
        }
        $dayNames = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $names = [];
        foreach ($data as $d) {
            $idx = (int) $d;
            if ($idx >= 1 && $idx <= 7) {
                $names[] = $dayNames[$idx];
            }
        }
        return $names === [] ? $raw : implode(', ', $names);
    }

    private function formatVyberiteSpetsialnosValue(string $raw): string
    {
        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return $raw;
        }
        $cats = $this->getCategoriesFromDb();
        $parts = [];
        foreach ($data as $catId) {
            $ids = $this->extractCategoryIds($catId);
            foreach ($ids as $id) {
                $title = isset($cats[$id]['title']) ? $cats[$id]['title'] : '#' . $id;
                $parts[] = $title;
            }
        }
        return $parts === [] ? $raw : implode(', ', $parts);
    }

    /**
     * @return array<int, string>
     */
    private function extractCategoryIds($value): array
    {
        if (\is_scalar($value) || $value === null) {
            $id = trim((string) $value);
            return $id === '' ? [] : [$id];
        }

        if (!\is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $nested) {
            foreach ($this->extractCategoryIds($nested) as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}

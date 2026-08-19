<?php

namespace Joomla\Plugin\User\Vigling\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\NoteField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper;

\defined('_JEXEC') or die;

class JsndecodeField extends NoteField
{
    protected $type = 'Jsndecode';

    private const PROFILE_MAIN_ROWS = [
        'firstname' => 'Имя',
        'lastname' => 'Фамилия',
        'telefon' => 'Телефон',
        'email' => 'E-mail',
        'sity' => 'Город',
        'area' => 'Район',
        'street' => 'Улица',
        'house_number' => 'Номер дома',
        'link' => 'Vk',
        'o_sebe' => 'О себе',
    ];

    private const PROFILE_FALLBACK = [
        'firstname' => ['user' => 'name'],
        'lastname' => ['profile' => 'lastname'],
        'telefon' => ['profile' => 'phone'],
        'email' => ['user' => 'email'],
        'sity' => ['profile' => 'city'],
        'area' => ['profile' => 'region'],
        'street' => ['profile' => 'address1'],
        'house_number' => ['profile' => 'address2'],
        'link' => ['profile' => 'website'],
        'o_sebe' => ['profile' => 'aboutme'],
    ];

    private const PROFILE_MAIN_NAMES = ['firstname', 'lastname', 'telefon', 'sity', 'area', 'street', 'house_number', 'link', 'o_sebe', 'about'];

    private const HOME_LABELS = [
        '1' => 'Салон',
        '2' => 'Вызов на дом',
        '3' => 'Мастер на дому',
    ];

    protected function getInput(): string
    {
        $userId = (int) $this->form->getValue('id');
        if ($userId <= 0) {
            return '<p class="text-muted">' . Text::_('PLG_USER_VIGLING_SAVE_FIRST') . '</p>';
        }

        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $user = $this->loadUser($db, $userId);
        $profile = $this->loadProfile($db, $userId);
        $jcfields = $this->loadJcfields($db, $userId);
        $notFound = 'Нет информации';

        $out = [];

        $out[] = '<div class="mb-4"><h5 class="mb-3">Профиль</h5><dl class="row mb-0">';
        foreach (self::PROFILE_MAIN_ROWS as $fieldName => $label) {
            $val = '';
            if ($fieldName === 'email' && $user !== null) {
                $val = trim((string) ($user['email'] ?? ''));
            } else {
                $val = isset($jcfields[$fieldName]) && trim((string) $jcfields[$fieldName]['value']) !== ''
                    ? trim((string) $jcfields[$fieldName]['value'])
                    : '';
                if ($val === '' && isset(self::PROFILE_FALLBACK[$fieldName])) {
                    $cfg = self::PROFILE_FALLBACK[$fieldName];
                    if (!empty($cfg['user']) && $user !== null && isset($user[$cfg['user']])) {
                        $val = trim((string) $user[$cfg['user']]);
                    } elseif (!empty($cfg['profile']) && isset($profile[$cfg['profile']])) {
                        $v = $profile[$cfg['profile']];
                        $val = is_scalar($v) ? trim((string) $v) : '';
                    }
                }
            }
            $isEmpty = ($val === '');
            $out[] = '<dt class="col-sm-4 text-muted">' . htmlspecialchars($label) . '</dt>';
            $out[] = '<dd class="col-sm-8">' . ($isEmpty ? htmlspecialchars($notFound) : htmlspecialchars($val)) . '</dd>';
        }
        $out[] = '</dl></div>';

        if ($user !== null) {
            $out[] = '<div class="mb-4"><h5 class="mb-3">Даты</h5><dl class="row mb-0">';
            $regDate = !empty($user['registerDate']) ? HTMLHelper::_('date', $user['registerDate'], Text::_('DATE_FORMAT_LC1')) : $notFound;
            $lastDate = !empty($user['lastvisitDate']) && $user['lastvisitDate'] !== $db->getNullDate()
                ? HTMLHelper::_('date', $user['lastvisitDate'], Text::_('DATE_FORMAT_LC1'))
                : Text::_('COM_USERS_PROFILE_NEVER_VISITED');
            $out[] = '<dt class="col-sm-4 text-muted">Дата регистрации</dt><dd class="col-sm-8">' . $regDate . '</dd>';
            $out[] = '<dt class="col-sm-4 text-muted">Последний визит</dt><dd class="col-sm-8">' . $lastDate . '</dd>';
            $out[] = '</dl></div>';
        }

        $otherFields = [];
        foreach ($jcfields as $name => $data) {
            if (in_array($name, self::PROFILE_MAIN_NAMES, true)) {
                continue;
            }
            if (in_array($name, JsnDecodeHelper::getEncodedFieldNames(), true)) {
                continue;
            }
            $title = $data['title'] ?? $name;
            $value = trim((string) ($data['value'] ?? ''));
            $otherFields[] = ['name' => $name, 'title' => $title, 'value' => $value];
        }
        if ($otherFields !== []) {
            $out[] = '<div class="mb-4"><h5 class="mb-3">Дополнительные поля</h5><dl class="row mb-0">';
            foreach ($otherFields as $f) {
                $val = $this->formatOtherFieldValue($f['name'], $f['value'], $notFound);
                $out[] = '<dt class="col-sm-4 text-muted">' . htmlspecialchars($f['title']) . '</dt><dd class="col-sm-8">' . $val . '</dd>';
            }
            $out[] = '</dl></div>';
        }

        $encodedNames = JsnDecodeHelper::getEncodedFieldNames();
        $encodedLabels = [
            'prices' => 'Цены (JSN)',
            'stock_prices' => 'Акционные цены (JSN)',
            'work_day' => 'Рабочие дни',
            'vyberite_spetsialnos' => 'Специальности (JSN)',
        ];
        $out[] = '<div class="mb-4"><h5 class="mb-3">Расшифровка</h5>';
        foreach ($encodedNames as $name) {
            $value = $jcfields[$name]['value'] ?? '';
            $label = $encodedLabels[$name] ?? $name;

            if ($name === 'prices') {
                $structured = JsnDecodeHelper::getUserServicesStructured($userId);
                if ($structured !== []) {
                    $out[] = '<div class="mb-3"><h6 class="mb-2">' . htmlspecialchars($label) . '</h6><div class="row g-2">';
                    foreach ($structured as $cat) {
                        $out[] = '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 small fw-bold">' . htmlspecialchars($cat['title']) . '</div><ul class="list-group list-group-flush list-group-numbered small">';
                        foreach ($cat['items'] as $item) {
                            $out[] = '<li class="list-group-item py-1">' . htmlspecialchars($item) . '</li>';
                        }
                        $out[] = '</ul></div></div>';
                    }
                    $out[] = '</div></div>';
                } else {
                    $out[] = '<div class="mb-3"><h6 class="mb-1">' . htmlspecialchars($label) . '</h6><p class="mb-0 text-warning">Нет данных в новой таблице услуг.</p></div>';
                }
                continue;
            }

            if (trim((string) $value) === '') {
                continue;
            }

            if ($name === 'stock_prices') {
                $structured = JsnDecodeHelper::getUserStockServicesStructured($userId);
                if ($structured !== []) {
                    $out[] = '<div class="mb-3"><h6 class="mb-2">' . htmlspecialchars($label) . '</h6><div class="row g-2">';
                    foreach ($structured as $cat) {
                        $out[] = '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 small fw-bold">' . htmlspecialchars($cat['title']) . '</div><ul class="list-group list-group-flush list-group-numbered small">';
                        foreach ($cat['items'] as $item) {
                            $out[] = '<li class="list-group-item py-1">' . htmlspecialchars($item) . '</li>';
                        }
                        $out[] = '</ul></div></div>';
                    }
                    $out[] = '</div></div>';
                } else {
                    $out[] = '<div class="mb-3"><h6 class="mb-1">' . htmlspecialchars($label) . '</h6><p class="mb-0 text-warning">Нет данных в новой таблице акционных услуг.</p></div>';
                }
                continue;
            } else {
                $decoded = JsnDecodeHelper::decodeFieldValue($name, $value);
                if ($decoded !== null) {
                    $out[] = '<div class="mb-3"><h6 class="mb-1">' . htmlspecialchars($label) . '</h6><p class="mb-0">' . htmlspecialchars($decoded) . '</p></div>';
                }
            }
        }
        $out[] = '</div>';

        return '<div class="jsn-decode-vigling">' . implode('', $out) . '</div>';
    }

    private function loadUser(\Joomla\Database\DatabaseInterface $db, int $userId): ?array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['name', 'email', 'registerDate', 'lastvisitDate']))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = ' . (int) $userId);
        $db->setQuery($query);
        $row = $db->loadAssoc();
        return is_array($row) ? $row : null;
    }

    private function loadProfile(\Joomla\Database\DatabaseInterface $db, int $userId): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['profile_key', 'profile_value']))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId)
            ->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote('profile.%'));
        $db->setQuery($query);
        $rows = $db->loadAssocList() ?: [];
        $profile = [];
        foreach ($rows as $r) {
            $k = str_replace('profile.', '', (string) ($r['profile_key'] ?? ''));
            if ($k === '') {
                continue;
            }
            $v = $r['profile_value'] ?? '';
            $dec = json_decode($v, true);
            $profile[$k] = $dec !== null ? $dec : $v;
        }
        return $profile;
    }

    private function loadJcfields(\Joomla\Database\DatabaseInterface $db, int $userId): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['f.name', 'f.title', 'fv.value']))
            ->from($db->quoteName('#__fields_values', 'fv'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id'))
            ->where($db->quoteName('f.context') . ' = ' . $db->quote('com_users.user'))
            ->where($db->quoteName('fv.item_id') . ' = ' . (int) $userId);
        $db->setQuery($query);
        $rows = $db->loadAssocList() ?: [];
        $byName = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $value = (string) ($row['value'] ?? '');
            if (!isset($byName[$name]) || strlen($value) > strlen($byName[$name]['value'] ?? '')) {
                $byName[$name] = ['title' => (string) ($row['title'] ?? $name), 'value' => $value];
            }
        }
        return $byName;
    }

    private function formatOtherFieldValue(string $name, string $value, string $notFound): string
    {
        $value = trim($value);
        if ($value === '') {
            return $notFound;
        }
        if ($name === 'avatar') {
            $src = $value;
            if ($src !== '' && strpos($src, 'http') !== 0) {
                $src = preg_replace('#^/?(images/profiler/?)?#i', '', str_replace('\\', '/', $src));
                $src = rtrim(Uri::root(), '/') . '/images/profiler/' . $src;
            }
            return $src !== '' ? '<img src="' . htmlspecialchars($src) . '" alt="" style="max-width:80px;height:auto;">' : $notFound;
        }
        if ($name === 'portfolio_field') {
            $arr = json_decode($value, true);
            if (!is_array($arr) || $arr === []) {
                return htmlspecialchars($value);
            }
            $root = rtrim(Uri::root(), '/');
            $portfolioBase = $root . '/images/portfolio/';
            $parts = [];
            foreach ($arr as $file) {
                $file = is_string($file) ? trim($file) : '';
                if ($file === '') {
                    continue;
                }
                $src = $portfolioBase . ltrim(str_replace('\\', '/', $file), '/');
                $parts[] = '<a href="' . htmlspecialchars($src) . '" target="_blank" rel="noopener"><img src="' . htmlspecialchars($src) . '" alt="" style="max-width:80px;max-height:80px;object-fit:cover;margin:2px;"></a>';
            }
            return $parts === [] ? $notFound : '<div class="d-flex flex-wrap gap-1">' . implode('', $parts) . '</div>';
        }
        if ($name === 'home') {
            $arr = json_decode($value, true);
            if (!is_array($arr) || $arr === []) {
                return htmlspecialchars($value);
            }
            $labels = [];
            foreach ($arr as $id) {
                $key = (string) $id;
                $labels[] = self::HOME_LABELS[$key] ?? $key;
            }
            return $labels === [] ? $notFound : htmlspecialchars(implode(', ', $labels));
        }
        return htmlspecialchars($value);
    }
}

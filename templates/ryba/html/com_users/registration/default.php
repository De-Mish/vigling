<?php
/**
 * @package     Joomla.Site
 * @subpackage  com_users
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')->useScript('form.validate');

$db = Factory::getContainer()->get(Joomla\Database\DatabaseInterface::class);

$catQuery = $db->getQuery(true)
    ->select($db->quoteName(['id', 'title', 'path']))
    ->from($db->quoteName('#__categories'))
    ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
    ->where($db->quoteName('published') . ' = 1')
    ->where($db->quoteName('level') . ' = 2')
    ->order($db->quoteName('lft') . ' ASC');
$db->setQuery($catQuery);
$specialtyRows = $db->loadAssocList() ?: [];

$specialties = [];
$repairCategoryIds = [];
$beautyCategoryIds = [];

foreach ($specialtyRows as $row) {
    $id = (int) ($row['id'] ?? 0);
    $title = (string) ($row['title'] ?? '');
    $path = (string) ($row['path'] ?? '');

    if ($id <= 0 || $title === '') {
        continue;
    }

    $isRepair = strpos($path, 'zatochka-remont/') === 0;

    $specialties[] = [
        'id' => $id,
        'title' => $title,
        'type' => $isRepair ? 'repair' : 'beauty',
    ];

    if ($isRepair) {
        $repairCategoryIds[] = $id;
    } else {
        $beautyCategoryIds[] = $id;
    }
}

$allCategoryIds = array_values(array_unique(array_merge($beautyCategoryIds, $repairCategoryIds)));
$servicesByCategory = [];

if ($allCategoryIds !== []) {
    $servicesQuery = $db->getQuery(true)
        ->select($db->quoteName(['id', 'catid', 'title']))
        ->from($db->quoteName('#__content'))
        ->where($db->quoteName('state') . ' = 1')
        ->where($db->quoteName('catid') . ' IN (' . implode(',', array_map('intval', $allCategoryIds)) . ')')
        ->order($db->quoteName('catid') . ' ASC')
        ->order($db->quoteName('title') . ' ASC');
    $db->setQuery($servicesQuery);
    $serviceRows = $db->loadAssocList() ?: [];

    $serviceIds = [];

    foreach ($serviceRows as $row) {
        $serviceId = (int) ($row['id'] ?? 0);
        $catId = (int) ($row['catid'] ?? 0);

        if ($serviceId <= 0 || $catId <= 0) {
            continue;
        }

        $serviceIds[] = $serviceId;

        if (!isset($servicesByCategory[$catId])) {
            $servicesByCategory[$catId] = [];
        }

        $servicesByCategory[$catId][$serviceId] = [
            'id' => $serviceId,
            'title' => (string) ($row['title'] ?? ''),
            'tags' => [],
        ];
    }

    if ($serviceIds !== []) {
        $tagsQuery = $db->getQuery(true)
            ->select([
                $db->quoteName('m.content_item_id', 'content_id'),
                $db->quoteName('t.id', 'tag_id'),
                $db->quoteName('t.title', 'tag_title'),
            ])
            ->from($db->quoteName('#__contentitem_tag_map', 'm'))
            ->join('INNER', $db->quoteName('#__tags', 't') . ' ON ' . $db->quoteName('t.id') . ' = ' . $db->quoteName('m.tag_id'))
            ->where($db->quoteName('m.type_alias') . ' = ' . $db->quote('com_content.article'))
            ->where($db->quoteName('m.content_item_id') . ' IN (' . implode(',', array_map('intval', $serviceIds)) . ')')
            ->where($db->quoteName('t.published') . ' = 1')
            ->order($db->quoteName('t.title') . ' ASC');
        $db->setQuery($tagsQuery);
        $tagRows = $db->loadAssocList() ?: [];

        $serviceCatMap = [];
        foreach ($servicesByCategory as $catId => $services) {
            foreach ($services as $serviceId => $serviceData) {
                $serviceCatMap[$serviceId] = (int) $catId;
            }
        }

        foreach ($tagRows as $tagRow) {
            $contentId = (int) ($tagRow['content_id'] ?? 0);
            $tagId = (int) ($tagRow['tag_id'] ?? 0);
            $tagTitle = (string) ($tagRow['tag_title'] ?? '');
            $catId = $serviceCatMap[$contentId] ?? 0;

            if ($contentId <= 0 || $tagId <= 0 || $catId <= 0 || $tagTitle === '') {
                continue;
            }

            if (!isset($servicesByCategory[$catId][$contentId])) {
                continue;
            }

            $servicesByCategory[$catId][$contentId]['tags'][] = [
                'id' => $tagId,
                'title' => $tagTitle,
            ];
        }
    }

    foreach ($servicesByCategory as $catId => $services) {
        $servicesByCategory[$catId] = array_values($services);
    }
}

$timeOptions = [];
for ($h = 8; $h <= 24; $h++) {
    foreach ([0, 15, 30, 45] as $m) {
        if ($h === 24 && $m > 0) {
            continue;
        }
        $labelH = $h === 24 ? '00' : sprintf('%02d', $h);
        $labelM = sprintf('%02d', $m);
        $timeOptions[] = $labelH . ':' . $labelM;
    }
}

$durationOptions = [];
for ($i = 1; $i <= 12; $i++) {
    $durationOptions[] = $i * 15;
}

$registrationData = is_array($this->data ?? null) ? $this->data : [];
$profileData = is_array($registrationData['profile'] ?? null) ? $registrationData['profile'] : [];
$hasSubmittedData = !empty($registrationData);

$getValue = static function (array $source, string $key, string $fallback = ''): string {
    $v = $source[$key] ?? $fallback;
    return is_scalar($v) ? (string) $v : $fallback;
};

$registrationTypeValue = $getValue($registrationData, 'registration_type', 'client');
if (!in_array($registrationTypeValue, ['client', 'master', 'zatochka_remont'], true)) {
    $registrationTypeValue = 'client';
}

$emailValue = $getValue($registrationData, 'email1');
$nameValue = $getValue($registrationData, 'name');
$password1Value = $getValue($registrationData, 'password1');
$password2Value = $getValue($registrationData, 'password2');
$usernameValue = $getValue($registrationData, 'username', $emailValue);

$phoneValue = $getValue($profileData, 'phone');
$lastnameValue = $getValue($profileData, 'lastname');
$cityValue = $getValue($profileData, 'city');
$regionValue = $getValue($profileData, 'region');
$address1Value = $getValue($profileData, 'address1');
$address2Value = $getValue($profileData, 'address2');
$websiteValue = $getValue($profileData, 'website');
$aboutMeValue = $getValue($profileData, 'aboutme');
$comFieldsRegData = isset($registrationData['com_fields']) && is_array($registrationData['com_fields']) ? $registrationData['com_fields'] : [];
$telegramValue = $getValue($comFieldsRegData, 'telegram');
$maxValue = $getValue($comFieldsRegData, 'max');

$selectedSpecialties = $registrationData['vyberite_spetsialnos'] ?? [];
if (!is_array($selectedSpecialties)) {
    $selectedSpecialties = [];
}
$selectedSpecialties = array_map('intval', $selectedSpecialties);

$selectedWorkDays = $registrationData['work_day'] ?? [1, 2, 3, 4, 5, 6];
if (!is_array($selectedWorkDays)) {
    $selectedWorkDays = [1, 2, 3, 4, 5, 6];
}
$selectedWorkDays = array_map('intval', $selectedWorkDays);
$workFromValue = $getValue($registrationData, 'work_from', '10:00');
$workToValue = $getValue($registrationData, 'work_to', '20:00');

$days = [
    1 => 'Понедельник',
    2 => 'Вторник',
    3 => 'Среда',
    4 => 'Четверг',
    5 => 'Пятница',
    6 => 'Суббота',
    7 => 'Воскресенье',
];

$specialtiesJson = json_encode($specialties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$servicesJson = json_encode($servicesByCategory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$durationJson = json_encode($durationOptions);
?>
<div id="easyprofile" class="test registration legacy-registration<?php echo $this->pageclass_sfx; ?>">
    <?php if ($this->params->get('show_page_heading')) : ?>
    <div class="container header-bot">
        <h1><?php echo $this->escape($this->params->get('page_heading')); ?></h1>
        <div class="clearFloat"></div>
    </div>
    <?php endif; ?>

    <form id="member-registration" action="<?php echo Route::_('index.php?option=com_users&task=registration.register'); ?>" method="post" class="form-validate form-horizontal well" enctype="multipart/form-data">
        <div class="reg-role-switch" id="registration-type-switch"<?php echo $hasSubmittedData ? ' style="display:none;"' : ''; ?>>
            <button type="button" class="dale reg-role-btn" data-type="client">Клиент</button>
            <button type="button" class="dale reg-role-btn" data-type="master">Мастер</button>
            <button type="button" class="dale reg-role-btn" data-type="zatochka_remont">Заточка/Ремонт</button>
        </div>

        <div id="jsn-form" class="hover clean mini flat z-icons-light z-shadows z-spaced z-tabs horizontal top-compact top"<?php echo $hasSubmittedData ? '' : ' style="display:none;"'; ?>>
        <ul class="z-tabs-nav z-tabs-mobile" style="display:none;">
            <li><a class="z-link" style="text-align:left;"><span class="z-title">Профиль<span></span></span><span class="z-arrow"></span></a></li>
        </ul>
        <i class="z-dropdown-arrow"></i>
        <ul id="jsn-profile-tabs" class="z-tabs-nav z-tabs-desktop reg-step-links">
            <li class="z-tab z-first z-active" data-tab="jsn_default"><a class="z-link reg-step-link" href="#jsn_default">Профиль<span></span></a></li>
            <li class="z-tab" data-tab="jsn_portfolio"><a class="z-link reg-step-link" href="#jsn_portfolio">Портфолио<span></span></a></li>
            <li class="z-tab" data-tab="jsn_spetsialnost"><a class="z-link reg-step-link" href="#jsn_spetsialnost">Специальность<span></span></a></li>
            <li class="z-tab" data-tab="jsn_addinfo"><a class="z-link reg-step-link" href="#jsn_addinfo">Услуги и цены<span></span></a></li>
            <li class="z-tab" data-tab="jsn_courses"><a class="z-link reg-step-link" href="#jsn_courses">Курсы<span></span></a></li>
            <li class="z-tab" data-tab="jsn_searches"><a class="z-link reg-step-link" href="#jsn_searches">Поиск моделей<span></span></a></li>
            <li class="z-tab" data-tab="jsn_login"><a class="z-link reg-step-link" href="#jsn_login">Email и пароль<span></span></a></li>
            <li class="z-tab z-last" data-tab="jsn_raspisanie"><a class="z-link reg-step-link" href="#jsn_raspisanie">Расписание<span></span></a></li>
        </ul>

        <div class="z-container" id="registration-tabs-container">
            <div class="z-content z-active" data-tab="jsn_default" style="display:block;">
                <div class="z-content-inner">
                    <fieldset id="jsn_default" class="jsn-form-fieldset">
                        <legend style="display:none;">Профиль</legend>

                        <div class="control-group avatar-group">
                            <div class="control-label" style="display:none;"><label>Фото профиля</label></div>
                            <div class="controls">
                                <img src="/templates/ryba/images/avatar_upload.png" alt="Фото профиля" class="img_avatar" style="float:left;width:50px;margin-right:10px;border-radius:2px;margin-bottom:5px;" />
                                <input type="file" name="jform[upload_avatar]" id="jform_upload_avatar" accept="image/*" />
                                <input type="hidden" name="jform[avatar]" id="jform_avatar" value="" />
                                <div style="clear:both"></div>
                            </div>
                        </div>

                        <div class="control-group name-group">
                            <div class="control-group firstname-group">
                                <div class="controls">
                                    <input type="text" name="jform[name]" id="jform_name" value="<?php echo $this->escape($nameValue); ?>" class="required" placeholder="Имя" required />
                                </div>
                            </div>
                            <div class="control-group lastname-group">
                                <div class="controls">
                                    <input type="text" name="jform[profile][lastname]" id="jform_lastname" value="<?php echo $this->escape($lastnameValue); ?>" class="required" placeholder="Фамилия" required />
                                </div>
                            </div>
                        </div>

                        <div class="control-group mail-group">
                            <div class="control-group telefon-group">
                                <div class="controls">
                                    <input type="text" name="jform[profile][phone]" id="jform_telefon" value="<?php echo $this->escape($phoneValue); ?>" class="required js-phone-mask" placeholder="Телефон" required />
                                </div>
                            </div>
                            <div class="control-group email1-group">
                                <div class="controls">
                                    <input type="email" name="jform[email1]" id="jform_email1" value="<?php echo $this->escape($emailValue); ?>" class="validate-email required" placeholder="E-mail" required autocomplete="email" />
                                    <div class="message-regex">Почта используется для восстановления аккаунта</div>
                                </div>
                            </div>
                        </div>

                        <div class="address-group form-row m-0">
                            <div class="control-group sity-group">
                                <div class="controls">
                                    <input type="text" name="jform[profile][city]" id="jform_sity" value="<?php echo $this->escape($cityValue); ?>" class="required" placeholder="Город" required />
                                </div>
                            </div>
                            <div class="control-group area-group master-only-field">
                                <div class="controls">
                                    <input type="text" name="jform[profile][region]" id="jform_area" value="<?php echo $this->escape($regionValue); ?>" placeholder="Район" />
                                </div>
                            </div>
                            <div class="control-group street-group master-only-field">
                                <div class="controls">
                                    <input type="text" name="jform[profile][address1]" id="jform_street" value="<?php echo $this->escape($address1Value); ?>" placeholder="Улица" />
                                </div>
                            </div>
                            <div class="control-group house_number-group master-only-field">
                                <div class="controls">
                                    <input type="text" name="jform[profile][address2]" id="jform_house_number" value="<?php echo $this->escape($address2Value); ?>" placeholder="Дом" />
                                </div>
                            </div>
                        </div>

                        <div class="form-row master-only-field social-links-group">
                            <div class="control-group social-link-row">
                                <img src="/templates/ryba/icons/social_icons/vk.svg" alt="Vk" class="social-link-icon">
                                <div class="controls">
                                    <input type="url" name="jform[profile][website]" id="jform_link" value="<?php echo $this->escape($websiteValue); ?>" placeholder="https://vk.com/ваш_id" pattern="^https?://(www\.|m\.)?vk\.com/.+" title="Ссылка должна начинаться с https://vk.com/" />
                                </div>
                            </div>
                            <div class="control-group social-link-row">
                                <img src="/templates/ryba/icons/social_icons/telegram.svg" alt="Telegram" class="social-link-icon">
                                <div class="controls">
                                    <input type="url" name="jform[com_fields][telegram]" id="jform_telegram" value="<?php echo $this->escape($telegramValue); ?>" placeholder="https://t.me/ваш_id" pattern="^https?://(www\.)?t\.me/.+" title="Ссылка должна начинаться с https://t.me/" />
                                </div>
                            </div>
                            <div class="control-group social-link-row">
                                <img src="/templates/ryba/icons/social_icons/max.svg" alt="Max" class="social-link-icon">
                                <div class="controls">
                                    <input type="url" name="jform[com_fields][max]" id="jform_max" value="<?php echo $this->escape($maxValue); ?>" placeholder="https://max.ru/ваш_id" pattern="^https?://(www\.)?max\.ru/.+" title="Ссылка должна начинаться с https://max.ru/" />
                                </div>
                            </div>
                        </div>

                        <div class="control-group o_sebe-group master-only-field">
                            <div class="controls">
                                <textarea name="jform[profile][aboutme]" id="jform_o_sebe" class="input_placeholder" placeholder="О себе"><?php echo $this->escape($aboutMeValue); ?></textarea>
                            </div>
                        </div>

                    </fieldset>
                </div>
            </div>

            <div class="z-content" data-tab="jsn_portfolio" style="display:none;">
                <div class="z-content-inner">
                    <fieldset id="jsn_portfolio" class="jsn-form-fieldset">
                        <legend style="display:none;">Портфолио</legend>
                        <div class="control-group portfolio_field-group">
                            <div class="controls">
                                <img src="/templates/ryba/images/3.png" alt="" class="img_portfolio_field" />
                                <input type="file" name="jform[upload_portfolio_field][]" id="jform_upload_portfolio_field" accept="image/*" multiple />
                                <input type="hidden" name="jform[portfolio_field]" id="jform_portfolio_field" value="" />
                                <div style="clear:both"></div>
                            </div>
                            <div id="portfolio-preview-list" class="portfolio-preview-list"></div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="z-content" data-tab="jsn_spetsialnost" style="display:none;">
                <div class="z-content-inner">
                    <fieldset id="jsn_spetsialnost" class="jsn-form-fieldset">
                        <legend style="display:none;">Специальность</legend>
                        <div class="control-group vyberite_spetsialnos-group">
                            <div class="controls">
                                <fieldset id="jform_vyberite_spetsialnos" class="required checkboxes" aria-required="true">
                                    <?php foreach ($specialties as $specialty) : ?>
                                    <label for="jform_vyberite_spetsialnos<?php echo (int) $specialty['id']; ?>" class="checkbox" data-type="<?php echo $specialty['type']; ?>">
                                        <input type="checkbox" id="jform_vyberite_spetsialnos<?php echo (int) $specialty['id']; ?>" name="jform[vyberite_spetsialnos][]" value="<?php echo (int) $specialty['id']; ?>" <?php echo in_array((int) $specialty['id'], $selectedSpecialties, true) ? 'checked' : ''; ?> />
                                        <?php echo $this->escape($specialty['title']); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="z-content" data-tab="jsn_addinfo" style="display:none;">
                <div class="z-content-inner">
                    <fieldset id="jsn_addinfo" class="jsn-form-fieldset">
                        <legend style="display:none;">Услуги и цены</legend>
                        <div class="control-group prices-group">
                            <div class="controls">
                                <fieldset id="jform_vyberite_usl">Выберите специальность, чтобы добавить услугу</fieldset>
                                <input type="hidden" name="jform[prices]" id="jform_prices" value="" />
                                <input type="hidden" name="jform[stock_prices]" id="jform_stock_prices" value="" />
                                <input type="hidden" name="jform[stocks_price]" id="jform_stocks_price" value="" />
                                <input type="hidden" name="jform[vigling_services_payload]" id="jform_vigling_services_payload" value="" />
                                <input type="hidden" name="jform[vigling_stock_services_payload]" id="jform_vigling_stock_services_payload" value="" />
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="z-content" data-tab="jsn_courses" style="display:none;">
                <div class="z-content-inner">
                    <fieldset id="jsn_courses" class="jsn-form-fieldset">
                        <legend style="display:none;">Курсы</legend>
                        <div class="control-group stock_prices-group">
                            <div class="controls">
                                <fieldset id="jform_courses_servis">Выберите специальность, чтобы добавить курс</fieldset>
                                <input type="hidden" name="jform[vigling_courses_payload]" id="jform_vigling_courses_payload" value="" />
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="z-content" data-tab="jsn_searches" style="display:none;">
                <div class="z-content-inner">
                    <fieldset id="jsn_searches" class="jsn-form-fieldset">
                        <legend style="display:none;">Поиск моделей</legend>
                        <div class="control-group stock_prices-group">
                            <div class="controls">
                                <fieldset id="jform_searches_servis">Выберите специальность, чтобы добавить поиск</fieldset>
                                <input type="hidden" name="jform[vigling_searches_payload]" id="jform_vigling_searches_payload" value="" />
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="z-content" data-tab="jsn_login" style="display:none;">
                <div class="z-content-inner">
                    <fieldset id="jsn_login" class="jsn-form-fieldset">
                        <legend style="display:none;">Email и пароль</legend>
                        <div class="control-group login-email-note">
                            <div class="controls">
                                <div class="message-regex">В качестве email для входа используется почта аккаунта</div>
                            </div>
                        </div>
                        <div class="control-group login-group">
                            <div class="control-group password1-group">
                                <div class="controls">
                                    <div class="password-input-wrap">
                                        <input type="password" name="jform[password1]" id="jform_password1" value="<?php echo $this->escape($password1Value); ?>" class="validate-password required" placeholder="Пароль" required autocomplete="new-password" />
                                        <button type="button" class="password-toggle-btn" data-target="#jform_password1" aria-label="Показать пароль" title="Показать пароль">
                                            <span class="fa fa-eye" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="control-group password2-group">
                                <div class="controls">
                                    <div class="password-input-wrap">
                                        <input type="password" name="jform[password2]" id="jform_password2" value="<?php echo $this->escape($password2Value); ?>" class="validate-password required" placeholder="Повторите пароль" required autocomplete="new-password" />
                                        <button type="button" class="password-toggle-btn" data-target="#jform_password2" aria-label="Показать пароль" title="Показать пароль">
                                            <span class="fa fa-eye" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="control-group privacy-consent-group">
                            <div class="controls">
                                <label class="checkbox privacy-consent-label" for="privacy_consent">
                                    <input type="checkbox" id="privacy_consent" name="privacy_consent" value="1" />
                                    Нажимая «Вперед», я принимаю условия <a class="z-link" href="/privacy-policy" target="_blank" rel="noopener noreferrer">Политики конфиденциальности</a>
                                </label>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="z-content" data-tab="jsn_raspisanie" style="display:none;">
                <div class="z-content-inner">
                    <fieldset id="jsn_raspisanie" class="jsn-form-fieldset">
                        <legend style="display:none;">Расписание</legend>
                        <div class="control-group work_day-group">
                            <div class="control-label"><label for="jform_work_day">Рабочие дни</label></div>
                            <div class="controls">
                                <fieldset id="jform_work_day" class="checkboxes">
                                    <?php foreach ($days as $dayValue => $dayLabel) : ?>
                                    <label for="jform_work_day<?php echo (int) $dayValue; ?>" class="checkbox">
                                        <input type="checkbox" id="jform_work_day<?php echo (int) $dayValue; ?>" name="jform[work_day][]" value="<?php echo (int) $dayValue; ?>" <?php echo in_array((int) $dayValue, $selectedWorkDays, true) ? 'checked' : ''; ?> />
                                        <?php echo $dayLabel; ?>
                                    </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            </div>
                        </div>

                        <div class="control-group work_from-group">
                            <div class="control-label"><label for="jform_work_from">Работаем с</label></div>
                            <div class="controls">
                                <select id="jform_work_from" name="jform[work_from]">
                                    <option value="">выбрать</option>
                                    <?php foreach ($timeOptions as $timeOption) : ?>
                                    <option value="<?php echo $this->escape($timeOption); ?>" <?php echo $timeOption === $workFromValue ? 'selected' : ''; ?>><?php echo $this->escape($timeOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="control-group work_to-group">
                            <div class="control-label"><label for="jform_work_to">Работаем до</label></div>
                            <div class="controls">
                                <select id="jform_work_to" name="jform[work_to]">
                                    <option value="">выбрать</option>
                                    <?php foreach ($timeOptions as $timeOption) : ?>
                                    <option value="<?php echo $this->escape($timeOption); ?>" <?php echo $timeOption === $workToValue ? 'selected' : ''; ?>><?php echo $this->escape($timeOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>
        </div>
        </div>

        <div class="jsn_registration_controls calc__btn"<?php echo $hasSubmittedData ? '' : ' style="display:none;"'; ?>>
            <button type="button" class="dale" id="reg-prev-step" style="display:none;">Назад</button>
            <button type="button" class="dale" id="reg-next-step">Вперед</button>
            <div class="reg-submit-block" id="reg-submit-block" hidden>
                <div id="privacy-consent-error" class="privacy-error privacy-error-box" role="alert" hidden></div>
                <button type="submit" class="dale validate" id="reg-submit" disabled aria-describedby="privacy-consent-error">Зарегистрироваться</button>
            </div>
            <a class="dale" id="reg-cancel" href="<?php echo Route::_('index.php'); ?>" title="<?php echo Text::_('JCANCEL'); ?>"><?php echo Text::_('JCANCEL'); ?></a>
        </div>

        <input type="hidden" name="jform[username]" id="jform_username" value="<?php echo $this->escape($usernameValue); ?>" />
        <input type="hidden" name="jform[registration_type]" id="jform_registration_type" value="<?php echo $this->escape($registrationTypeValue); ?>" />
        <input type="hidden" name="jform[is_master]" id="jform_is_master" value="0" />
        <input type="hidden" name="jform[recaptcha_token]" id="jform_recaptcha_token" value="" />
        <input type="hidden" name="jform[recaptcha_action]" id="jform_recaptcha_action" value="" />

        <input type="hidden" name="option" value="com_users" />
        <input type="hidden" name="task" value="registration.register" />
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
</div>

<style>
/*
  Registration step tabs.
  Previous CSS in style-ext.css did not show a frame: tabs.min.css zeros
  border/radius on `a`, and style.css paints `a` with the same #f7cc53 fill.
  This block is in the page HTML (after those files) and draws a ::after
  frame on li.z-tab that the plugin never targets. Fill is white so the
  gold border matches #jform_o_sebe / .input_placeholder (radius 20px).
*/
#easyprofile.registration #jsn-form.flat > ul#jsn-profile-tabs.reg-step-links,
#easyprofile.registration #jsn-form.flat > ul#jsn-profile-tabs.reg-step-links > li.z-tab,
#easyprofile.registration ul#jsn-profile-tabs.reg-step-links > li.z-tab {
    position: relative !important;
    overflow: visible !important;
    box-sizing: border-box !important;
    border: 0 none !important;
    background: transparent !important;
    background-color: transparent !important;
}
#easyprofile.registration ul#jsn-profile-tabs.reg-step-links > li.z-tab {
    border-radius: 20px !important;
}
#easyprofile.registration ul#jsn-profile-tabs.reg-step-links > li.z-tab::after {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    border: 2px solid #f7cc53;
    border-radius: 20px;
    box-sizing: border-box;
    pointer-events: none;
    z-index: 2;
}
#easyprofile.registration #jsn-form.flat.horizontal.clean > ul#jsn-profile-tabs.z-tabs-nav > li.z-tab > a.z-link.reg-step-link,
#easyprofile.registration ul#jsn-profile-tabs.reg-step-links > li.z-tab > a.z-link.reg-step-link {
    position: relative !important;
    z-index: 1;
    height: 32px !important;
    min-height: 32px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 6px 12px !important;
    font-size: 14px !important;
    line-height: 1.1 !important;
    white-space: nowrap !important;
    box-sizing: border-box !important;
    overflow: visible !important;
    border: 0 none !important;
    border-radius: 20px !important;
    background: #fff !important;
    background-color: #fff !important;
    color: #333 !important;
    text-decoration: none !important;
    box-shadow: none !important;
    outline: none !important;
}
#easyprofile.registration ul#jsn-profile-tabs.reg-step-links > li.z-tab > a.z-link.reg-step-link:hover,
#easyprofile.registration ul#jsn-profile-tabs.reg-step-links > li.z-tab > a.z-link.reg-step-link:focus {
    background: #fff8e1 !important;
    background-color: #fff8e1 !important;
    color: #333 !important;
    text-decoration: none !important;
}
#easyprofile.registration ul#jsn-profile-tabs.reg-step-links > li.z-tab.z-active > a.z-link.reg-step-link {
    background: #bb9a3c !important;
    background-color: #bb9a3c !important;
    color: #000 !important;
}

#easyprofile.registration #jform_courses_servis {
    display: block;
}
#easyprofile.registration #jform_courses_servis > label {
    display: block !important;
    position: relative !important;
    width: 100% !important;
    max-width: 520px !important;
    margin: 0 0 34px !important;
    padding: 0 !important;
    font-size: 16px !important;
    line-height: 1.35 !important;
    font-family: "GothamPro-Medium", sans-serif !important;
    font-weight: 500 !important;
    color: #222 !important;
    cursor: default !important;
}
#easyprofile.registration #jform_courses_servis > label:last-child {
    margin-bottom: 0 !important;
}
#easyprofile.registration #jform_courses_servis > label > b {
    display: inline-block !important;
    width: 32px !important;
    height: 32px !important;
    margin-left: 22px !important;
    vertical-align: middle !important;
    background-repeat: no-repeat !important;
    background-position: center !important;
    background-size: 16px 10px !important;
    border-radius: 50% !important;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08) !important;
    background-color: #fff !important;
}
#easyprofile.registration #jform_courses_servis > label .flex_wrap {
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 0 !important;
    margin-top: 0 !important;
    min-height: 0 !important;
}
#easyprofile.registration #jform_courses_servis > label .service_list {
    display: block !important;
    width: 100% !important;
}
#easyprofile.registration #jform_courses_servis > label .service_list:empty {
    display: none !important;
}
#easyprofile.registration #jform_courses_servis .stock_key {
    margin: 12px 0 0 !important;
    width: 37px;
    height: 37px;
    border: 1px solid #000;
    border-radius: 50%;
    cursor: pointer;
    position: relative;
}
#easyprofile.registration #jform_courses_servis .stock_key::before,
#easyprofile.registration #jform_courses_servis .stock_key::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 50%;
    background: #000;
    transform: translate(-50%, -50%);
}
#easyprofile.registration #jform_courses_servis .stock_key::before {
    width: 14px;
    height: 2px;
}
#easyprofile.registration #jform_courses_servis .stock_key::after {
    width: 2px;
    height: 14px;
}
#easyprofile.registration #jform_courses_servis .service__item {
    display: block !important;
    margin: 15px !important;
    padding-bottom: 20px !important;
    position: relative !important;
}
#easyprofile.registration #jform_courses_servis .service__item::after {
    content: "";
    position: absolute;
    display: block;
    background-color: rgba(0, 0, 0, 0.15);
    width: 411px;
    height: 2px;
    bottom: -5px;
}
#easyprofile.registration #jform_courses_servis .service__item .course_desc,
#easyprofile.registration #jform_courses_servis .service__item .course_title,
#easyprofile.registration #jform_courses_servis .service__item .course_media,
#easyprofile.registration #jform_courses_servis .service__item .course_price,
#easyprofile.registration #jform_courses_servis .service__item .course_duration,
#easyprofile.registration #jform_courses_servis .service__item .course_capacity,
#easyprofile.registration #jform_courses_servis .service__item .course_mode,
#easyprofile.registration #jform_courses_servis .service__item .course_slot {
    display: flex !important;
    align-items: center !important;
    padding: 4px 0 !important;
    gap: 8px;
}
#easyprofile.registration #jform_courses_servis .service__item .course_media {
    align-items: flex-start !important;
}
#easyprofile.registration #jform_courses_servis .service__item .course_desc label,
#easyprofile.registration #jform_courses_servis .service__item .course_title label,
#easyprofile.registration #jform_courses_servis .service__item .course_media label,
#easyprofile.registration #jform_courses_servis .service__item .course_price label,
#easyprofile.registration #jform_courses_servis .service__item .course_duration label,
#easyprofile.registration #jform_courses_servis .service__item .course_capacity label,
#easyprofile.registration #jform_courses_servis .service__item .course_mode label,
#easyprofile.registration #jform_courses_servis .service__item .course_slot label {
    min-width: 165px;
    padding-right: 8px !important;
}
#easyprofile.registration #jform_courses_servis .service__item .course_media .course-media-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 255px;
}
#easyprofile.registration #jform_courses_servis .service__item .course_price input,
#easyprofile.registration #jform_courses_servis .service__item .course_capacity input,
#easyprofile.registration #jform_courses_servis .service__item .course_duration select,
#easyprofile.registration #jform_courses_servis .service__item .course_mode select {
    max-width: 180px !important;
    width: 180px !important;
}
#easyprofile.registration #jform_courses_servis .service__item .course_price input,
#easyprofile.registration #jform_courses_servis .service__item .course_capacity input {
    max-width: 90px !important;
    width: 90px !important;
    text-align: center;
}
#easyprofile.registration #jform_courses_servis .service__item .course_duration select {
    max-width: 90px !important;
    width: 90px !important;
}
#easyprofile.registration #jform_courses_servis .service__item .course_desc textarea,
#easyprofile.registration #jform_courses_servis .service__item .course_title input,
#easyprofile.registration #jform_courses_servis .service__item .course_slot input {
    max-width: 255px !important;
    width: 255px !important;
}
#easyprofile.registration #jform_courses_servis .service__item .course_desc textarea {
    min-height: 96px !important;
    resize: vertical;
}
#easyprofile.registration #jform_courses_servis .service__item .course_media .course-media-file-input {
    max-width: 255px !important;
    width: 255px !important;
}
#easyprofile.registration #jform_courses_servis .service__item .course_media .course-media-current {
    font-size: 13px;
    line-height: 1.35;
    color: #666;
    word-break: break-word;
}
#easyprofile.registration #jform_courses_servis .service__item .stock-remove {
    display: block;
    width: 36px;
    height: 36px;
    border: 1px solid #000;
    border-radius: 50%;
    position: absolute;
    right: -48px;
    top: 0;
    cursor: pointer;
    background: #fff;
}
#easyprofile.registration #jform_courses_servis .service__item .stock-remove::before,
#easyprofile.registration #jform_courses_servis .service__item .stock-remove::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 50%;
    width: 14px;
    height: 2px;
    background: #000;
    transform-origin: center;
}
#easyprofile.registration #jform_courses_servis .service__item .stock-remove::before { transform: translate(-50%, -50%) rotate(45deg); }
#easyprofile.registration #jform_courses_servis .service__item .stock-remove::after { transform: translate(-50%, -50%) rotate(-45deg); }
#easyprofile.registration #jform_courses_servis .service__item .course_mode.is-free .course_slot {
    display: none !important;
}
#easyprofile.registration #jform_searches_servis {
    display: block;
}
#easyprofile.registration #jform_searches_servis > label {
    display: block !important;
    position: relative !important;
    width: 100% !important;
    max-width: 520px !important;
    margin: 0 0 34px !important;
    padding: 0 !important;
    font-size: 16px !important;
    line-height: 1.35 !important;
    font-family: "GothamPro-Medium", sans-serif !important;
    font-weight: 500 !important;
    color: #222 !important;
    cursor: default !important;
}
#easyprofile.registration #jform_searches_servis > label:last-child {
    margin-bottom: 0 !important;
}
#easyprofile.registration #jform_searches_servis > label > b {
    display: inline-block !important;
    width: 32px !important;
    height: 32px !important;
    margin-left: 22px !important;
    vertical-align: middle !important;
    background-repeat: no-repeat !important;
    background-position: center !important;
    background-size: 16px 10px !important;
    border-radius: 50% !important;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08) !important;
    background-color: #fff !important;
}
#easyprofile.registration #jform_searches_servis > label .flex_wrap {
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 0 !important;
    margin-top: 0 !important;
    min-height: 0 !important;
}
#easyprofile.registration #jform_searches_servis > label .service_list {
    display: block !important;
    width: 100% !important;
}
#easyprofile.registration #jform_searches_servis > label .service_list:empty {
    display: none !important;
}
#easyprofile.registration #jform_searches_servis .stock_key,
#easyprofile.registration #jform_searches_servis .service__item .stock-remove {
    width: 37px;
    height: 37px;
    border: 1px solid #000;
    border-radius: 50%;
    cursor: pointer;
    position: relative;
    background: #fff;
}
#easyprofile.registration #jform_searches_servis .stock_key {
    margin: 12px 0 0 !important;
}
#easyprofile.registration #jform_searches_servis .stock_key::before,
#easyprofile.registration #jform_searches_servis .stock_key::after,
#easyprofile.registration #jform_searches_servis .service__item .stock-remove::before,
#easyprofile.registration #jform_searches_servis .service__item .stock-remove::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 50%;
    background: #000;
    transform-origin: center;
}
#easyprofile.registration #jform_searches_servis .stock_key::before {
    width: 14px;
    height: 2px;
    transform: translate(-50%, -50%);
}
#easyprofile.registration #jform_searches_servis .stock_key::after {
    width: 2px;
    height: 14px;
    transform: translate(-50%, -50%);
}
#easyprofile.registration #jform_searches_servis .service__item {
    display: block !important;
    margin: 15px !important;
    padding-bottom: 20px !important;
    position: relative !important;
}
#easyprofile.registration #jform_searches_servis .service__item::after {
    content: "";
    position: absolute;
    display: block;
    background-color: rgba(0, 0, 0, 0.15);
    width: 411px;
    height: 2px;
    bottom: -5px;
}
#easyprofile.registration #jform_searches_servis .service__item .search_desc,
#easyprofile.registration #jform_searches_servis .service__item .search_title,
#easyprofile.registration #jform_searches_servis .service__item .search_media,
#easyprofile.registration #jform_searches_servis .service__item .search_price,
#easyprofile.registration #jform_searches_servis .service__item .search_duration,
#easyprofile.registration #jform_searches_servis .service__item .search_capacity,
#easyprofile.registration #jform_searches_servis .service__item .search_mode,
#easyprofile.registration #jform_searches_servis .service__item .search_slot {
    display: flex !important;
    align-items: center !important;
    padding: 4px 0 !important;
    gap: 8px;
}
#easyprofile.registration #jform_searches_servis .service__item .search_media {
    align-items: flex-start !important;
}
#easyprofile.registration #jform_searches_servis .service__item .search_desc label,
#easyprofile.registration #jform_searches_servis .service__item .search_title label,
#easyprofile.registration #jform_searches_servis .service__item .search_media label,
#easyprofile.registration #jform_searches_servis .service__item .search_price label,
#easyprofile.registration #jform_searches_servis .service__item .search_duration label,
#easyprofile.registration #jform_searches_servis .service__item .search_capacity label,
#easyprofile.registration #jform_searches_servis .service__item .search_mode label,
#easyprofile.registration #jform_searches_servis .service__item .search_slot label {
    min-width: 165px;
    padding-right: 8px !important;
}
#easyprofile.registration #jform_searches_servis .service__item .search_media .search-media-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 255px;
}
#easyprofile.registration #jform_searches_servis .service__item .search_price input,
#easyprofile.registration #jform_searches_servis .service__item .search_capacity input,
#easyprofile.registration #jform_searches_servis .service__item .search_duration select {
    max-width: 90px !important;
    width: 90px !important;
}
#easyprofile.registration #jform_searches_servis .service__item .search_mode select {
    max-width: 180px !important;
    width: 180px !important;
}
#easyprofile.registration #jform_searches_servis .service__item .search_desc textarea,
#easyprofile.registration #jform_searches_servis .service__item .search_title input,
#easyprofile.registration #jform_searches_servis .service__item .search_slot input,
#easyprofile.registration #jform_searches_servis .service__item .search_media .search-media-file-input {
    max-width: 255px !important;
    width: 255px !important;
}
#easyprofile.registration #jform_searches_servis .service__item .search_desc textarea {
    min-height: 96px !important;
    resize: vertical;
}
#easyprofile.registration #jform_searches_servis .service__item .search_media .search-media-current {
    font-size: 13px;
    line-height: 1.35;
    color: #666;
    word-break: break-word;
}
#easyprofile.registration #jform_searches_servis .service__item .stock-remove {
    display: block;
    width: 36px;
    height: 36px;
    position: absolute;
    right: -48px;
    top: 0;
}
#easyprofile.registration #jform_searches_servis .service__item .stock-remove::before,
#easyprofile.registration #jform_searches_servis .service__item .stock-remove::after {
    width: 14px;
    height: 2px;
}
#easyprofile.registration #jform_searches_servis .service__item .stock-remove::before { transform: translate(-50%, -50%) rotate(45deg); }
#easyprofile.registration #jform_searches_servis .service__item .stock-remove::after { transform: translate(-50%, -50%) rotate(-45deg); }
#easyprofile.registration #jform_searches_servis .service__item .search_mode.is-free .search_slot {
    display: none !important;
}
@media (max-width: 820px) {
    #easyprofile.registration #jform_courses_servis > label {
        max-width: 100% !important;
        margin-bottom: 28px !important;
    }
    #easyprofile.registration #jform_courses_servis > label > b {
        margin-left: 12px !important;
    }
    #easyprofile.registration #jform_courses_servis > label .flex_wrap,
    #easyprofile.registration #jform_courses_servis > label .service_list {
        width: 100% !important;
    }
    #easyprofile.registration #jform_courses_servis .service__item {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 12px 0 22px !important;
        box-sizing: border-box !important;
    }
    #easyprofile.registration #jform_courses_servis .service__item::after {
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
    }
    #easyprofile.registration #jform_courses_servis .service__item .course_desc,
    #easyprofile.registration #jform_courses_servis .service__item .course_title,
    #easyprofile.registration #jform_courses_servis .service__item .course_media,
    #easyprofile.registration #jform_courses_servis .service__item .course_price,
    #easyprofile.registration #jform_courses_servis .service__item .course_duration,
    #easyprofile.registration #jform_courses_servis .service__item .course_capacity,
    #easyprofile.registration #jform_courses_servis .service__item .course_mode,
    #easyprofile.registration #jform_courses_servis .service__item .course_slot {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 8px !important;
        padding: 0 0 12px !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    #easyprofile.registration #jform_courses_servis .service__item .course_desc label,
    #easyprofile.registration #jform_courses_servis .service__item .course_title label,
    #easyprofile.registration #jform_courses_servis .service__item .course_media label,
    #easyprofile.registration #jform_courses_servis .service__item .course_price label,
    #easyprofile.registration #jform_courses_servis .service__item .course_duration label,
    #easyprofile.registration #jform_courses_servis .service__item .course_capacity label,
    #easyprofile.registration #jform_courses_servis .service__item .course_mode label,
    #easyprofile.registration #jform_courses_servis .service__item .course_slot label {
        min-width: 0 !important;
        width: 100% !important;
        padding-right: 0 !important;
    }
    #easyprofile.registration #jform_courses_servis .service__item .course_media .course-media-field,
    #easyprofile.registration #jform_courses_servis .service__item .course_desc textarea,
    #easyprofile.registration #jform_courses_servis .service__item .course_title input,
    #easyprofile.registration #jform_courses_servis .service__item .course_media input,
    #easyprofile.registration #jform_courses_servis .service__item .course_slot input,
    #easyprofile.registration #jform_courses_servis .service__item .course_media .course-media-file-input,
    #easyprofile.registration #jform_courses_servis .service__item .course_price input,
    #easyprofile.registration #jform_courses_servis .service__item .course_capacity input,
    #easyprofile.registration #jform_courses_servis .service__item .course_duration select,
    #easyprofile.registration #jform_courses_servis .service__item .course_mode select {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    #easyprofile.registration #jform_courses_servis .service__item .stock-remove {
        position: relative !important;
        right: auto !important;
        top: auto !important;
        margin: 4px 0 0 !important;
    }
    #easyprofile.registration #jform_searches_servis > label {
        max-width: 100% !important;
        margin-bottom: 28px !important;
    }
    #easyprofile.registration #jform_searches_servis > label > b {
        margin-left: 12px !important;
    }
    #easyprofile.registration #jform_searches_servis > label .flex_wrap,
    #easyprofile.registration #jform_searches_servis > label .service_list {
        width: 100% !important;
    }
    #easyprofile.registration #jform_searches_servis .service__item {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 12px 0 22px !important;
        box-sizing: border-box !important;
    }
    #easyprofile.registration #jform_searches_servis .service__item::after {
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
    }
    #easyprofile.registration #jform_searches_servis .service__item .search_desc,
    #easyprofile.registration #jform_searches_servis .service__item .search_title,
    #easyprofile.registration #jform_searches_servis .service__item .search_media,
    #easyprofile.registration #jform_searches_servis .service__item .search_price,
    #easyprofile.registration #jform_searches_servis .service__item .search_duration,
    #easyprofile.registration #jform_searches_servis .service__item .search_capacity,
    #easyprofile.registration #jform_searches_servis .service__item .search_mode,
    #easyprofile.registration #jform_searches_servis .service__item .search_slot {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 8px !important;
        padding: 0 0 12px !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    #easyprofile.registration #jform_searches_servis .service__item .search_desc label,
    #easyprofile.registration #jform_searches_servis .service__item .search_title label,
    #easyprofile.registration #jform_searches_servis .service__item .search_media label,
    #easyprofile.registration #jform_searches_servis .service__item .search_price label,
    #easyprofile.registration #jform_searches_servis .service__item .search_duration label,
    #easyprofile.registration #jform_searches_servis .service__item .search_capacity label,
    #easyprofile.registration #jform_searches_servis .service__item .search_mode label,
    #easyprofile.registration #jform_searches_servis .service__item .search_slot label {
        min-width: 0 !important;
        width: 100% !important;
        padding-right: 0 !important;
    }
    #easyprofile.registration #jform_searches_servis .service__item .search_media .search-media-field,
    #easyprofile.registration #jform_searches_servis .service__item .search_desc textarea,
    #easyprofile.registration #jform_searches_servis .service__item .search_title input,
    #easyprofile.registration #jform_searches_servis .service__item .search_media input,
    #easyprofile.registration #jform_searches_servis .service__item .search_slot input,
    #easyprofile.registration #jform_searches_servis .service__item .search_media .search-media-file-input,
    #easyprofile.registration #jform_searches_servis .service__item .search_price input,
    #easyprofile.registration #jform_searches_servis .service__item .search_capacity input,
    #easyprofile.registration #jform_searches_servis .service__item .search_duration select,
    #easyprofile.registration #jform_searches_servis .service__item .search_mode select {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    #easyprofile.registration #jform_searches_servis .service__item .stock-remove {
        position: relative !important;
        right: auto !important;
        top: auto !important;
        margin: 4px 0 0 !important;
    }
}
</style>

<script>
window.viglingRegistrationSpecialties = <?php echo $specialtiesJson ?: '[]'; ?>;
window.viglingRegistrationServicesByCategory = <?php echo $servicesJson ?: '{}'; ?>;
window.viglingRegistrationDurations = <?php echo $durationJson ?: '[]'; ?>;

document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;
    if (!$) {
        return;
    }

    window.Joomla = window.Joomla || {};
    if (typeof window.Joomla.renderMessages !== 'function') {
        window.Joomla.renderMessages = function () {};
    }

    var form = $('#member-registration');
    if (!form.length) {
        return;
    }

    var typeButtons = $('.reg-role-btn');
    var typeSwitch = $('#registration-type-switch');
    var tabsNav = $('#jsn-profile-tabs');
    var tabsContainer = $('#registration-tabs-container');
    var tabContents = tabsContainer.find('.z-content');
    var tabsRoot = $('#jsn-form');
    var controlsBar = $('.jsn_registration_controls');
    var typeInput = $('#jform_registration_type');
    var isMasterInput = $('#jform_is_master');
    var emailInput = $('#jform_email1');
    var usernameInput = $('#jform_username');
    var phoneInput = $('#jform_telefon');
    var avatarInput = $('#jform_upload_avatar');
    var avatarImage = $('.avatar-group .img_avatar');
    var portfolioInput = $('#jform_upload_portfolio_field');
    var portfolioGroup = $('#jsn_portfolio .portfolio_field-group');
    var MAX_PORTFOLIO_FILES = 10;
    var MAX_IMAGE_SIZE_BYTES = 25 * 1024 * 1024;
    var selectedPortfolioFiles = [];
    var storageKey = 'vigling_registration_state_v3';
    var storageKeys = ['vigling_registration_state_v3', 'vigling_registration_state_v2', 'vigling_registration_state_v1'];

    var tabsByType = {
        client: ['jsn_default', 'jsn_login'],
        master: ['jsn_default', 'jsn_portfolio', 'jsn_spetsialnost', 'jsn_addinfo', 'jsn_courses', 'jsn_searches', 'jsn_login', 'jsn_raspisanie'],
        zatochka_remont: ['jsn_default', 'jsn_portfolio', 'jsn_spetsialnost', 'jsn_addinfo', 'jsn_courses', 'jsn_searches', 'jsn_login', 'jsn_raspisanie']
    };

    var masterValueByType = {
        client: '0',
        master: '1',
        zatochka_remont: '2'
    };

    var currentType = typeInput.val() || 'client';
    var currentTab = 'jsn_default';
    var typeSelectionLocked = <?php echo $hasSubmittedData ? 'true' : 'false'; ?>;
    var hasSubmittedData = <?php echo $hasSubmittedData ? 'true' : 'false'; ?>;
    var recaptchaSubmitBypass = false;
    var recaptchaSubmitInFlight = false;

    function safeParseJson(raw, fallback) {
        try {
            return JSON.parse(raw);
        } catch (e) {
            return fallback;
        }
    }

    function tabsForCurrentType() {
        return tabsByType[currentType] || tabsByType.client;
    }

    function showRecaptchaError() {
        var message = 'Подтвердите, что вы не робот';
        if (window.ViglingNotify && typeof window.ViglingNotify.error === 'function') {
            window.ViglingNotify.error(message, { timeout: 6000 });
            return;
        }
        if (window.Joomla && typeof window.Joomla.renderMessages === 'function') {
            window.Joomla.renderMessages({ error: [message] });
            return;
        }
        window.alert(message);
    }

    function setTab(tabId) {
        var allowed = tabsForCurrentType();
        if (allowed.indexOf(tabId) === -1) {
            tabId = allowed[0];
        }
        currentTab = tabId;

        tabsNav.find('li').each(function () {
            var li = $(this);
            var id = String(li.data('tab'));
            if (allowed.indexOf(id) === -1) {
                li.hide();
            } else {
                li.show();
                li.toggleClass('z-active', id === tabId);
            }
        });

        tabContents.each(function () {
            var block = $(this);
            var id = String(block.data('tab'));
            var isVisible = id === tabId && allowed.indexOf(id) !== -1;
            block.toggleClass('z-active', isVisible);
            if (isVisible) {
                block.css({
                    display: 'block',
                    position: 'relative',
                    left: '0',
                    top: '0',
                    opacity: '1',
                    height: 'auto',
                    overflow: 'visible'
                });
            } else {
                block.css({
                    display: 'none',
                    position: 'absolute',
                    left: '0',
                    top: '0',
                    opacity: '0',
                    height: '100%',
                    overflow: 'hidden'
                });
            }
        });

        updateTabsContainerHeight();
        if (tabId === 'jsn_login') {
            $('#jsn_login .login-group, #jsn_login .password1-group, #jsn_login .password2-group').css('display', 'block');
            $('#jsn_login .privacy-consent-group').css({
                display: 'flex',
                visibility: 'visible',
                opacity: '1'
            });
            updateTabsContainerHeight();
        }

        syncStepButtons();
        syncPrivacyConsentError();
        persistDraftState();
    }

    function updateTabsContainerHeight() {
        var activeBlock = tabsContainer.find('.z-content.z-active');
        if (!activeBlock.length) {
            tabsContainer.css('height', 'auto');
            return;
        }
        window.requestAnimationFrame(function () {
            tabsContainer.css('height', Math.ceil(activeBlock.outerHeight(true)) + 'px');
        });
    }

    function normalizeRegistrationCancelLink() {
        var formEl = form.get(0);
        var links = $('[id="reg-cancel"]');
        if (links.length > 1) {
            links.slice(1).remove();
        }
        var link = $('#reg-cancel').first();
        if (!link.length) {
            return;
        }
        link.find('input').each(function () {
            if (formEl) {
                formEl.appendChild(this);
            }
        });
        if ($.trim(link.text()) === '') {
            link.text('Отменить');
        }
    }

    function stripStrayRegistrationControlText() {
        var root = controlsBar.get(0);
        if (!root) {
            return;
        }
        var node = root.firstChild;
        while (node) {
            var next = node.nextSibling;
            if (node.nodeType === 3 && String(node.nodeValue || '').replace(/\s+/g, '') !== '') {
                root.removeChild(node);
            }
            node = next;
        }
        normalizeRegistrationCancelLink();
    }

    function isFinalRegistrationStep() {
        var allowed = tabsForCurrentType();
        return allowed.indexOf(currentTab) === allowed.length - 1;
    }

    function syncStepButtons() {
        var allowed = tabsForCurrentType();
        var idx = allowed.indexOf(currentTab);
        var isFinal = isFinalRegistrationStep();
        var isLoginStep = currentTab === 'jsn_login';

        $('#reg-prev-step').toggle(idx > 0);
        controlsBar.toggleClass('is-final-step', isFinal);
        controlsBar.toggleClass('is-login-step', isLoginStep);

        if (isFinal) {
            $('#reg-next-step').attr('hidden', true);
            $('#reg-submit-block').removeAttr('hidden');
            $('#reg-submit').prop('disabled', false).removeAttr('hidden');
        } else {
            $('#reg-next-step').removeAttr('hidden');
            $('#reg-submit-block').attr('hidden', true);
            $('#reg-submit').prop('disabled', true);
            hidePrivacyConsentError();
        }
        stripStrayRegistrationControlText();
    }

    function syncTypeButtons() {
        typeButtons.each(function () {
            var btn = $(this);
            btn.toggleClass('active', btn.data('type') === currentType);
        });
    }

    function lockTypeSelection() {
        typeSelectionLocked = true;
        typeSwitch.hide();
        tabsRoot.show();
        controlsBar.show();
        setTab(tabsForCurrentType()[0]);
    }

    function setMasterFieldsRequired(isRequired) {
        var fields = ['#jform_area', '#jform_street', '#jform_house_number'];
        fields.forEach(function (selector) {
            var el = $(selector);
            if (!el.length) {
                return;
            }
            el.prop('required', isRequired);
            el.closest('.control-group').toggleClass('required', isRequired);
        });
    }

    function filterSpecialtiesByType() {
        var isRepair = currentType === 'zatochka_remont';
        $('#jform_vyberite_spetsialnos > label').each(function () {
            var item = $(this);
            var type = item.data('type');
            var isVisible = (isRepair && type === 'repair') || (!isRepair && type === 'beauty');
            item.toggle(isVisible);
            if (!isVisible) {
                item.find('input[type="checkbox"]').prop('checked', false);
                item.removeClass('active');
            }
        });
        syncSpecialtyActiveState();
        renderServiceBuilders();
        renderCourseBuilders();
        renderSearchBuilders();
    }

    function syncSpecialtyActiveState() {
        $('#jform_vyberite_spetsialnos > label').each(function () {
            var item = $(this);
            var checked = item.find('input[type="checkbox"]').prop('checked');
            item.toggleClass('active', !!checked);
        });
    }

    function setType(nextType) {
        if (!tabsByType[nextType]) {
            nextType = 'client';
        }
        currentType = nextType;
        typeInput.val(nextType);
        isMasterInput.val(masterValueByType[nextType] || '0');

        var isClient = nextType === 'client';
        $('.master-only-field').toggle(!isClient);
        setMasterFieldsRequired(!isClient);
        syncTypeButtons();
        filterSpecialtiesByType();
        setTab(tabsForCurrentType()[0]);
        persistDraftState();
    }

    function selectedSpecialtyIds() {
        var ids = [];
        $('#jform_vyberite_spetsialnos input[type="checkbox"]:checked').each(function () {
            var id = parseInt(this.value, 10);
            if (!isNaN(id)) {
                ids.push(id);
            }
        });
        return ids;
    }

    function serviceOptionsHtml(categoryId) {
        var servicesMap = window.viglingRegistrationServicesByCategory || {};
        var items = servicesMap[String(categoryId)] || servicesMap[categoryId] || [];
        var html = '<option value="">Выберите услугу...</option>';
        items.forEach(function (service) {
            if (Array.isArray(service.tags) && service.tags.length) {
                service.tags.forEach(function (tag) {
                    html += '<option value="' + service.id + '-' + tag.id + '">' + service.title + ' / ' + tag.title + '</option>';
                });
            } else {
                html += '<option value="' + service.id + '">' + service.title + '</option>';
            }
        });
        return html;
    }

    function durationOptionsHtml() {
        var values = window.viglingRegistrationDurations || [];
        var html = '';
        values.forEach(function (v) {
            html += '<option value="' + v + '">' + v + '</option>';
        });
        return html;
    }

    function renderServiceBuilders() {
        var holder = $('#jform_vyberite_usl');
        var ids = selectedSpecialtyIds();

        if (!ids.length) {
            holder.html('Выберите специальность, чтобы добавить услугу');
            updateTabsContainerHeight();
            return;
        }

        var labelsById = {};
        $('#jform_vyberite_spetsialnos input[type="checkbox"]').each(function () {
            labelsById[parseInt(this.value, 10)] = $(this).closest('label').text().trim();
        });

        var html = '';
        ids.forEach(function (catId) {
            html += '<label class="checkbox type_master_open" data-id="' + catId + '">';
            html += labelsById[catId] || ('Категория #' + catId);
            html += '<b></b>';
            html += '<div class="flex_wrap">';
            html += '<div class="service_list"></div>';
            html += '<button type="button" class="btn-add-service dale">Добавить услугу</button>';
            html += '</div>';
            html += '</label>';
        });

        holder.html(html);
        updateTabsContainerHeight();
        persistDraftState();
    }

    function renderCourseBuilders() {
        var holder = $('#jform_courses_servis');
        var ids = selectedSpecialtyIds();

        if (!holder.length) {
            return;
        }

        if (!ids.length) {
            holder.html('Выберите специальность, чтобы добавить курс');
            updateTabsContainerHeight();
            return;
        }

        var labelsById = {};
        $('#jform_vyberite_spetsialnos input[type="checkbox"]').each(function () {
            labelsById[parseInt(this.value, 10)] = $(this).closest('label').text().trim();
        });

        var html = '';
        ids.forEach(function (catId) {
            html += '<label class="checkbox type_master_open type_master_closed" data-id="' + catId + '">';
            html += labelsById[catId] || ('Категория #' + catId);
            html += '<b></b>';
            html += '<div class="flex_wrap">';
            html += '<div class="service_list"></div>';
            html += '<div class="stock_key" title="Добавить курс"></div>';
            html += '</div>';
            html += '</label>';
        });

        holder.html(html);
        updateTabsContainerHeight();
        persistDraftState();
    }

    function renderSearchBuilders() {
        var holder = $('#jform_searches_servis');
        var ids = selectedSpecialtyIds();

        if (!holder.length) {
            return;
        }

        if (!ids.length) {
            holder.html('Выберите специальность, чтобы добавить поиск');
            updateTabsContainerHeight();
            return;
        }

        var labelsById = {};
        $('#jform_vyberite_spetsialnos input[type="checkbox"]').each(function () {
            labelsById[parseInt(this.value, 10)] = $(this).closest('label').text().trim();
        });

        var html = '';
        ids.forEach(function (catId) {
            html += '<label class="checkbox type_master_open type_master_closed" data-id="' + catId + '">';
            html += labelsById[catId] || ('Категория #' + catId);
            html += '<b></b>';
            html += '<div class="flex_wrap">';
            html += '<div class="service_list"></div>';
            html += '<div class="stock_key" title="Добавить поиск"></div>';
            html += '</div>';
            html += '</label>';
        });

        holder.html(html);
        updateTabsContainerHeight();
        persistDraftState();
    }

    function basenameFromPath(path) {
        var raw = String(path || '').trim();
        if (!raw) {
            return '';
        }
        var parts = raw.split('/');
        return parts.length ? parts[parts.length - 1] : raw;
    }

    function formatDatetimeLocal(value) {
        var raw = String(value || '').trim();
        if (!raw) {
            return '';
        }
        if (raw.indexOf('T') !== -1 && raw.indexOf(' ') === -1) {
            return raw.slice(0, 16);
        }
        var iso = raw.replace(' ', 'T');
        if (iso.length === 16) {
            iso += ':00';
        }
        var date = new Date(iso + 'Z');
        if (Number.isNaN(date.getTime())) {
            return raw.replace(' ', 'T').slice(0, 16);
        }
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        var h = String(date.getHours()).padStart(2, '0');
        var i = String(date.getMinutes()).padStart(2, '0');
        return y + '-' + m + '-' + d + 'T' + h + ':' + i;
    }

    function normalizeDatetimeFromLocal(value) {
        var raw = String(value || '').trim();
        if (!raw) {
            return '';
        }
        var date = new Date(raw);
        if (Number.isNaN(date.getTime())) {
            return raw.replace('T', ' ') + ':00';
        }
        var y = date.getUTCFullYear();
        var m = String(date.getUTCMonth() + 1).padStart(2, '0');
        var d = String(date.getUTCDate()).padStart(2, '0');
        var h = String(date.getUTCHours()).padStart(2, '0');
        var i = String(date.getUTCMinutes()).padStart(2, '0');
        var s = String(date.getUTCSeconds()).padStart(2, '0');
        return y + '-' + m + '-' + d + ' ' + h + ':' + i + ':' + s;
    }

    function syncCourseModeState(row) {
        if (!row || !row.length) {
            return;
        }
        var modeSelect = row.find('.course-mode-select');
        var modeWrap = row.find('.course_mode');
        var slotInput = row.find('.course-slot-input');
        var mode = String(modeSelect.val() || 'free');
        modeWrap.toggleClass('is-free', mode !== 'fixed');
        if (mode !== 'fixed') {
            slotInput.val('');
        }
    }

    function syncCourseMediaState(row) {
        if (!row || !row.length) {
            return;
        }
        var fileInput = row.find('.course-media-file-input');
        var hiddenInput = row.find('.course-media-input');
        var currentNode = row.find('.course-media-current');
        var file = fileInput.length && fileInput[0].files && fileInput[0].files.length ? fileInput[0].files[0] : null;
        if (file && file.name) {
            currentNode.text('Новый файл: ' + file.name);
            return;
        }
        var existingPath = String(hiddenInput.val() || '').trim();
        currentNode.text(existingPath ? ('Текущий файл: ' + basenameFromPath(existingPath)) : 'Файл не выбран');
    }

    function syncSearchModeState(row) {
        if (!row || !row.length) {
            return;
        }
        var modeSelect = row.find('.search-mode-select');
        var modeWrap = row.find('.search_mode');
        var slotInput = row.find('.search-slot-input');
        var mode = String(modeSelect.val() || 'free');
        modeWrap.toggleClass('is-free', mode !== 'fixed');
        if (mode !== 'fixed') {
            slotInput.val('');
        }
    }

    function syncSearchMediaState(row) {
        if (!row || !row.length) {
            return;
        }
        var fileInput = row.find('.search-media-file-input');
        var hiddenInput = row.find('.search-media-input');
        var currentNode = row.find('.search-media-current');
        var file = fileInput.length && fileInput[0].files && fileInput[0].files.length ? fileInput[0].files[0] : null;
        if (file && file.name) {
            currentNode.text('Новый файл: ' + file.name);
            return;
        }
        var existingPath = String(hiddenInput.val() || '').trim();
        currentNode.text(existingPath ? ('Текущий файл: ' + basenameFromPath(existingPath)) : 'Файл не выбран');
    }

    function addServiceRow(categoryLabel) {
        var catId = parseInt(categoryLabel.data('id'), 10);
        if (!catId) {
            return;
        }

        var row = $('<p class="service__item">' +
            '<select class="service-select">' + serviceOptionsHtml(catId) + '</select>' +
            '<span class="time"><label>Время:</label><select class="time-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
            '<span class="time2"><label>Перерыв:</label><select class="pause-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
            '<span class="price"><label>Стоимость:</label><input type="number" min="0" step="1" class="price-input" value="" /></span>' +
            '<button type="button" class="btn-remove-service">Удалить</button>' +
        '</p>');

        row.attr('data-category-id', String(catId));
        row.attr('data-course-id', '0');
        categoryLabel.find('.service_list').append(row);
        updateTabsContainerHeight();
        persistDraftState();
    }

    function addCourseRow(categoryLabel) {
        var catId = parseInt(categoryLabel.data('id'), 10);
        if (!catId) {
            return;
        }

        var row = $('<p class="service__item">' +
            '<span class="course_title"><label>Название курса:</label><input type="text" maxlength="150" class="course-title-input" value="" /></span>' +
            '<span class="course_desc"><label>Описание:</label><textarea maxlength="150" placeholder="До 150 символов" class="course-description-input"></textarea></span>' +
            '<span class="course_media"><label>Изображение:</label><span class="course-media-field"><input type="hidden" class="course-media-input" value="" /><input type="file" name="jform[upload_course_media][]" accept="image/*" class="course-media-file-input" /><span class="course-media-current">Файл не выбран</span></span></span>' +
            '<span class="course_price"><label>Стоимость:</label><input type="number" min="0" step="1" class="course-price-input" value="" /></span>' +
            '<span class="course_duration"><label>Длительность:</label><select class="course-duration-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
            '<span class="course_capacity"><label>Лимит мест:</label><input type="number" min="1" step="1" class="course-capacity-input" value="1" /></span>' +
            '<span class="course_mode"><label>Режим записи:</label><select class="course-mode-select"><option value="free">Любое время</option><option value="fixed">Фиксированная дата</option></select><span class="course_slot"><label>Дата и время:</label><input type="datetime-local" class="course-slot-input" value="" /></span></span>' +
            '<i class="stock-remove" title="Удалить"></i>' +
        '</p>');

        row.attr('data-category-id', String(catId));
        categoryLabel.find('.service_list').append(row);
        syncCourseModeState(row);
        syncCourseMediaState(row);
        updateTabsContainerHeight();
        persistDraftState();
    }

    function addSearchRow(categoryLabel) {
        var catId = parseInt(categoryLabel.data('id'), 10);
        if (!catId) {
            return;
        }

        var row = $('<p class="service__item">' +
            '<span class="search_title"><label>Название поиска:</label><input type="text" maxlength="150" class="search-title-input" value="" /></span>' +
            '<span class="search_desc"><label>Описание:</label><textarea maxlength="150" placeholder="До 150 символов" class="search-description-input"></textarea></span>' +
            '<span class="search_media"><label>Изображение:</label><span class="search-media-field"><input type="hidden" class="search-media-input" value="" /><input type="file" name="jform[upload_search_media][]" accept="image/*" class="search-media-file-input" /><span class="search-media-current">Файл не выбран</span></span></span>' +
            '<span class="search_price"><label>Стоимость:</label><input type="number" min="0" step="1" class="search-price-input" value="" /></span>' +
            '<span class="search_duration"><label>Длительность:</label><select class="search-duration-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
            '<span class="search_capacity"><label>Лимит мест:</label><input type="number" min="1" step="1" class="search-capacity-input" value="1" /></span>' +
            '<span class="search_mode"><label>Режим записи:</label><select class="search-mode-select"><option value="free">Любое время</option><option value="fixed">Фиксированная дата</option></select><span class="search_slot"><label>Дата и время:</label><input type="datetime-local" class="search-slot-input" value="" /></span></span>' +
            '<i class="stock-remove" title="Удалить"></i>' +
        '</p>');

        row.attr('data-category-id', String(catId));
        categoryLabel.find('.service_list').append(row);
        syncSearchModeState(row);
        syncSearchMediaState(row);
        updateTabsContainerHeight();
        persistDraftState();
    }

    function serializeServiceRows() {
        var items = [];
        $('#jform_vyberite_usl .service__item').each(function () {
            var row = $(this);
            items.push({
                id: parseInt(row.attr('data-course-id') || '0', 10) || 0,
                categoryId: parseInt(row.attr('data-category-id') || '0', 10) || 0,
                serviceRaw: String(row.find('.service-select').val() || ''),
                duration: String(row.find('.time-select').val() || ''),
                pause: String(row.find('.pause-select').val() || ''),
                price: String(row.find('.price-input').val() || '')
            });
        });
        return items;
    }

    function serializeCourseRows() {
        var items = [];
        $('#jform_courses_servis .service__item').each(function () {
            var row = $(this);
            items.push({
                categoryId: parseInt(row.attr('data-category-id') || '0', 10) || 0,
                title: String(row.find('.course-title-input').val() || ''),
                description: String(row.find('.course-description-input').val() || ''),
                mediaPath: String(row.find('.course-media-input').val() || ''),
                price: parseInt(row.find('.course-price-input').val() || '0', 10) || 0,
                duration: parseInt(row.find('.course-duration-select').val() || '0', 10) || 0,
                capacity: parseInt(row.find('.course-capacity-input').val() || '1', 10) || 1,
                bookingMode: String(row.find('.course-mode-select').val() || 'free'),
                slotStartLocal: String(row.find('.course-slot-input').val() || ''),
                slotStartUtc: normalizeDatetimeFromLocal(String(row.find('.course-slot-input').val() || ''))
            });
        });
        return items;
    }

    function serializeSearchRows() {
        var items = [];
        $('#jform_searches_servis .service__item').each(function () {
            var row = $(this);
            items.push({
                categoryId: parseInt(row.attr('data-category-id') || '0', 10) || 0,
                title: String(row.find('.search-title-input').val() || ''),
                description: String(row.find('.search-description-input').val() || ''),
                mediaPath: String(row.find('.search-media-input').val() || ''),
                price: parseInt(row.find('.search-price-input').val() || '0', 10) || 0,
                duration: parseInt(row.find('.search-duration-select').val() || '0', 10) || 0,
                capacity: parseInt(row.find('.search-capacity-input').val() || '1', 10) || 1,
                bookingMode: String(row.find('.search-mode-select').val() || 'free'),
                slotStartLocal: String(row.find('.search-slot-input').val() || ''),
                slotStartUtc: normalizeDatetimeFromLocal(String(row.find('.search-slot-input').val() || ''))
            });
        });
        return items;
    }

    function buildCoursePayload() {
        return {
            version: 2,
            items: serializeCourseRows()
                .filter(function (row) {
                    return row.categoryId && String(row.title || '').trim() && row.price > 0 && row.duration > 0;
                })
                .map(function (row) {
                    var slotStartUtc = String(row.slotStartUtc || '').trim();
                    return {
                        id: parseInt(row.id || 0, 10) || 0,
                        category_id: row.categoryId,
                        title: String(row.title || '').trim(),
                        description: String(row.description || '').trim(),
                        media_path: String(row.mediaPath || '').trim(),
                        price: row.price,
                        duration_min: row.duration,
                        capacity: Math.max(1, parseInt(row.capacity || 1, 10) || 1),
                        booking_mode: String(row.bookingMode || 'free') === 'fixed' ? 'fixed' : 'free',
                        slot_start_utc: String(row.bookingMode || 'free') === 'fixed' && slotStartUtc !== ''
                            ? slotStartUtc
                            : '',
                        slot_start_local: String(row.bookingMode || 'free') === 'fixed'
                            ? String(row.slotStartLocal || '').trim()
                            : ''
                    };
                })
        };
    }

    function buildSearchPayload() {
        return {
            version: 1,
            items: serializeSearchRows()
                .filter(function (row) {
                    return row.categoryId && String(row.title || '').trim() && row.price > 0 && row.duration > 0;
                })
                .map(function (row) {
                    var slotStartUtc = String(row.slotStartUtc || '').trim();
                    return {
                        id: parseInt(row.id || 0, 10) || 0,
                        category_id: row.categoryId,
                        title: String(row.title || '').trim(),
                        description: String(row.description || '').trim(),
                        media_path: String(row.mediaPath || '').trim(),
                        price: row.price,
                        duration_min: row.duration,
                        capacity: Math.max(1, parseInt(row.capacity || 1, 10) || 1),
                        booking_mode: String(row.bookingMode || 'free') === 'fixed' ? 'fixed' : 'free',
                        slot_start_utc: String(row.bookingMode || 'free') === 'fixed' && slotStartUtc !== ''
                            ? slotStartUtc
                            : '',
                        slot_start_local: String(row.bookingMode || 'free') === 'fixed'
                            ? String(row.slotStartLocal || '').trim()
                            : ''
                    };
                })
        };
    }

    function syncCoursePayloadInput() {
        $('#jform_vigling_courses_payload').val(JSON.stringify(buildCoursePayload()));
    }

    function syncSearchPayloadInput() {
        $('#jform_vigling_searches_payload').val(JSON.stringify(buildSearchPayload()));
    }

    function applyServiceRows(items) {
        if (!Array.isArray(items) || !items.length) {
            return;
        }
        items.forEach(function (item) {
            var catId = parseInt(item.categoryId || 0, 10);
            if (!catId) {
                return;
            }
            var label = $('#jform_vyberite_usl > label[data-id="' + catId + '"]');
            if (!label.length) {
                return;
            }
            addServiceRow(label);
            var row = label.find('.service__item').last();
            if (!row.length) {
                return;
            }
            row.find('.service-select').val(String(item.serviceRaw || ''));
            row.find('.time-select').val(String(item.duration || '15'));
            row.find('.pause-select').val(String(item.pause || '15'));
            row.find('.price-input').val(String(item.price || ''));
        });
        updateTabsContainerHeight();
    }

    function applyCourseRows(items) {
        if (!Array.isArray(items) || !items.length) {
            return;
        }
        items.forEach(function (item) {
            var catId = parseInt(item.categoryId || 0, 10);
            if (!catId) {
                return;
            }
            var label = $('#jform_courses_servis > label[data-id="' + catId + '"]');
            if (!label.length) {
                return;
            }
            addCourseRow(label);
            var row = label.find('.service__item').last();
            if (!row.length) {
                return;
            }
            row.attr('data-course-id', String(parseInt(item.id || 0, 10) || 0));
            row.find('.course-title-input').val(String(item.title || ''));
            row.find('.course-description-input').val(String(item.description || ''));
            row.find('.course-media-input').val(String(item.mediaPath || ''));
            row.find('.course-price-input').val(String(parseInt(item.price || '0', 10) || 0));
            row.find('.course-duration-select').val(String(parseInt(item.duration || '0', 10) || 0));
            row.find('.course-capacity-input').val(String(Math.max(1, parseInt(item.capacity || '1', 10) || 1)));
            row.find('.course-mode-select').val(String(item.bookingMode || 'free'));
            row.find('.course-slot-input').val(formatDatetimeLocal(String(item.slotStartUtc || '')));
            syncCourseModeState(row);
            syncCourseMediaState(row);
        });
        updateTabsContainerHeight();
    }

    function applySearchRows(items) {
        if (!Array.isArray(items) || !items.length) {
            return;
        }
        items.forEach(function (item) {
            var catId = parseInt(item.categoryId || 0, 10);
            if (!catId) {
                return;
            }
            var label = $('#jform_searches_servis > label[data-id="' + catId + '"]');
            if (!label.length) {
                return;
            }
            addSearchRow(label);
            var row = label.find('.service__item').last();
            if (!row.length) {
                return;
            }
            row.attr('data-search-id', String(parseInt(item.id || 0, 10) || 0));
            row.find('.search-title-input').val(String(item.title || ''));
            row.find('.search-description-input').val(String(item.description || ''));
            row.find('.search-media-input').val(String(item.mediaPath || ''));
            row.find('.search-price-input').val(String(parseInt(item.price || '0', 10) || 0));
            row.find('.search-duration-select').val(String(parseInt(item.duration || '0', 10) || 0));
            row.find('.search-capacity-input').val(String(Math.max(1, parseInt(item.capacity || '1', 10) || 1)));
            row.find('.search-mode-select').val(String(item.bookingMode || 'free'));
            row.find('.search-slot-input').val(formatDatetimeLocal(String(item.slotStartUtc || '')));
            syncSearchModeState(row);
            syncSearchMediaState(row);
        });
        updateTabsContainerHeight();
    }

    function collectDraftState() {
        var selectedSpecs = [];
        $('#jform_vyberite_spetsialnos input[type="checkbox"]:checked').each(function () {
            selectedSpecs.push(parseInt(this.value, 10));
        });

        var selectedDays = [];
        $('#jform_work_day input[type="checkbox"]:checked').each(function () {
            selectedDays.push(parseInt(this.value, 10));
        });

        return {
            registrationType: currentType,
            locked: typeSelectionLocked,
            activeTab: currentTab,
            fields: {
                name: $('#jform_name').val() || '',
                lastname: $('#jform_lastname').val() || '',
                phone: $('#jform_telefon').val() || '',
                email: $('#jform_email1').val() || '',
                city: $('#jform_sity').val() || '',
                region: $('#jform_area').val() || '',
                address1: $('#jform_street').val() || '',
                address2: $('#jform_house_number').val() || '',
                website: $('#jform_link').val() || '',
                telegram: $('#jform_telegram').val() || '',
                max: $('#jform_max').val() || '',
                aboutme: $('#jform_o_sebe').val() || '',
                workFrom: $('#jform_work_from').val() || '',
                workTo: $('#jform_work_to').val() || ''
            },
            passwordFields: {
                password1: $('#jform_password1').val() || '',
                password2: $('#jform_password2').val() || ''
            },
            privacyConsent: $('#privacy_consent').prop('checked') === true,
            specialties: selectedSpecs,
            workDays: selectedDays,
            services: serializeServiceRows(),
            courses: serializeCourseRows(),
            searches: serializeSearchRows()
        };
    }

    function persistDraftState() {
        syncCoursePayloadInput();
        syncSearchPayloadInput();
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(collectDraftState()));
        } catch (e) {
            // ignore storage errors
        }
    }

    function clearDraftState() {
        storageKeys.forEach(function (key) {
            try {
                window.sessionStorage.removeItem(key);
            } catch (e) {
                // ignore storage errors
            }
            try {
                window.localStorage.removeItem(key);
            } catch (e) {
                // ignore storage errors
            }
        });
    }

    function getNavigationType() {
        try {
            if (window.performance && typeof window.performance.getEntriesByType === 'function') {
                var entries = window.performance.getEntriesByType('navigation');
                if (entries && entries.length && entries[0] && entries[0].type) {
                    return String(entries[0].type);
                }
            }
            if (window.performance && window.performance.navigation) {
                var nav = window.performance.navigation.type;
                if (nav === 1) {
                    return 'reload';
                }
                if (nav === 2) {
                    return 'back_forward';
                }
                return 'navigate';
            }
        } catch (e) {
            // ignore unsupported performance API
        }
        return 'navigate';
    }

    function shouldRestoreDraftStateOnLoad() {
        return getNavigationType() === 'reload';
    }

    function restoreDraftState() {
        var raw = '';
        try {
            raw = window.sessionStorage.getItem(storageKey) || '';
        } catch (e) {
            raw = '';
        }
        if (!raw) {
            return false;
        }
        var draft = safeParseJson(raw, null);
        if (!draft || typeof draft !== 'object') {
            return false;
        }

        var restoredType = String(draft.registrationType || 'client');
        if (!tabsByType[restoredType]) {
            restoredType = 'client';
        }
        currentType = restoredType;
        typeInput.val(restoredType);
        isMasterInput.val(masterValueByType[restoredType] || '0');
        setType(restoredType);

        var fields = draft.fields || {};
        $('#jform_name').val(fields.name || '');
        $('#jform_lastname').val(fields.lastname || '');
        $('#jform_telefon').val(fields.phone || '');
        $('#jform_email1').val(fields.email || '');
        $('#jform_sity').val(fields.city || '');
        $('#jform_area').val(fields.region || '');
        $('#jform_street').val(fields.address1 || '');
        $('#jform_house_number').val(fields.address2 || '');
        $('#jform_link').val(fields.website || '');
        $('#jform_telegram').val(fields.telegram || '');
        $('#jform_max').val(fields.max || '');
        $('#jform_o_sebe').val(fields.aboutme || '');
        $('#jform_work_from').val(fields.workFrom || '');
        $('#jform_work_to').val(fields.workTo || '');

        var passwordFields = draft.passwordFields || {};
        $('#jform_password1').val(passwordFields.password1 || '');
        $('#jform_password2').val(passwordFields.password2 || '');
        $('#privacy_consent').prop('checked', draft.privacyConsent === true);

        $('#jform_vyberite_spetsialnos input[type="checkbox"]').prop('checked', false);
        if (Array.isArray(draft.specialties)) {
            draft.specialties.forEach(function (id) {
                $('#jform_vyberite_spetsialnos input[type="checkbox"][value="' + parseInt(id, 10) + '"]').prop('checked', true);
            });
        }
        syncSpecialtyActiveState();
        renderServiceBuilders();
        renderCourseBuilders();
        renderSearchBuilders();
        applyServiceRows(draft.services || []);
        applyCourseRows(draft.courses || []);
        applySearchRows(draft.searches || []);

        $('#jform_work_day input[type="checkbox"]').prop('checked', false);
        if (Array.isArray(draft.workDays) && draft.workDays.length) {
            draft.workDays.forEach(function (id) {
                $('#jform_work_day input[type="checkbox"][value="' + parseInt(id, 10) + '"]').prop('checked', true);
            });
        }

        if (draft.locked) {
            lockTypeSelection();
            var allowed = tabsForCurrentType();
            var tabToOpen = String(draft.activeTab || allowed[0]);
            if (allowed.indexOf(tabToOpen) === -1) {
                tabToOpen = allowed[0];
            }
            setTab(tabToOpen);
        }

        syncUsernameWithEmail();
        updateTabsContainerHeight();
        return true;
    }

    function buildLegacyPricesFromRows(rows) {
        var grouped = {};
        rows.each(function () {
            var row = $(this);
            var catId = String(row.data('category-id') || '');
            var serviceRaw = String(row.find('.service-select').val() || '');
            var duration = parseInt(row.find('.time-select').val() || '0', 10);
            var pause = parseInt(row.find('.pause-select').val() || '0', 10);
            var price = parseInt(row.find('.price-input').val() || '0', 10);

            if (!catId || !serviceRaw || !price || !duration) {
                return;
            }

            if (!grouped[catId]) {
                grouped[catId] = [];
            }

            grouped[catId].push([price, duration + '.' + pause, serviceRaw]);
        });
        return grouped;
    }

    function buildViglingPayload(rows) {
        var items = [];
        rows.each(function () {
            var row = $(this);
            var catId = String(row.data('category-id') || '');
            var serviceRaw = String(row.find('.service-select').val() || '');
            var duration = parseInt(row.find('.time-select').val() || '0', 10);
            var pause = parseInt(row.find('.pause-select').val() || '0', 10);
            var price = parseInt(row.find('.price-input').val() || '0', 10);

            if (!catId || !serviceRaw || !price || !duration) {
                return;
            }

            items.push({
                cat_id: catId,
                service_raw: serviceRaw,
                price: price,
                duration: String(duration + '.' + pause)
            });
        });

        return {version: 1, items: items};
    }

    function syncUsernameWithEmail() {
        var email = String(emailInput.val() || '').trim();
        usernameInput.val(email);
    }

    function validateSchedule() {
        if (currentType === 'client') {
            return true;
        }

        var from = String($('#jform_work_from').val() || '');
        var to = String($('#jform_work_to').val() || '');

        if (!from || !to) {
            alert('Выберите время работы: с и до.');
            return false;
        }

        if (from >= to) {
            alert('Время "Работаем до" должно быть позже времени "Работаем с".');
            return false;
        }

        return true;
    }

    function isPrivacyConsentChecked() {
        return $('#privacy_consent').prop('checked') === true;
    }

    function hidePrivacyConsentError() {
        var errorEl = $('#privacy-consent-error');
        errorEl.removeClass('is-visible').attr('hidden', true).empty();
        $('#privacy_consent').removeAttr('aria-invalid');
    }

    function showPrivacyConsentError() {
        var errorEl = $('#privacy-consent-error');
        var message = 'Для завершения регистрации необходимо принять условия Политики конфиденциальности';
        errorEl
            .text(message)
            .addClass('is-visible')
            .removeAttr('hidden')
            .attr('aria-hidden', 'false');
        $('#privacy_consent').attr('aria-invalid', 'true');
        if (errorEl[0] && typeof errorEl[0].scrollIntoView === 'function') {
            errorEl[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function syncPrivacyConsentError() {
        var allowed = tabsForCurrentType();
        var isFinalStep = allowed.indexOf(currentTab) === allowed.length - 1;
        if (isPrivacyConsentChecked() || !isFinalStep) {
            hidePrivacyConsentError();
        }
    }

    function validatePrivacyConsent() {
        if (isPrivacyConsentChecked()) {
            hidePrivacyConsentError();
            return true;
        }
        showPrivacyConsentError();
        return false;
    }

    function formatPhone(digits) {
        digits = String(digits || '').replace(/\D/g, '');
        if (digits.charAt(0) === '8') {
            digits = '7' + digits.slice(1);
        }
        if (digits.charAt(0) !== '7') {
            digits = '7' + digits;
        }
        digits = digits.slice(0, 11);

        if (digits.length <= 1) {
            return digits ? '+' + digits : '';
        }

        var s = '+7';
        if (digits.length > 1) {
            s += ' (' + digits.slice(1, 4);
        }
        if (digits.length >= 4) {
            s += ') ' + digits.slice(4, 7);
        }
        if (digits.length >= 7) {
            s += '-' + digits.slice(7, 9);
        }
        if (digits.length >= 9) {
            s += '-' + digits.slice(9, 11);
        }
        return s;
    }

    if (phoneInput.length) {
        phoneInput.on('input', function () {
            this.value = formatPhone(this.value);
        });
        phoneInput.on('focus', function () {
            if (this.value === '') {
                this.value = '+7';
            }
        });
        phoneInput.on('blur', function () {
            if (this.value === '+7') {
                this.value = '';
            }
        });
    }

    if (avatarInput.length && avatarImage.length) {
        avatarInput.on('change', function (e) {
            var file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
            if (!file) {
                return;
            }
            var src = URL.createObjectURL(file);
            avatarImage.one('load', function () {
                URL.revokeObjectURL(src);
            });
            avatarImage.attr('src', src);
        });
    }

    function fileKey(file) {
        return [file.name, file.size, file.lastModified].join('::');
    }

    function syncPortfolioInputWithSelectedFiles() {
        if (!portfolioInput.length) {
            return;
        }
        if (!window.DataTransfer) {
            return;
        }
        var dt = new DataTransfer();
        selectedPortfolioFiles.forEach(function (file) {
            dt.items.add(file);
        });
        portfolioInput[0].files = dt.files;
    }

    function renderPortfolioPreview() {
        portfolioGroup.find('.controls.preview.upload-preview').remove();
        if (!selectedPortfolioFiles.length) {
            updateTabsContainerHeight();
            return;
        }
        var anchorControl = portfolioGroup.find('.controls').first();
        selectedPortfolioFiles.forEach(function (file) {
            var src = URL.createObjectURL(file);
            var preview = $('<div class="controls preview upload-preview"></div>');
            preview.css('background-image', 'url("' + src + '")');
            preview.append('<img src="' + src + '" alt="" />');
            preview.insertBefore(anchorControl);
        });
        updateTabsContainerHeight();
    }

    if (portfolioInput.length && portfolioGroup.length) {
        portfolioInput.attr('multiple', 'multiple');
        portfolioInput.on('change', function (e) {
            var files = e.target.files ? Array.from(e.target.files) : [];
            if (!files.length) {
                return;
            }
            var existing = {};
            selectedPortfolioFiles.forEach(function (file) {
                existing[fileKey(file)] = true;
            });
            var added = 0;
            files.forEach(function (file) {
                if (!file || !String(file.type || '').match(/^image\//i)) {
                    return;
                }
                if (file.size > MAX_IMAGE_SIZE_BYTES) {
                    alert('Файл "' + file.name + '" больше 25MB и не будет добавлен.');
                    return;
                }
                var key = fileKey(file);
                if (existing[key]) {
                    return;
                }
                if (selectedPortfolioFiles.length >= MAX_PORTFOLIO_FILES) {
                    return;
                }
                existing[key] = true;
                selectedPortfolioFiles.push(file);
                added++;
            });
            if (!added && selectedPortfolioFiles.length >= MAX_PORTFOLIO_FILES) {
                alert('Можно загрузить не более 10 фотографий в портфолио.');
            }
            renderPortfolioPreview();
            syncPortfolioInputWithSelectedFiles();
        });
    }

    emailInput.on('input change', syncUsernameWithEmail);
    form.on('input change', 'input, select, textarea', persistDraftState);

    tabsNav.on('click', 'li a', function (e) {
        e.preventDefault();
        var tabId = $(this).closest('li').data('tab');
        setTab(String(tabId));
    });

    typeButtons.on('click', function () {
        if (typeSelectionLocked) {
            return;
        }
        setType(String($(this).data('type') || 'client'));
        lockTypeSelection();
    });

    $('#reg-prev-step').on('click', function () {
        var allowed = tabsForCurrentType();
        var idx = allowed.indexOf(currentTab);
        if (idx > 0) {
            setTab(allowed[idx - 1]);
        }
    });

    $('#reg-next-step').on('click', function () {
        var allowed = tabsForCurrentType();
        var idx = allowed.indexOf(currentTab);
        if (idx >= 0 && idx < allowed.length - 1) {
            setTab(allowed[idx + 1]);
        }
    });

    $(document).on('click', '#reg-cancel', function () {
        clearDraftState();
    });

    $('#jform_vyberite_spetsialnos').on('change', 'input[type="checkbox"]', function () {
        $(this).closest('label').toggleClass('active', $(this).prop('checked'));
        renderServiceBuilders();
        renderCourseBuilders();
        renderSearchBuilders();
    });

    $('#jform_vyberite_usl').on('click', '.btn-add-service', function () {
        addServiceRow($(this).closest('label'));
    });

    $('#jform_vyberite_usl').on('click', '.btn-remove-service', function () {
        $(this).closest('.service__item').remove();
        updateTabsContainerHeight();
        persistDraftState();
    });

    $('#jform_courses_servis').on('click', '.stock_key', function () {
        addCourseRow($(this).closest('label'));
    });

    $('#jform_courses_servis').on('click', '.stock-remove', function () {
        $(this).closest('.service__item').remove();
        updateTabsContainerHeight();
        persistDraftState();
    });

    $('#jform_courses_servis').on('change', '.course-mode-select', function () {
        var row = $(this).closest('.service__item');
        syncCourseModeState(row);
        persistDraftState();
    });

    $('#jform_courses_servis').on('change', '.course-media-file-input', function () {
        var row = $(this).closest('.service__item');
        syncCourseMediaState(row);
    });

    $('#jform_searches_servis').on('click', '.stock_key', function () {
        addSearchRow($(this).closest('label'));
    });

    $('#jform_searches_servis').on('click', '.stock-remove', function () {
        $(this).closest('.service__item').remove();
        updateTabsContainerHeight();
        persistDraftState();
    });

    $('#jform_searches_servis').on('change', '.search-mode-select', function () {
        var row = $(this).closest('.service__item');
        syncSearchModeState(row);
        persistDraftState();
    });

    $('#jform_searches_servis').on('change', '.search-media-file-input', function () {
        var row = $(this).closest('.service__item');
        syncSearchMediaState(row);
    });

    $('#jsn_login').on('click', '.password-toggle-btn', function () {
        var btn = $(this);
        var input = $(btn.data('target'));
        if (!input.length) {
            return;
        }
        var nextType = input.attr('type') === 'password' ? 'text' : 'password';
        var isText = nextType === 'text';
        input.attr('type', nextType);
        btn.toggleClass('is-active', isText);
        btn.attr('title', isText ? 'Скрыть пароль' : 'Показать пароль');
        btn.attr('aria-label', isText ? 'Скрыть пароль' : 'Показать пароль');
    });

    $('#jsn_login').on('click', '.privacy-consent-label a.z-link', function (e) {
        e.stopPropagation();
    });

    $('#privacy_consent').on('change', function () {
        persistDraftState();
        if (isPrivacyConsentChecked()) {
            hidePrivacyConsentError();
        }
        updateTabsContainerHeight();
    });

    $('#reg-submit').on('click', function (e) {
        if (!isFinalRegistrationStep() || !validatePrivacyConsent()) {
            e.preventDefault();
            return false;
        }
        return true;
    });

    form.on('submit', function (e) {
        if (!isFinalRegistrationStep() || !validatePrivacyConsent()) {
            e.preventDefault();
            recaptchaSubmitBypass = false;
            return false;
        }

        if (recaptchaSubmitBypass) {
            recaptchaSubmitBypass = false;
            syncUsernameWithEmail();
            syncCoursePayloadInput();
            syncSearchPayloadInput();
            return true;
        }

        syncUsernameWithEmail();
        syncCoursePayloadInput();
        syncSearchPayloadInput();

        if (!validateSchedule()) {
            e.preventDefault();
            return false;
        }

        if (currentType !== 'client' && selectedSpecialtyIds().length === 0) {
            alert('Выберите специальность.');
            e.preventDefault();
            return false;
        }

        var rows = $('#jform_vyberite_usl .service__item');
        var legacy = buildLegacyPricesFromRows(rows);
        var payload = buildViglingPayload(rows);

        $('#jform_prices').val(JSON.stringify(legacy));
        $('#jform_vigling_services_payload').val(JSON.stringify(payload));
        $('#jform_vigling_stock_services_payload').val(JSON.stringify({version: 1, items: []}));
        syncCoursePayloadInput();
        syncSearchPayloadInput();
        persistDraftState();

        if (
            window.ViglingRecaptcha
            && typeof window.ViglingRecaptcha.getToken === 'function'
            && typeof window.ViglingRecaptcha.isEnabled === 'function'
            && window.ViglingRecaptcha.isEnabled()
        ) {
            e.preventDefault();

            if (recaptchaSubmitInFlight) {
                return false;
            }

            recaptchaSubmitInFlight = true;
            $('#reg-submit').prop('disabled', true).addClass('is-loading');

            window.ViglingRecaptcha.getToken('registration_submit')
                .then(function (token) {
                    if (!token) {
                        throw new Error('empty token');
                    }
                    $('#jform_recaptcha_token').val(token);
                    $('#jform_recaptcha_action').val('registration_submit');
                    recaptchaSubmitBypass = true;
                    form.trigger('submit');
                })
                .catch(function () {
                    showRecaptchaError();
                })
                .finally(function () {
                    recaptchaSubmitInFlight = false;
                    $('#reg-submit').prop('disabled', false).removeClass('is-loading');
                });

            return false;
        }

        return true;
    });

    syncUsernameWithEmail();
    if (!hasSubmittedData && !shouldRestoreDraftStateOnLoad()) {
        clearDraftState();
    }
    if (!hasSubmittedData && restoreDraftState()) {
        // restored from sessionStorage
    } else {
        setType(currentType);
        syncSpecialtyActiveState();
        if (typeSelectionLocked) {
            lockTypeSelection();
        } else {
            tabsRoot.hide();
            controlsBar.hide();
        }
    }
    $(window).on('resize', function () {
        updateTabsContainerHeight();
    });
    stripStrayRegistrationControlText();
    if (window.MutationObserver && controlsBar.get(0)) {
        var strayTextObserver = new MutationObserver(function () {
            stripStrayRegistrationControlText();
        });
        strayTextObserver.observe(controlsBar.get(0), { childList: true, subtree: false });
    }
});
</script>

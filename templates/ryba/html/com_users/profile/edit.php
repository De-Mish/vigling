<?php
defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

$lang = $this->getLanguage();
$lang->load('plg_user_profile', JPATH_ADMINISTRATOR);

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')->useScript('form.validate');

$user = Factory::getApplication()->getIdentity();
$userId = (int) ($user->id ?? 0);
$groups = $userId > 0 ? Access::getGroupsByUser($userId, false) : [];
$isAdministrator = in_array(8, $groups, true) || in_array(7, $groups, true) || in_array(6, $groups, true);
$isMaster = in_array(3, $groups, true);
$profileMasterType = '';

$profileImage = '';
$jcfields = [];
if (!empty($this->data->jcfields) && is_array($this->data->jcfields)) {
	foreach ($this->data->jcfields as $f) {
		if (isset($f->name)) {
			$jcfields[$f->name] = $f;
		}
	}
}
if (isset($jcfields['avatar']->rawvalue) && is_scalar($jcfields['avatar']->rawvalue)) {
	$raw = trim((string) $jcfields['avatar']->rawvalue);
	$dec = json_decode($raw, true);
	if (is_string($dec)) {
		$profileImage = trim($dec);
	} elseif (is_array($dec)) {
		foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($dec)) as $v) {
			if (is_scalar($v) && trim((string) $v) !== '') {
				$profileImage = trim((string) $v);
				break;
			}
		}
	} else {
		$profileImage = $raw;
	}
}
if ($profileImage === '') {
	$fieldsFallback = \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::getFields('com_users.user', $this->data, true);
	foreach ($fieldsFallback as $f) {
		if (!isset($f->name) || $f->name !== 'avatar') {
			continue;
		}
		$v = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
		if (is_scalar($v)) {
			$raw = trim((string) $v);
			$dec = json_decode($raw, true);
			if (is_string($dec)) {
				$profileImage = trim($dec);
			} elseif (is_array($dec)) {
				foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($dec)) as $imgVal) {
					if (is_scalar($imgVal) && trim((string) $imgVal) !== '') {
						$profileImage = trim((string) $imgVal);
						break;
					}
				}
			} else {
				$profileImage = $raw;
			}
		}
		break;
	}
}

if (isset($jcfields['is_master']->rawvalue) && is_scalar($jcfields['is_master']->rawvalue)) {
	$profileMasterType = trim((string) $jcfields['is_master']->rawvalue);
}
if ($profileMasterType === '1' || $profileMasterType === '2') {
	$isMaster = true;
}
if ($isAdministrator) {
	$isMaster = true;
}

if ($isAdministrator) {
	$roleLabel = 'Администратор';
} elseif ($profileMasterType === '2') {
	$roleLabel = 'Мастер - Заточка/Ремонт';
} elseif ($profileMasterType === '1' || $isMaster) {
	$roleLabel = 'Мастер';
} else {
	$roleLabel = 'Клиент';
}
if ($profileImage !== '' && strpos($profileImage, 'http') !== 0) {
	$clean = preg_replace('#^/?(images/profiler/?)?#i', '', str_replace('\\', '/', $profileImage));
	$profileImage = rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/images/profiler/' . $clean;
}

$defaultImg = \Joomla\CMS\Uri\Uri::root() . 'templates/ryba/images/master.png';
if (!is_file(JPATH_ROOT . '/templates/ryba/images/master.png')) {
	$defaultImg = \Joomla\CMS\Uri\Uri::root() . 'components/com_jsn/assets/img/default.jpg';
}

$tabs = $isMaster
	? ['profile', 'portfolio', 'speciality', 'services', 'stocks', 'courses', 'searches', 'schedule', 'login']
	: ['profile', 'login'];

$tabTitles = [
	'profile' => 'Профиль',
	'portfolio' => 'Портфолио',
	'speciality' => 'Специальность',
	'services' => 'Услуги и цены',
	'stocks' => 'Акции',
	'courses' => 'Курсы',
	'searches' => 'Поиск моделей',
	'schedule' => 'Расписание',
	'login' => 'Пароль',
];

$tabFields = [
	'profile' => [],
	'portfolio' => [],
	'speciality' => [],
	'services' => [],
	'stocks' => [],
	'courses' => [],
	'searches' => [],
	'schedule' => [],
	'login' => [],
];

$fieldTokens = static function ($field) {
	$id = strtolower((string) ($field->id ?? ''));
	$name = strtolower((string) ($field->fieldname ?? ''));
	$rawName = '';
	if (method_exists($field, 'getAttribute')) {
		$rawName = (string) $field->getAttribute('name', '');
	}

	return trim($id . ' ' . $name . ' ' . strtolower($rawName));
};

$classifyField = static function ($field) {
	$id = strtolower((string) ($field->id ?? ''));
	$name = strtolower((string) ($field->fieldname ?? ''));
	$rawName = '';
	if (method_exists($field, 'getAttribute')) {
		$rawName = (string) $field->getAttribute('name', '');
	}
	$joined = $id . ' ' . $name . ' ' . strtolower($rawName);

	if (strpos($joined, 'portfolio') !== false) {
		return 'portfolio';
	}
	if (strpos($joined, 'spetsial') !== false || strpos($joined, 'special') !== false || strpos($joined, 'vyberite_spetsialnos') !== false) {
		return 'speciality';
	}
	if (strpos($joined, 'stock') !== false || strpos($joined, 'akci') !== false) {
		return 'stocks';
	}
	if (strpos($joined, 'price') !== false || strpos($joined, 'uslug') !== false || strpos($joined, 'servis') !== false) {
		return 'services';
	}
	if (strpos($joined, 'work_day') !== false || strpos($joined, 'work_from') !== false || strpos($joined, 'work_to') !== false) {
		return 'schedule';
	}
	if (strpos($joined, 'notify') !== false || strpos($joined, 'push') !== false) {
		return 'profile';
	}
	if (strpos($joined, 'username') !== false || strpos($joined, 'password') !== false) {
		return 'login';
	}

	return 'profile';
};

$isEmailField = static function ($field) use ($fieldTokens) {
	$t = $fieldTokens($field);
	return strpos($t, 'email') !== false;
};

$isNameField = static function ($field) use ($fieldTokens) {
	$t = $fieldTokens($field);
	return strpos($t, 'jform_name') !== false || preg_match('/\bname\b/', $t);
};

$isUsernameField = static function ($field) use ($fieldTokens) {
	$t = $fieldTokens($field);
	return strpos($t, 'username') !== false;
};

$isPasswordField = static function ($field) use ($fieldTokens) {
	$t = $fieldTokens($field);
	return strpos($t, 'password') !== false;
};

$lockedUsername = '';
if (method_exists($this->form, 'getValue')) {
	$lockedUsername = trim((string) $this->form->getValue('username'));
}
if ($lockedUsername === '' && isset($this->data->username) && is_scalar($this->data->username)) {
	$lockedUsername = trim((string) $this->data->username);
}
if ($lockedUsername === '') {
	$lockedUsername = trim((string) ($user->username ?? ''));
}

$allRenderableFields = [];
foreach ($this->form->getFieldsets() as $group => $fieldset) {
	$fields = $this->form->getFieldset($group);
	if (!count($fields)) {
		continue;
	}
	foreach ($fields as $field) {
		$type = strtolower((string) ($field->type ?? ''));
		if ($type === 'hidden') {
			continue;
		}
		$allRenderableFields[] = $field;
		$tab = $classifyField($field);
		$tabFields[$tab][] = $field;
	}
}

$hasAnyFieldsInTabs = false;
foreach ($tabFields as $set) {
	if (!empty($set)) {
		$hasAnyFieldsInTabs = true;
		break;
	}
}

$profileData = [];
if (isset($this->data->profile) && is_array($this->data->profile)) {
	$profileData = $this->data->profile;
} elseif (isset($this->data->profile) && is_object($this->data->profile)) {
	$profileData = (array) $this->data->profile;
}

$profileValue = static function (array $data, string $key, string $fallback = '') {
	if (!array_key_exists($key, $data)) {
		return $fallback;
	}
	$val = $data[$key];
	if (is_array($val) || is_object($val)) {
		return $fallback;
	}

	return trim((string) $val);
};

$lastnameValue = $profileValue($profileData, 'lastname', '');
$phoneValue = $profileValue($profileData, 'phone', '');
$cityValue = $profileValue($profileData, 'city', '');
$regionValue = $profileValue($profileData, 'region', '');
$address1Value = $profileValue($profileData, 'address1', '');
$address2Value = $profileValue($profileData, 'address2', '');
$websiteValue = $profileValue($profileData, 'website', '');
$aboutMeValue = $profileValue($profileData, 'aboutme', '');
if ($lastnameValue === '' && isset($jcfields['lastname']->rawvalue) && is_scalar($jcfields['lastname']->rawvalue)) {
	$lastnameValue = trim((string) $jcfields['lastname']->rawvalue);
}
if ($phoneValue === '') {
	if (isset($jcfields['telefon']->rawvalue) && is_scalar($jcfields['telefon']->rawvalue)) {
		$phoneValue = trim((string) $jcfields['telefon']->rawvalue);
	} elseif (isset($jcfields['phone']->rawvalue) && is_scalar($jcfields['phone']->rawvalue)) {
		$phoneValue = trim((string) $jcfields['phone']->rawvalue);
	}
}

if ($cityValue === '' && isset($jcfields['sity']->rawvalue) && is_scalar($jcfields['sity']->rawvalue)) {
	$cityValue = trim((string) $jcfields['sity']->rawvalue);
}
if ($regionValue === '' && isset($jcfields['area']->rawvalue) && is_scalar($jcfields['area']->rawvalue)) {
	$regionValue = trim((string) $jcfields['area']->rawvalue);
}
if ($address1Value === '' && isset($jcfields['street']->rawvalue) && is_scalar($jcfields['street']->rawvalue)) {
	$address1Value = trim((string) $jcfields['street']->rawvalue);
}
if ($address2Value === '' && isset($jcfields['house_number']->rawvalue) && is_scalar($jcfields['house_number']->rawvalue)) {
	$address2Value = trim((string) $jcfields['house_number']->rawvalue);
}
if ($websiteValue === '' && isset($jcfields['link']->rawvalue) && is_scalar($jcfields['link']->rawvalue)) {
	$websiteValue = trim((string) $jcfields['link']->rawvalue);
}
$telegramValue = '';
if (isset($jcfields['telegram']->rawvalue) && is_scalar($jcfields['telegram']->rawvalue)) {
	$telegramValue = trim((string) $jcfields['telegram']->rawvalue);
}
$maxValue = '';
if (isset($jcfields['max']->rawvalue) && is_scalar($jcfields['max']->rawvalue)) {
	$maxValue = trim((string) $jcfields['max']->rawvalue);
}
if (($telegramValue === '' || $maxValue === '' || $websiteValue === '') && (int) $userId > 0) {
	try {
		$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$q = $db->getQuery(true)
			->select([$db->quoteName('f.name'), $db->quoteName('fv.value')])
			->from($db->quoteName('#__fields_values', 'fv'))
			->innerJoin($db->quoteName('#__fields', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id'))
			->where($db->quoteName('f.context') . ' = ' . $db->quote('com_users.user'))
			->where($db->quoteName('f.name') . ' IN (' . $db->quote('telegram') . ', ' . $db->quote('max') . ', ' . $db->quote('link') . ')')
			->where($db->quoteName('fv.item_id') . ' = ' . (int) $userId);
		$db->setQuery($q);
		foreach ($db->loadObjectList() ?: [] as $row) {
			if ($row->name === 'telegram' && $telegramValue === '') {
				$telegramValue = trim((string) $row->value);
			} elseif ($row->name === 'max' && $maxValue === '') {
				$maxValue = trim((string) $row->value);
			} elseif ($row->name === 'link' && $websiteValue === '') {
				$websiteValue = trim((string) $row->value);
			}
		}
	} catch (\Throwable $ignored) {
	}
	if ($websiteValue === '') {
		try {
			$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$q = $db->getQuery(true)
				->select($db->quoteName('profile_value'))
				->from($db->quoteName('#__user_profiles'))
				->where($db->quoteName('user_id') . ' = ' . (int) $userId)
				->where($db->quoteName('profile_key') . ' = ' . $db->quote('profile.website'));
			$db->setQuery($q);
			$raw = $db->loadResult();
			if (is_string($raw) && $raw !== '') {
				$decoded = json_decode($raw, true);
				$websiteValue = trim((string) (is_string($decoded) ? $decoded : $raw));
			}
		} catch (\Throwable $ignored) {
		}
	}
}
if ($aboutMeValue === '' && isset($jcfields['o_sebe']->rawvalue) && is_scalar($jcfields['o_sebe']->rawvalue)) {
	$aboutMeValue = trim((string) $jcfields['o_sebe']->rawvalue);
}

$scheduleWorkDays = [1, 2, 3, 4, 5, 6];
$scheduleWorkFrom = '10:00';
$scheduleWorkTo = '20:00';
$scheduleFieldRaw = [];
if ($userId > 0) {
	try {
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select([$db->quoteName('f.name'), $db->quoteName('fv.value')])
			->from($db->quoteName('#__fields', 'f'))
			->join('INNER', $db->quoteName('#__fields_values', 'fv') . ' ON ' . $db->quoteName('fv.field_id') . ' = ' . $db->quoteName('f.id'))
			->where($db->quoteName('f.context') . ' = ' . $db->quote('com_users.user'))
			->where($db->quoteName('fv.item_id') . ' = ' . (int) $userId)
			->where($db->quoteName('f.name') . ' IN (' . implode(',', array_map([$db, 'quote'], ['work_day', 'work_from', 'work_to'])) . ')');
		$db->setQuery($query);
		foreach (($db->loadObjectList() ?: []) as $row) {
			if (isset($row->name) && is_scalar($row->value)) {
				$scheduleFieldRaw[(string) $row->name] = (string) $row->value;
			}
		}
	} catch (Throwable $e) {
		$scheduleFieldRaw = [];
	}
}
if (isset($scheduleFieldRaw['work_day']) || (isset($jcfields['work_day']->rawvalue) && is_scalar($jcfields['work_day']->rawvalue))) {
	$wdRaw = trim((string) ($scheduleFieldRaw['work_day'] ?? $jcfields['work_day']->rawvalue));
	if ($wdRaw !== '') {
		$wdDecoded = json_decode($wdRaw, true);
		if (is_array($wdDecoded) && !empty($wdDecoded)) {
			$scheduleWorkDays = array_values(array_unique(array_map('intval', array_filter($wdDecoded, 'is_scalar'))));
		}
	}
}
$decodeTimeField = static function (string $raw): string {
	$raw = trim($raw);
	if ($raw === '') {
		return '';
	}
	$dec = json_decode($raw, true);
	if (is_array($dec) && !empty($dec) && is_scalar(reset($dec))) {
		$raw = trim((string) reset($dec));
	}
	$raw = str_replace('.', ':', $raw);
	if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
		return sprintf('%02d', (int) $m[1]) . ':' . $m[2];
	}
	return '';
};
if (isset($scheduleFieldRaw['work_from']) || (isset($jcfields['work_from']->rawvalue) && is_scalar($jcfields['work_from']->rawvalue))) {
	$v = $decodeTimeField((string) ($scheduleFieldRaw['work_from'] ?? $jcfields['work_from']->rawvalue));
	if ($v !== '') {
		$scheduleWorkFrom = $v;
	}
}
if (isset($scheduleFieldRaw['work_to']) || (isset($jcfields['work_to']->rawvalue) && is_scalar($jcfields['work_to']->rawvalue))) {
	$v = $decodeTimeField((string) ($scheduleFieldRaw['work_to'] ?? $jcfields['work_to']->rawvalue));
	if ($v !== '') {
		$scheduleWorkTo = $v;
	}
}
$scheduleTimeOptions = [];
for ($h = 8; $h <= 24; $h++) {
	foreach ([0, 15, 30, 45] as $m) {
		if ($h === 24 && $m > 0) {
			continue;
		}
		$scheduleTimeOptions[] = sprintf('%02d', $h === 24 ? 0 : $h) . ':' . sprintf('%02d', $m);
	}
}
$scheduleDayNames = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];

$portfolioRaw = '';
if (isset($jcfields['portfolio_field']->rawvalue) && is_scalar($jcfields['portfolio_field']->rawvalue)) {
	$portfolioRaw = trim((string) $jcfields['portfolio_field']->rawvalue);
}
if ($portfolioRaw === '') {
	$fieldsFallback = \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::getFields('com_users.user', $this->data, true);
	foreach ($fieldsFallback as $f) {
		if (!isset($f->name) || $f->name !== 'portfolio_field') {
			continue;
		}
		$v = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
		if (is_scalar($v)) {
			$portfolioRaw = trim((string) $v);
		}
		break;
	}
}

$portfolioFiles = [];
if ($portfolioRaw !== '') {
	$decoded = json_decode($portfolioRaw, true);
	if (is_array($decoded)) {
		$iter = new RecursiveIteratorIterator(new RecursiveArrayIterator($decoded));
		foreach ($iter as $item) {
			if (is_scalar($item)) {
				$file = trim((string) $item);
				if ($file !== '') {
					$portfolioFiles[] = basename(str_replace('\\', '/', $file));
				}
			}
		}
	} else {
		$file = basename(str_replace('\\', '/', $portfolioRaw));
		if ($file !== '') {
			$portfolioFiles[] = $file;
		}
	}
}
$portfolioFiles = array_values(array_unique($portfolioFiles));

$portfolioImages = [];
foreach ($portfolioFiles as $file) {
	$portfolioImages[] = [
		'file' => $file,
		'url' => rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/images/portfolio/' . $file,
	];
}

$specialtyRaw = '';
if (isset($jcfields['vyberite_spetsialnos']->rawvalue) && is_scalar($jcfields['vyberite_spetsialnos']->rawvalue)) {
	$specialtyRaw = trim((string) $jcfields['vyberite_spetsialnos']->rawvalue);
}
if ($specialtyRaw === '') {
	$fieldsFallback = \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::getFields('com_users.user', $this->data, true);
	foreach ($fieldsFallback as $f) {
		if (!isset($f->name) || $f->name !== 'vyberite_spetsialnos') {
			continue;
		}
		$v = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
		if (is_scalar($v)) {
			$specialtyRaw = trim((string) $v);
		}
		break;
	}
}
$selectedSpecialtyIds = [];
if ($specialtyRaw !== '') {
	$decoded = json_decode($specialtyRaw, true);
	if (is_array($decoded)) {
		foreach ($decoded as $item) {
			if (is_scalar($item)) {
				$id = (int) $item;
				if ($id > 0) {
					$selectedSpecialtyIds[] = $id;
				}
			}
		}
	} elseif (strpos($specialtyRaw, ',') !== false) {
		foreach (explode(',', $specialtyRaw) as $item) {
			$id = (int) trim((string) $item);
			if ($id > 0) {
				$selectedSpecialtyIds[] = $id;
			}
		}
	} else {
		$id = (int) $specialtyRaw;
		if ($id > 0) {
			$selectedSpecialtyIds[] = $id;
		}
	}
}
$selectedSpecialtyIds = array_values(array_unique($selectedSpecialtyIds));

$specialties = [];
try {
	$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
	$query = $db->getQuery(true)
		->select($db->quoteName(['id', 'title', 'path']))
		->from($db->quoteName('#__categories'))
		->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
		->where($db->quoteName('published') . ' = 1')
		->where($db->quoteName('level') . ' = 2')
		->order($db->quoteName('lft') . ' ASC');
	$db->setQuery($query);
	$rows = $db->loadAssocList() ?: [];
	foreach ($rows as $row) {
		$id = (int) ($row['id'] ?? 0);
		$title = trim((string) ($row['title'] ?? ''));
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
	}
} catch (\Throwable $e) {
	$specialties = [];
}

$servicesByCategory = [];
$allCategoryIds = array_values(array_unique(array_map(static function (array $item): int {
	return (int) ($item['id'] ?? 0);
}, $specialties)));
$allCategoryIds = array_values(array_filter($allCategoryIds, static function (int $id): bool {
	return $id > 0;
}));

if ($allCategoryIds !== []) {
	try {
		$db = $db ?? Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
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
			foreach ($servicesByCategory as $catId => $items) {
				foreach ($items as $serviceId => $item) {
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
		foreach ($servicesByCategory as $catId => $items) {
			$servicesByCategory[$catId] = array_values($items);
		}
	} catch (\Throwable $e) {
		$servicesByCategory = [];
	}
}

$durationOptions = [];
for ($i = 1; $i <= 12; $i++) {
	$durationOptions[] = $i * 15;
}

$serviceOptionIndex = [];
foreach ($servicesByCategory as $catId => $items) {
	$catKey = (int) $catId;
	foreach ((array) $items as $service) {
		$serviceId = (int) ($service['id'] ?? 0);
		if ($serviceId <= 0) {
			continue;
		}
		$serviceOptionIndex[$catKey][(string) $serviceId] = true;
		$tags = isset($service['tags']) && is_array($service['tags']) ? $service['tags'] : [];
		foreach ($tags as $tag) {
			$tagId = (int) ($tag['id'] ?? 0);
			if ($tagId <= 0) {
				continue;
			}
			$serviceOptionIndex[$catKey][$serviceId . '-' . $tagId] = true;
		}
	}
}

$missingServiceOptionsByCategory = [];
$rememberMissingServiceOption = static function (int $categoryId, string $serviceRaw, string $serviceLabel) use (&$missingServiceOptionsByCategory, $serviceOptionIndex): void {
	if ($categoryId <= 0 || $serviceRaw === '') {
		return;
	}
	if (!empty($serviceOptionIndex[$categoryId][$serviceRaw])) {
		return;
	}
	if (!isset($missingServiceOptionsByCategory[$categoryId])) {
		$missingServiceOptionsByCategory[$categoryId] = [];
	}
	if (isset($missingServiceOptionsByCategory[$categoryId][$serviceRaw])) {
		return;
	}
	$missingServiceOptionsByCategory[$categoryId][$serviceRaw] = [
		'value' => $serviceRaw,
		'label' => $serviceLabel !== '' ? $serviceLabel : ('Услуга #' . $serviceRaw),
	];
};

$existingServiceRows = [];
try {
	$existingStructured = \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::getUserServicesStructuredWithIds($userId);
	foreach ($existingStructured as $category) {
		$catId = (string) ($category['cat_id'] ?? '');
		if ($catId === '') {
			continue;
		}
		$items = isset($category['items']) && is_array($category['items']) ? $category['items'] : [];
		foreach ($items as $item) {
			$svcId = (string) ($item['svc_id'] ?? '');
			if ($svcId === '') {
				continue;
			}
			$tagId = (int) ($item['tag_id'] ?? 0);
			$svcInt = (int) $svcId;
			$serviceRaw = ($tagId > 0 && $tagId !== $svcInt) ? ($svcId . '-' . $tagId) : $svcId;
			$serviceLabel = trim((string) ($item['name'] ?? ''));
			$existingServiceRows[] = [
				'categoryId' => (int) $catId,
				'serviceRaw' => $serviceRaw,
				'serviceLabel' => $serviceLabel,
				'duration' => (int) ($item['duration'] ?? 15),
				'pause' => (int) ($item['pause_min'] ?? 15),
				'price' => (int) ($item['price'] ?? 0),
			];
			$rememberMissingServiceOption((int) $catId, $serviceRaw, $serviceLabel);
		}
	}
} catch (\Throwable $e) {
	$existingServiceRows = [];
}

$existingStockRows = [];
try {
	$existingStockStructured = \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::getUserStockServicesStructuredWithIds($userId);
	foreach ($existingStockStructured as $category) {
		$catId = (string) ($category['cat_id'] ?? '');
		if ($catId === '') {
			continue;
		}
		$items = isset($category['items']) && is_array($category['items']) ? $category['items'] : [];
		foreach ($items as $item) {
			$svcId = (string) ($item['svc_id'] ?? '');
			if ($svcId === '') {
				continue;
			}
			$tagId = (int) ($item['tag_id'] ?? 0);
			$svcInt = (int) $svcId;
			$serviceRaw = ($tagId > 0 && $tagId !== $svcInt) ? ($svcId . '-' . $tagId) : $svcId;
			$serviceLabel = trim((string) ($item['name'] ?? ''));
			$existingStockRows[] = [
				'categoryId' => (int) $catId,
				'serviceRaw' => $serviceRaw,
				'serviceLabel' => $serviceLabel,
				'duration' => (int) ($item['duration'] ?? 15),
				'pause' => (int) ($item['pause_min'] ?? 15),
				'price' => (int) ($item['price'] ?? 0),
				'oldPrice' => (int) ($item['old_price'] ?? 0),
				'aboutStock' => (string) ($item['about_stock'] ?? ''),
				'countStock' => (int) ($item['count_stock'] ?? 0),
			];
			$rememberMissingServiceOption((int) $catId, $serviceRaw, $serviceLabel);
		}
	}
} catch (\Throwable $e) {
	$existingStockRows = [];
}

$existingCourseRows = [];
try {
		$existingCourseStructured = \Joomla\Plugin\User\Vigling\Service\UserCoursesService::getUserCoursesStructured($userId);
		foreach ($existingCourseStructured as $course) {
			$existingCourseRows[] = [
				'id' => (int) ($course['id'] ?? 0),
				'categoryId' => (int) ($course['category_id'] ?? 0),
				'title' => (string) ($course['title'] ?? $course['description'] ?? ''),
				'description' => (string) ($course['description'] ?? ''),
				'mediaPath' => (string) ($course['media_path'] ?? ''),
				'price' => (int) ($course['price'] ?? 0),
				'duration' => (int) ($course['duration_min'] ?? 60),
				'capacity' => (int) ($course['capacity'] ?? 1),
				'concurrentParticipants' => (int) ($course['concurrent_participants'] ?? 1),
				'bookingMode' => (string) ($course['booking_mode'] ?? 'free'),
				'bookingCount' => (int) ($course['booking_count'] ?? 0),
				'slotStartUtc' => (string) ($course['slot_start_utc'] ?? ''),
			];
		}
} catch (\Throwable $e) {
	$existingCourseRows = [];
}

$existingSearchRows = [];
try {
	if (class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserSearchesService')) {
		$existingSearchStructured = \Joomla\Plugin\User\Vigling\Service\UserSearchesService::getUserSearchesStructured($userId);
		foreach ($existingSearchStructured as $search) {
			$existingSearchRows[] = [
				'id' => (int) ($search['id'] ?? 0),
				'categoryId' => (int) ($search['category_id'] ?? 0),
				'title' => (string) ($search['title'] ?? $search['description'] ?? ''),
				'description' => (string) ($search['description'] ?? ''),
				'mediaPath' => (string) ($search['media_path'] ?? ''),
				'price' => (int) ($search['price'] ?? 0),
				'duration' => (int) ($search['duration_min'] ?? 60),
				'capacity' => (int) ($search['capacity'] ?? 1),
				'bookingMode' => (string) ($search['booking_mode'] ?? 'free'),
				'bookingCount' => (int) ($search['booking_count'] ?? 0),
				'slotStartUtc' => (string) ($search['slot_start_utc'] ?? ''),
			];
		}
	}
} catch (\Throwable $e) {
	$existingSearchRows = [];
}

$missingServiceOptionsJson = json_encode(array_map('array_values', $missingServiceOptionsByCategory), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$servicesJson = json_encode($servicesByCategory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$durationJson = json_encode($durationOptions);
$existingServiceRowsJson = json_encode($existingServiceRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$existingStockRowsJson = json_encode($existingStockRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$existingCourseRowsJson = json_encode($existingCourseRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$existingSearchRowsJson = json_encode($existingSearchRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div id="easyprofile" class="view_profile profile-edit legacy-registration">
	<div class="jsn-p">
		<div class="view_profile-header">
			<div class="jsn-p-top jsn-p-top-a">
				<div class="jsn-p-avatar">
					<?php if ($profileImage !== '') : ?>
					<img src="<?php echo $this->escape($profileImage); ?>" alt="<?php echo $this->escape($user->name ?: $user->username); ?>" class="avatar">
					<?php else : ?>
					<img src="<?php echo $this->escape($defaultImg); ?>" alt="<?php echo $this->escape($user->name ?: $user->username); ?>" class="avatar">
					<?php endif; ?>
					<span class="avatar-online" title="OnLine" aria-hidden="true"></span>
				</div>
				<div class="jsn-p-title">
					<h3><?php echo $this->escape($user->name ?: $user->username); ?></h3>
					<span class="jsn-p-role"><?php echo $this->escape($roleLabel); ?></span>
				</div>
				<div class="jsn-p-before-fields"></div>
			</div>
		</div>

		<form id="member-profile" action="<?php echo Route::_('index.php'); ?>" method="post" class="jsn-p-fields form-validate form-horizontal well" enctype="multipart/form-data">
			<div id="jsn-form" class="hover clean mini flat z-icons-light z-shadows z-spaced z-tabs horizontal top-compact top view_profile-tabs">
				<ul class="z-tabs-nav z-tabs-mobile" style="display:none;">
					<li><a class="z-link" style="text-align:left;"><span class="z-title">Профиль</span><span class="z-arrow"></span></a></li>
				</ul>
				<i class="z-dropdown-arrow"></i>
				<ul id="jsn-profile-tabs" class="z-tabs-nav z-tabs-desktop">
					<?php foreach ($tabs as $index => $tabKey) : ?>
					<li data-index="<?php echo (int) $index; ?>" data-link="profile-tab<?php echo (int) $index; ?>" class="z-tab<?php echo $index === 0 ? ' z-first z-active' : ''; ?><?php echo $index === (count($tabs) - 1) ? ' z-last' : ''; ?>" style="width: <?php echo (100 / max(1, count($tabs))); ?>%;">
						<a class="z-link" style="min-height: 18px;"><?php echo $this->escape($tabTitles[$tabKey]); ?><span></span></a>
					</li>
					<?php endforeach; ?>
				</ul>

				<div class="z-container lk-edit-tab-panels">
					<?php foreach ($tabs as $index => $tabKey) : ?>
					<div class="lk-edit-tab-panel z-content<?php echo $index === 0 ? ' z-active' : ''; ?>" data-index="<?php echo (int) $index; ?>" data-name="profile-tab<?php echo (int) $index; ?>" style="<?php echo $index === 0 ? 'display:block;' : 'display:none;'; ?>">
						<div class="z-content-inner">
							<fieldset class="jsn-form-fieldset" data-index="<?php echo (int) $index; ?>" data-name="profile-tab<?php echo (int) $index; ?>">
								<legend style="display:none;"><?php echo $this->escape($tabTitles[$tabKey]); ?></legend>
								<?php if (!$isMaster && $tabKey === 'profile') : ?>
									<div class="control-group avatar-group lk-avatar-edit-group">
										<div class="control-label"><label for="jform_upload_avatar">Фото профиля</label></div>
										<div class="controls lk-avatar-edit-controls">
											<button type="button" class="lk-avatar-trigger" id="lk-avatar-trigger" title="Сменить фото">
												<div class="jsn-p-avatar">
													<img src="<?php echo $this->escape($profileImage !== '' ? $profileImage : $defaultImg); ?>" alt="<?php echo $this->escape($user->name ?: $user->username); ?>" class="avatar lk-avatar-preview" id="lk-avatar-preview">
													<span class="avatar-online" title="OnLine" aria-hidden="true"></span>
												</div>
											</button>
											<input type="file" name="jform[upload_avatar]" id="jform_upload_avatar" accept="image/*" class="lk-avatar-file-input">
											<span class="lk-avatar-help">Нажмите на фото, чтобы заменить</span>
										</div>
									</div>
									<?php
									$nameField = null;
									$emailField = null;
									foreach ($tabFields['profile'] as $f) {
										if ($nameField === null && $isNameField($f)) {
											$nameField = $f;
										}
										if ($emailField === null && $isEmailField($f)) {
											$emailField = $f;
										}
									}
									?>
									<?php if ($nameField) : ?>
										<?php echo $nameField->renderField(); ?>
									<?php endif; ?>
									<div class="control-group lastname-group">
										<div class="control-label">
											<label for="jform_lastname">Фамилия</label>
										</div>
										<div class="controls">
											<input type="text" name="jform[profile][lastname]" id="jform_lastname" value="<?php echo $this->escape($lastnameValue); ?>" placeholder="Фамилия">
										</div>
									</div>
									<div class="control-group telefon-group">
										<div class="control-label">
											<label for="jform_telefon">Телефон</label>
										</div>
										<div class="controls">
											<input type="text" name="jform[profile][phone]" id="jform_telefon" value="<?php echo $this->escape($phoneValue); ?>" class="js-phone-mask" placeholder="Телефон">
										</div>
									</div>
									<?php
									$emailValue = '';
									if ($emailField && method_exists($emailField, 'getValue')) {
										$emailValue = (string) $emailField->getValue();
									}
									if ($emailValue === '') {
										$emailValue = (string) ($user->email ?? '');
									}
									?>
									<div class="control-group email1-group email-readonly-group">
										<div class="control-label">
											<label for="jform_email1_readonly">E-mail</label>
										</div>
										<div class="controls">
											<input type="email" id="jform_email1_readonly" value="<?php echo $this->escape($emailValue); ?>" readonly aria-readonly="true">
											<input type="hidden" name="jform[email1]" value="<?php echo $this->escape($emailValue); ?>">
										</div>
									</div>
								<?php elseif ($isMaster && $tabKey === 'profile') : ?>
									<div class="control-group avatar-group lk-avatar-edit-group">
										<div class="control-label"><label for="jform_upload_avatar">Фото профиля</label></div>
										<div class="controls lk-avatar-edit-controls">
											<button type="button" class="lk-avatar-trigger" id="lk-avatar-trigger" title="Сменить фото">
												<div class="jsn-p-avatar">
													<img src="<?php echo $this->escape($profileImage !== '' ? $profileImage : $defaultImg); ?>" alt="<?php echo $this->escape($user->name ?: $user->username); ?>" class="avatar lk-avatar-preview" id="lk-avatar-preview">
													<span class="avatar-online" title="OnLine" aria-hidden="true"></span>
												</div>
											</button>
											<input type="file" name="jform[upload_avatar]" id="jform_upload_avatar" accept="image/*" class="lk-avatar-file-input">
											<span class="lk-avatar-help">Нажмите на фото, чтобы заменить</span>
										</div>
									</div>
									<?php
									$nameField = null;
									$emailField = null;
									foreach ($tabFields['profile'] as $f) {
										if ($nameField === null && $isNameField($f)) {
											$nameField = $f;
										}
										if ($emailField === null && $isEmailField($f)) {
											$emailField = $f;
										}
									}
									$emailValue = '';
									if ($emailField && method_exists($emailField, 'getValue')) {
										$emailValue = (string) $emailField->getValue();
									}
									if ($emailValue === '') {
										$emailValue = (string) ($user->email ?? '');
									}
									?>
									<div class="control-group name-group">
										<?php if ($nameField) : ?>
											<?php echo $nameField->renderField(); ?>
										<?php else : ?>
										<div class="control-group firstname-group">
											<div class="control-label"><label for="jform_name">Имя <span class="star" aria-hidden="true">*</span></label></div>
											<div class="controls"><input type="text" name="jform[name]" id="jform_name" value="<?php echo $this->escape((string) ($user->name ?? '')); ?>" required></div>
										</div>
										<?php endif; ?>
										<div class="control-group lastname-group">
											<div class="control-label"><label for="jform_lastname">Фамилия</label></div>
											<div class="controls"><input type="text" name="jform[profile][lastname]" id="jform_lastname" value="<?php echo $this->escape($lastnameValue); ?>" placeholder="Фамилия"></div>
										</div>
									</div>

									<div class="control-group mail-group">
										<div class="control-group telefon-group">
											<div class="control-label"><label for="jform_telefon">Телефон</label></div>
											<div class="controls"><input type="text" name="jform[profile][phone]" id="jform_telefon" value="<?php echo $this->escape($phoneValue); ?>" class="js-phone-mask" placeholder="Телефон"></div>
										</div>
										<div class="control-group email1-group">
											<div class="control-label"><label for="jform_email1">E-mail <span class="star" aria-hidden="true">*</span></label></div>
											<div class="controls"><input type="email" name="jform[email1]" id="jform_email1" value="<?php echo $this->escape($emailValue); ?>" required autocomplete="email" placeholder="E-mail"></div>
										</div>
									</div>

									<div class="control-group address-group form-row m-0">
										<div class="control-group sity-group">
											<div class="control-label"><label for="jform_sity">Город</label></div>
											<div class="controls"><input type="text" name="jform[profile][city]" id="jform_sity" value="<?php echo $this->escape($cityValue); ?>" placeholder="Город"></div>
										</div>
										<div class="control-group area-group">
											<div class="control-label"><label for="jform_area">Район</label></div>
											<div class="controls"><input type="text" name="jform[profile][region]" id="jform_area" value="<?php echo $this->escape($regionValue); ?>" placeholder="Район"></div>
										</div>
										<div class="control-group street-group">
											<div class="control-label"><label for="jform_street">Улица</label></div>
											<div class="controls"><input type="text" name="jform[profile][address1]" id="jform_street" value="<?php echo $this->escape($address1Value); ?>" placeholder="Улица"></div>
										</div>
										<div class="control-group house_number-group">
											<div class="control-label"><label for="jform_house_number">Номер дома</label></div>
											<div class="controls"><input type="text" name="jform[profile][address2]" id="jform_house_number" value="<?php echo $this->escape($address2Value); ?>" placeholder="Дом"></div>
										</div>
									</div>

									<div class="control-group form-row social-links-group">
										<div class="control-group social-link-row">
											<img src="/templates/ryba/icons/social_icons/vk.svg" alt="Vk" class="social-link-icon">
											<div class="controls"><input type="url" name="jform[profile][website]" id="jform_link" value="<?php echo $this->escape($websiteValue); ?>" placeholder="https://vk.com/ваш_id" pattern="^https?://(www\.|m\.)?vk\.com/.+" title="Ссылка должна начинаться с https://vk.com/"></div>
										</div>
										<div class="control-group social-link-row">
											<img src="/templates/ryba/icons/social_icons/telegram.svg" alt="Telegram" class="social-link-icon">
											<div class="controls"><input type="url" name="jform[com_fields][telegram]" id="jform_telegram" value="<?php echo $this->escape($telegramValue); ?>" placeholder="https://t.me/ваш_id" pattern="^https?://(www\.)?t\.me/.+" title="Ссылка должна начинаться с https://t.me/"></div>
										</div>
										<div class="control-group social-link-row">
											<img src="/templates/ryba/icons/social_icons/max.svg" alt="Max" class="social-link-icon">
											<div class="controls"><input type="url" name="jform[com_fields][max]" id="jform_max" value="<?php echo $this->escape($maxValue); ?>" placeholder="https://max.ru/ваш_id" pattern="^https?://(www\.)?max\.ru/.+" title="Ссылка должна начинаться с https://max.ru/"></div>
										</div>
									</div>
									<div class="control-group o_sebe-group">
										<div class="control-label"><label for="jform_o_sebe">О себе</label></div>
										<div class="controls"><textarea name="jform[profile][aboutme]" id="jform_o_sebe" class="input_placeholder" placeholder="О себе"><?php echo $this->escape($aboutMeValue); ?></textarea></div>
									</div>
								<?php elseif ($isMaster && $tabKey === 'portfolio') : ?>
									<div class="control-group portfolio_field-group">
										<div class="control-label"><label for="jform_upload_portfolio_field">Портфолио</label></div>
										<div class="controls lk-portfolio-edit-controls">
											<label class="lk-portfolio-upload-btn" for="jform_upload_portfolio_field">Добавить фото</label>
											<input type="file" name="jform[upload_portfolio_field][]" id="jform_upload_portfolio_field" accept="image/*" multiple>
											<input type="hidden" name="jform[portfolio_deleted]" id="jform_portfolio_deleted" value="">
											<?php if (!empty($portfolioImages)) : ?>
											<div class="lk-portfolio-grid lk-edit-portfolio-grid">
												<?php foreach ($portfolioImages as $img) : ?>
												<div class="lk-portfolio-item lk-edit-portfolio-item" data-file="<?php echo $this->escape($img['file']); ?>">
													<img src="<?php echo $this->escape($img['url']); ?>" alt="Портфолио">
													<button type="button" class="lk-portfolio-remove" data-file="<?php echo $this->escape($img['file']); ?>" title="Удалить">×</button>
												</div>
												<?php endforeach; ?>
											</div>
											<?php else : ?>
											<fieldset class="readonly">Портфолио пока пустое</fieldset>
											<?php endif; ?>
										</div>
									</div>
								<?php elseif ($isMaster && $tabKey === 'speciality') : ?>
									<div class="control-group vyberite_spetsialnos-group">
										<div class="control-label"><label for="jform_vyberite_spetsialnos">Специальность</label></div>
										<div class="controls">
											<fieldset id="jform_vyberite_spetsialnos" class="required checkboxes" aria-required="true">
												<?php foreach ($specialties as $specialty) : ?>
												<label for="jform_vyberite_spetsialnos<?php echo (int) $specialty['id']; ?>" class="checkbox<?php echo in_array((int) $specialty['id'], $selectedSpecialtyIds, true) ? ' active' : ''; ?>" data-type="<?php echo $specialty['type']; ?>">
													<input type="checkbox" id="jform_vyberite_spetsialnos<?php echo (int) $specialty['id']; ?>" name="jform[vyberite_spetsialnos][]" value="<?php echo (int) $specialty['id']; ?>" <?php echo in_array((int) $specialty['id'], $selectedSpecialtyIds, true) ? 'checked' : ''; ?>>
													<?php echo $this->escape($specialty['title']); ?>
												</label>
												<?php endforeach; ?>
											</fieldset>
										</div>
									</div>
								<?php elseif ($isMaster && $tabKey === 'services') : ?>
									<div class="control-group prices-group">
										<div class="control-label"><label for="jform_vyberite_usl">Услуги и цены</label></div>
										<div class="controls">
											<fieldset id="jform_vyberite_usl">Выберите специальность, чтобы добавить услугу</fieldset>
											<input type="hidden" name="jform[prices]" id="jform_prices" value="">
											<input type="hidden" name="jform[stock_prices]" id="jform_stock_prices" value="">
											<input type="hidden" name="jform[vigling_services_payload]" id="jform_vigling_services_payload" value="">
											<input type="hidden" name="jform[vigling_stock_services_payload]" id="jform_vigling_stock_services_payload" value="">
											<input type="hidden" name="jform[vigling_courses_payload]" id="jform_vigling_courses_payload" value="">
											<input type="hidden" name="jform[vigling_searches_payload]" id="jform_vigling_searches_payload" value="">
										</div>
									</div>
								<?php elseif ($isMaster && $tabKey === 'stocks') : ?>
									<div class="control-group stock_prices-group">
										<div class="control-label"><label for="jform_stocks_servis">Акции</label></div>
										<div class="controls">
											<fieldset id="jform_stocks_servis">Выберите специальность, чтобы добавить акцию</fieldset>
										</div>
									</div>
								<?php elseif ($isMaster && $tabKey === 'courses') : ?>
									<div class="control-group stock_prices-group">
										<div class="control-label"><label for="jform_courses_servis">Курсы</label></div>
										<div class="controls">
											<fieldset id="jform_courses_servis">Выберите специальность, чтобы добавить курс</fieldset>
										</div>
									</div>
								<?php elseif ($isMaster && $tabKey === 'searches') : ?>
									<div class="control-group stock_prices-group">
										<div class="control-label"><label for="jform_searches_servis">Поиск моделей</label></div>
										<div class="controls">
											<fieldset id="jform_searches_servis">Выберите специальность, чтобы добавить поиск</fieldset>
										</div>
									</div>
								<?php elseif ($isMaster && $tabKey === 'schedule') : ?>
									<div class="control-group work_day-group">
										<div class="control-label"><label>Рабочие дни</label></div>
										<div class="controls">
											<input type="hidden" name="jform[com_fields][work_day]" id="jform_work_day_json" value="<?php echo htmlspecialchars(json_encode(array_values(array_map('strval', $scheduleWorkDays))), ENT_QUOTES); ?>">
											<fieldset class="checkboxes schedule-days">
												<?php foreach ($scheduleDayNames as $dayNum => $dayName) : ?>
												<label class="checkbox schedule-day-label">
													<input type="checkbox" class="schedule-day-cb" name="jform[vigling_schedule_days][]" value="<?php echo (int) $dayNum; ?>" data-day="<?php echo (int) $dayNum; ?>" <?php echo in_array($dayNum, $scheduleWorkDays, true) ? 'checked' : ''; ?> />
													<?php echo $dayName; ?>
												</label>
												<?php endforeach; ?>
											</fieldset>
										</div>
									</div>
									<script>
									(function() {
										function updateWorkDayJson() {
											var days = [];
											document.querySelectorAll('.schedule-day-cb:checked').forEach(function(cb) {
												days.push(cb.getAttribute('data-day') || cb.value);
											});
											var input = document.getElementById('jform_work_day_json');
											if (input) {
												input.value = JSON.stringify(days);
											}
										}
										document.querySelectorAll('.schedule-day-cb').forEach(function(cb) {
											cb.addEventListener('change', updateWorkDayJson);
										});
										var form = document.getElementById('member-profile');
										if (form) {
											form.addEventListener('submit', updateWorkDayJson);
										}
									})();
									</script>
									<div class="control-group work_from-group" style="margin-top:16px">
										<div class="control-label"><label for="jform_work_from_edit">Начало рабочего дня</label></div>
										<div class="controls">
											<select id="jform_work_from_edit" name="jform[com_fields][work_from]" class="schedule-time-select">
												<option value="">— выбрать —</option>
												<?php foreach ($scheduleTimeOptions as $t) : ?>
												<option value="<?php echo $t; ?>" <?php echo $t === $scheduleWorkFrom ? 'selected' : ''; ?>><?php echo $t; ?></option>
												<?php endforeach; ?>
											</select>
										</div>
									</div>
									<div class="control-group work_to-group" style="margin-top:12px">
										<div class="control-label"><label for="jform_work_to_edit">Конец рабочего дня</label></div>
										<div class="controls">
											<select id="jform_work_to_edit" name="jform[com_fields][work_to]" class="schedule-time-select">
												<option value="">— выбрать —</option>
												<?php foreach ($scheduleTimeOptions as $t) : ?>
												<option value="<?php echo $t; ?>" <?php echo $t === $scheduleWorkTo ? 'selected' : ''; ?>><?php echo $t; ?></option>
												<?php endforeach; ?>
											</select>
										</div>
									</div>
									<p class="schedule-hint" style="margin-top:16px;color:#888;font-size:13px;">Расписание используется в системе онлайн-записи для отображения доступных слотов у клиентов.</p>
								<?php elseif ($tabKey === 'login') : ?>
									<p class="lk-login-hint">В качестве email для входа используется почта аккаунта</p>
									<?php
									$hasPasswordFields = false;
									foreach ($tabFields['login'] as $field) {
										if ($isUsernameField($field)) {
											continue;
										}
										if (!$isPasswordField($field)) {
											continue;
										}
										$hasPasswordFields = true;
										echo $field->renderField();
									}
									?>
									<?php if (!$hasPasswordFields) : ?>
										<fieldset class="readonly">Нет доступных полей</fieldset>
									<?php endif; ?>
								<?php elseif (!empty($tabFields[$tabKey])) : ?>
									<?php foreach ($tabFields[$tabKey] as $field) : ?>
										<?php echo $field->renderField(); ?>
									<?php endforeach; ?>
								<?php elseif ($tabKey === 'profile' && !$hasAnyFieldsInTabs && !empty($allRenderableFields)) : ?>
									<?php foreach ($allRenderableFields as $field) : ?>
										<?php echo $field->renderField(); ?>
									<?php endforeach; ?>
								<?php else : ?>
									<fieldset class="readonly">Нет доступных полей</fieldset>
								<?php endif; ?>
							</fieldset>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<input type="hidden" name="option" value="com_users">
			<input type="hidden" name="jform[username]" value="<?php echo $this->escape($lockedUsername); ?>">

			<?php if ($this->mfaConfigurationUI) : ?>
			<div class="com-users-profile__multifactor">
				<h4><?php echo \Joomla\CMS\Language\Text::_('COM_USERS_PROFILE_MULTIFACTOR_AUTH'); ?></h4>
				<?php echo $this->mfaConfigurationUI; ?>
			</div>
			<?php endif; ?>

			<?php echo $this->form->renderControlFields(); ?>

			<div class="lk-edit-form-footer jsn_profile_edit_controls">
				<button type="submit" class="dale validate" name="task" value="profile.save">Сохранить</button>
				<button type="submit" class="dale" name="task" value="profile.cancel" formnovalidate>Отменить</button>
			</div>
		</form>
	</div>
</div>

<style>
.profile-edit {
	padding-bottom: 42px;
}
.profile-edit #member-profile {
	display: block;
	width: 100%;
	margin-bottom: 0 !important;
	overflow: visible !important;
}
.profile-edit .jsn-p-fields {
	width: 100% !important;
	max-width: none !important;
}
.profile-edit #jsn-form {
	margin-bottom: 0 !important;
	position: relative;
	z-index: 1;
	width: 100% !important;
}
.profile-edit #jsn-form .lk-edit-tab-panels {
	display: block;
	position: relative;
	min-height: 0 !important;
	height: auto !important;
	overflow: visible !important;
	width: 100% !important;
}
.profile-edit #jsn-form .lk-edit-tab-panels > .z-content,
.profile-edit #jsn-form .lk-edit-tab-panels > .z-content.z-active,
.profile-edit #jsn-form .lk-edit-tab-panel {
	position: static !important;
	left: auto !important;
	top: auto !important;
	right: auto !important;
	width: 100% !important;
	height: auto !important;
	overflow: visible !important;
	display: none;
}
.profile-edit #jsn-form .lk-edit-tab-panels > .z-content.z-active {
	display: block;
}
.profile-edit #jsn-form .z-content-inner,
.profile-edit #jsn-form .jsn-form-fieldset {
	height: auto !important;
	overflow: visible !important;
}
.profile-edit #jsn-form .jsn-form-fieldset::after {
	content: "";
	display: block;
	clear: both;
}
.profile-edit #jsn-form .jsn-form-fieldset .control-group {
	display: block !important;
	margin: 0 0 14px !important;
	clear: both;
}
.profile-edit #jsn-form .jsn-form-fieldset .control-group,
.profile-edit #jsn-form .jsn-form-fieldset .controls,
.profile-edit #jsn-form .jsn-form-fieldset .control-label {
	float: none !important;
	position: static !important;
}
.profile-edit #jsn-form .jsn-form-fieldset .control-label {
	display: block !important;
	width: 100% !important;
	max-width: none !important;
	margin: 0 0 6px !important;
	padding: 0 !important;
	text-align: left !important;
}
.profile-edit #jsn-form .jsn-form-fieldset .controls {
	display: block !important;
	width: 100% !important;
	max-width: none !important;
	margin: 0 !important;
	padding: 0 !important;
}
.profile-edit #jsn-form .jsn-form-fieldset input[type="text"],
.profile-edit #jsn-form .jsn-form-fieldset input[type="email"],
.profile-edit #jsn-form .jsn-form-fieldset input[type="password"],
.profile-edit #jsn-form .jsn-form-fieldset input[type="tel"],
.profile-edit #jsn-form .jsn-form-fieldset input[type="url"],
.profile-edit #jsn-form .jsn-form-fieldset input[type="number"],
.profile-edit #jsn-form .jsn-form-fieldset select,
.profile-edit #jsn-form .jsn-form-fieldset textarea {
	display: block !important;
	width: 100% !important;
	max-width: 680px !important;
	min-height: 40px;
	padding: 8px 12px !important;
	background: #fff !important;
	color: #000 !important;
	border: 1px solid #d9d9d9 !important;
	border-radius: 8px !important;
	box-sizing: border-box;
}
.profile-edit #jsn-form .jsn-form-fieldset textarea { min-height: 120px; }
.profile-edit #jsn-form .jsn-form-fieldset label { color: #222 !important; font-size: 14px; line-height: 1.3; }
.profile-edit #jsn-form .jsn-form-fieldset .star { color: #d71703 !important; }
.profile-edit .control-group:has([id*="patronymic"]) { display: none !important; }
.profile-edit #jform_vyberite_usl .service__item,
.profile-edit #jform_stocks_servis .service__item,
.profile-edit #jform_courses_servis .service__item,
.profile-edit #jform_searches_servis .service__item {
	display: block !important;
	margin: 15px auto !important;
	padding-bottom: 20px !important;
	position: relative !important;
}
.profile-edit #jform_stocks_servis .service__item::after,
.profile-edit #jform_courses_servis .service__item::after,
.profile-edit #jform_searches_servis .service__item::after {
	content: "";
	position: absolute;
	display: block;
	background-color: rgba(0, 0, 0, 0.15);
	width: 411px;
	height: 2px;
	bottom: -5px;
}
.profile-edit #jform_stocks_servis,
.profile-edit #jform_courses_servis,
.profile-edit #jform_searches_servis {
	display: block !important;
}
.profile-edit #jform_stocks_servis > label,
.profile-edit #jform_courses_servis > label,
.profile-edit #jform_searches_servis > label {
	display: block !important;
	position: relative !important;
	width: 100% !important;
	max-width: 100% !important;
	margin: 0 0 34px !important;
	padding: 0 !important;
	font-size: 16px !important;
	line-height: 1.35 !important;
	font-family: "GothamPro-Medium", sans-serif !important;
	font-weight: 500 !important;
	color: #222 !important;
	cursor: default !important;
}
.profile-edit #jform_stocks_servis > label:last-child,
.profile-edit #jform_courses_servis > label:last-child,
.profile-edit #jform_searches_servis > label:last-child {
	margin-bottom: 0 !important;
}
.profile-edit #jform_stocks_servis > label > b,
.profile-edit #jform_courses_servis > label > b,
.profile-edit #jform_searches_servis > label > b {
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
.profile-edit #jform_stocks_servis > label .flex_wrap,
.profile-edit #jform_courses_servis > label .flex_wrap,
.profile-edit #jform_searches_servis > label .flex_wrap {
	display: flex !important;
	flex-direction: column !important;
	align-items: flex-start !important;
	gap: 0 !important;
	margin-top: 0 !important;
	min-height: 0 !important;
}
.profile-edit #jform_stocks_servis > label .service_list,
.profile-edit #jform_courses_servis > label .service_list,
.profile-edit #jform_searches_servis > label .service_list {
	display: block !important;
	width: 100% !important;
}
.profile-edit #jform_stocks_servis > label .service_list:empty,
.profile-edit #jform_courses_servis > label .service_list:empty,
.profile-edit #jform_searches_servis > label .service_list:empty {
	display: none !important;
}
.profile-edit #jform_stocks_servis .service__item .time,
.profile-edit #jform_stocks_servis .service__item .time2,
.profile-edit #jform_stocks_servis .service__item .stock_price,
.profile-edit #jform_stocks_servis .service__item .old_price,
.profile-edit #jform_stocks_servis .service__item .about_stock,
.profile-edit #jform_stocks_servis .service__item .count_stock {
	display: flex !important;
	align-items: center !important;
	padding: 4px 0 !important;
}
.profile-edit #jform_stocks_servis .service__item .time label,
.profile-edit #jform_stocks_servis .service__item .time2 label,
.profile-edit #jform_stocks_servis .service__item .stock_price label,
.profile-edit #jform_stocks_servis .service__item .old_price label,
.profile-edit #jform_stocks_servis .service__item .about_stock label,
.profile-edit #jform_stocks_servis .service__item .count_stock label {
	min-width: 165px;
	padding-right: 8px !important;
}
.profile-edit #jform_stocks_servis .service__item .stock-service-select {
	width: 100% !important;
	max-width: 100% !important;
	box-sizing: border-box !important;
}
.profile-edit #jform_stocks_servis .service__item .stock_price input,
.profile-edit #jform_stocks_servis .service__item .old_price input {
	max-width: 90px !important;
	width: 90px !important;
	text-align: center;
}
.profile-edit #jform_stocks_servis .service__item .about_stock input {
	max-width: 255px !important;
	width: 255px !important;
}
.profile-edit #jform_stocks_servis .service__item .about_stock {
	display: block !important;
	width: 100% !important;
}
.profile-edit #jform_stocks_servis .service__item .about_stock label {
	display: block !important;
	margin-bottom: 4px !important;
	width: 100% !important;
	min-width: 0 !important;
	padding-right: 0 !important;
}
.profile-edit #jform_stocks_servis .service__item .count_stock input {
	max-width: 90px !important;
	width: 90px !important;
	text-align: center;
}
.profile-edit #jform_stocks_servis .service__item .time select,
.profile-edit #jform_stocks_servis .service__item .time2 select {
	max-width: 65px !important;
	width: 65px !important;
}
.profile-edit #jform_stocks_servis .btn-remove-service {
	display: inline-block;
	padding: 4px 16px;
	margin: 8px 0 0;
	border: 1px solid #d9d9d9;
	border-radius: 6px;
	background: #f5f5f5;
	color: #333;
	font-size: 13px;
	cursor: pointer;
	transition: all 0.2s ease;
}
.profile-edit #jform_stocks_servis .btn-remove-service:hover {
	background: #e8e8e8;
	border-color: #bbb;
}
.profile-edit #jform_stocks_servis .stock_key {
	margin: 12px;
	width: 37px;
	height: 37px;
	border: 1px solid #000;
	border-radius: 50%;
	cursor: pointer;
	position: relative;
}
.profile-edit #jform_stocks_servis .stock_key::before,
.profile-edit #jform_stocks_servis .stock_key::after {
	content: "";
	position: absolute;
	left: 50%;
	top: 50%;
	background: #000;
	transform: translate(-50%, -50%);
}
.profile-edit #jform_stocks_servis .stock_key::before {
	width: 14px;
	height: 2px;
}
.profile-edit #jform_stocks_servis .stock_key::after {
	width: 2px;
	height: 14px;
}
.profile-edit #jform_courses_servis .stock_key {
	margin: 12px;
	width: 37px;
	height: 37px;
	border: 1px solid #000;
	border-radius: 50%;
	cursor: pointer;
	position: relative;
}
.profile-edit #jform_courses_servis .stock_key::before,
.profile-edit #jform_courses_servis .stock_key::after {
	content: "";
	position: absolute;
	left: 50%;
	top: 50%;
	background: #000;
	transform: translate(-50%, -50%);
}
.profile-edit #jform_courses_servis .stock_key::before {
	width: 14px;
	height: 2px;
}
.profile-edit #jform_courses_servis .stock_key::after {
	width: 2px;
	height: 14px;
}
.profile-edit #jform_courses_servis .service__item .course_desc,
.profile-edit #jform_courses_servis .service__item .course_title,
.profile-edit #jform_courses_servis .service__item .course_media,
.profile-edit #jform_courses_servis .service__item .course_price,
.profile-edit #jform_courses_servis .service__item .course_duration,
.profile-edit #jform_courses_servis .service__item .course_capacity,
.profile-edit #jform_courses_servis .service__item .course_concurrent,
.profile-edit #jform_courses_servis .service__item .course_mode,
.profile-edit #jform_courses_servis .service__item .course_slot {
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    padding: 4px 0 !important;
    gap: 4px;
    width: 100% !important;
}
.profile-edit #jform_courses_servis .service__item .course_duration {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    padding: 4px 0 !important;
    gap: 4px !important;
    flex-wrap: wrap !important;
    width: 100% !important;
}
.profile-edit #jform_courses_servis .service__item .course_duration label {
    min-width: 0 !important;
    width: auto !important;
    display: inline !important;
    padding-right: 4px !important;
}
.profile-edit #jform_courses_servis .service__item .course_duration select {
    max-width: 80px !important;
    width: auto !important;
    display: inline-block !important;
}
.profile-edit #jform_courses_servis .service__item .course_desc label,
.profile-edit #jform_courses_servis .service__item .course_title label,
.profile-edit #jform_courses_servis .service__item .course_media label,
.profile-edit #jform_courses_servis .service__item .course_price label,
.profile-edit #jform_courses_servis .service__item .course_capacity label,
.profile-edit #jform_courses_servis .service__item .course_concurrent label,
.profile-edit #jform_courses_servis .service__item .course_mode label,
.profile-edit #jform_courses_servis .service__item .course_slot label {
    min-width: 0 !important;
    width: 100% !important;
    display: block !important;
    padding-right: 0 !important;
}
.profile-edit #jform_courses_servis .service__item .course_title input,
.profile-edit #jform_courses_servis .service__item .course_price input,
.profile-edit #jform_courses_servis .service__item .course_capacity input,
.profile-edit #jform_courses_servis .service__item .course_concurrent input,
.profile-edit #jform_courses_servis .service__item .course_mode select,
.profile-edit #jform_courses_servis .service__item .course_slot input {
    max-width: 100% !important;
    width: 100% !important;
}
.profile-edit #jform_courses_servis .service__item .course_desc textarea {
    display: block !important;
    width: 100% !important;
    max-width: 100% !important;
    min-height: 96px !important;
    resize: vertical;
}
.profile-edit #jform_courses_servis .service__item .course_media .course-media-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100% !important;
    max-width: 100% !important;
}
.profile-edit #jform_courses_servis .service__item .course_media .course-media-file-input {
    max-width: 100% !important;
    width: 100% !important;
    min-height: 0 !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
}
.profile-edit #jform_courses_servis .service__item .course_media .course-media-current {
    font-size: 13px;
    line-height: 1.35;
    color: #666;
    word-break: break-word;
    max-width: 100% !important;
}
.profile-edit #jform_courses_servis .service__item .course_desc textarea {
	display: block !important;
	width: 255px !important;
	max-width: 255px !important;
	min-height: 96px !important;
	resize: vertical;
}
.profile-edit #jform_courses_servis .service__item .course_media .course-media-file-input {
	max-width: 255px !important;
	width: 255px !important;
	min-height: 0 !important;
	padding: 0 !important;
	border: 0 !important;
	border-radius: 0 !important;
	background: transparent !important;
}
.profile-edit #jform_courses_servis .service__item .course_media .course-media-current {
	font-size: 13px;
	line-height: 1.35;
	color: #666;
	word-break: break-word;
}
.profile-edit #jform_courses_servis .btn-remove-service {
	display: inline-block;
	padding: 4px 16px;
	margin: 8px 0 0;
	border: 1px solid #d9d9d9;
	border-radius: 6px;
	background: #f5f5f5;
	color: #333;
	font-size: 13px;
	cursor: pointer;
	transition: all 0.2s ease;
}
.profile-edit #jform_courses_servis .btn-remove-service:hover {
	background: #e8e8e8;
	border-color: #bbb;
}
.profile-edit #jform_courses_servis .service__item .course_mode.is-free .course_slot {
	display: none !important;
}
.profile-edit #jform_courses_servis .service__item:not(.is-free-mode) .course_concurrent,
.profile-edit #jform_courses_servis .service__item:not(.has-capacity) .course_concurrent {
	display: none !important;
}
.profile-edit #jform_searches_servis .stock_key {
	margin: 12px;
	width: 37px;
	height: 37px;
	border: 1px solid #000;
	border-radius: 50%;
	cursor: pointer;
	position: relative;
}
.profile-edit #jform_searches_servis .stock_key::before,
.profile-edit #jform_searches_servis .stock_key::after {
	content: "";
	position: absolute;
	left: 50%;
	top: 50%;
	background: #000;
	transform: translate(-50%, -50%);
}
.profile-edit #jform_searches_servis .service__item .search_desc,
.profile-edit #jform_searches_servis .service__item .search_title,
.profile-edit #jform_searches_servis .service__item .search_media,
.profile-edit #jform_searches_servis .service__item .search_price,
.profile-edit #jform_searches_servis .service__item .search_duration,
.profile-edit #jform_searches_servis .service__item .search_capacity,
.profile-edit #jform_searches_servis .service__item .search_mode,
.profile-edit #jform_searches_servis .service__item .search_slot {
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    padding: 4px 0 !important;
    gap: 4px;
    width: 100% !important;
}
.profile-edit #jform_searches_servis .service__item .search_desc label,
.profile-edit #jform_searches_servis .service__item .search_title label,
.profile-edit #jform_searches_servis .service__item .search_media label,
.profile-edit #jform_searches_servis .service__item .search_price label,
.profile-edit #jform_searches_servis .service__item .search_capacity label,
.profile-edit #jform_searches_servis .service__item .search_mode label,
.profile-edit #jform_searches_servis .service__item .search_slot label {
    min-width: 0 !important;
    width: 100% !important;
    display: block !important;
    padding-right: 0 !important;
}
.profile-edit #jform_searches_servis .service__item .search_duration {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    padding: 4px 0 !important;
    gap: 4px !important;
    flex-wrap: wrap !important;
    width: 100% !important;
}
.profile-edit #jform_searches_servis .service__item .search_duration label {
    min-width: 0 !important;
    width: auto !important;
    display: inline !important;
    padding-right: 4px !important;
}
.profile-edit #jform_searches_servis .service__item .search_duration select {
    max-width: 80px !important;
    width: auto !important;
    display: inline-block !important;
}
.profile-edit #jform_searches_servis .service__item .search_price input,
.profile-edit #jform_searches_servis .service__item .search_capacity input {
    max-width: 90px !important;
    width: 90px !important;
    text-align: center;
}
.profile-edit #jform_searches_servis .service__item .search_mode select {
	max-width: 180px !important;
	width: 180px !important;
}
.profile-edit #jform_searches_servis .service__item .search_media {
	align-items: flex-start !important;
}
.profile-edit #jform_searches_servis .service__item .search_media .search-media-field {
	display: flex;
	flex-direction: column;
	gap: 8px;
	width: 255px;
}
.profile-edit #jform_searches_servis .service__item .search_desc input,
.profile-edit #jform_searches_servis .service__item .search_title input,
.profile-edit #jform_searches_servis .service__item .search_media input,
.profile-edit #jform_searches_servis .service__item .search_slot input {
	max-width: 255px !important;
	width: 255px !important;
}
.profile-edit #jform_searches_servis .service__item .search_desc textarea {
	display: block !important;
	width: 255px !important;
	max-width: 255px !important;
	min-height: 96px !important;
	resize: vertical;
}
.profile-edit #jform_searches_servis .service__item .search_media .search-media-file-input {
	max-width: 255px !important;
	width: 255px !important;
	min-height: 0 !important;
	padding: 0 !important;
	border: 0 !important;
	border-radius: 0 !important;
	background: transparent !important;
}
.profile-edit #jform_searches_servis .service__item .search_media .search-media-current {
	font-size: 13px;
	line-height: 1.35;
	color: #666;
	word-break: break-word;
}
.profile-edit #jform_searches_servis .btn-remove-service {
	display: inline-block;
	padding: 4px 16px;
	margin: 8px 0 0;
	border: 1px solid #d9d9d9;
	border-radius: 6px;
	background: #f5f5f5;
	color: #333;
	font-size: 13px;
	cursor: pointer;
	transition: all 0.2s ease;
}
.profile-edit #jform_searches_servis .btn-remove-service:hover {
	background: #e8e8e8;
	border-color: #bbb;
}
.profile-edit #jform_searches_servis .service__item .search_mode.is-free .search_slot {
	display: none !important;
}

.profile-edit #jsn-form .lk-login-hint { margin: 0 0 14px !important; text-align: left; color: #222; }
.profile-edit #jsn-form .email-readonly-group .star { display: none !important; }
.profile-edit #jsn-form .email-readonly-group input[readonly] {
	background: #efefef !important;
	color: #777 !important;
	border-color: #d0d0d0 !important;
	cursor: not-allowed !important;
}
.profile-edit #jsn-form .telefon-group .controls,
.profile-edit #jsn-form .email1-group .controls,
.profile-edit #jsn-form .email-readonly-group .controls {
	display: flex !important;
	align-items: center !important;
	gap: 10px !important;
	max-width: 680px !important;
}
.profile-edit #jsn-form .telefon-group .controls::before,
.profile-edit #jsn-form .email1-group .controls::before,
.profile-edit #jsn-form .email-readonly-group .controls::before {
	display: inline-block !important;
	margin: 0 !important;
	flex: 0 0 24px !important;
	width: 24px !important;
	height: 17px !important;
	background-size: contain !important;
	background-position: center center !important;
}
.profile-edit #jsn-form .telefon-group .controls input,
.profile-edit #jsn-form .email1-group .controls input,
.profile-edit #jsn-form .email-readonly-group .controls input {
	flex: 1 1 auto !important;
	max-width: none !important;
	width: auto !important;
}
.profile-edit #jsn-form .address-group { display: flex; flex-wrap: wrap; gap: 12px; }
.profile-edit #jsn-form .address-group .control-group { flex: 1 1 220px; min-width: 200px; }
.profile-edit #jsn-form .name-group,
.profile-edit #jsn-form .mail-group,
.profile-edit #jsn-form .links-group,
.profile-edit #jsn-form .o_sebe-group { width: 100%; }
.profile-edit #jsn-form .mail-group,
.profile-edit #jsn-form .name-group,
.profile-edit #jsn-form .sity-group {
	display: block !important;
	margin-right: 0 !important;
}

.profile-edit .lk-avatar-edit-controls { display: flex !important; align-items: center; gap: 12px; }
.profile-edit .lk-avatar-edit-group .controls {
	max-width: 680px !important;
	padding: 0 !important;
	background: transparent !important;
	border: 0 !important;
	border-radius: 0 !important;
	box-shadow: none !important;
}
.profile-edit .lk-avatar-trigger { padding: 0; border: 0; background: transparent; cursor: pointer; }
.profile-edit .lk-avatar-preview {
	width: 72px !important;
	height: 72px !important;
	object-fit: cover;
	border-radius: 50% !important;
	border: 2px solid #ececec !important;
	display: block;
}
.profile-edit .lk-avatar-edit-group .jsn-p-avatar {
	width: 72px !important;
	height: 72px !important;
	position: relative !important;
}
.profile-edit .lk-avatar-edit-group .jsn-p-avatar .avatar-online {
	right: -1px;
	bottom: -1px;
}
.profile-edit .lk-avatar-file-input { display: none !important; }
.profile-edit .lk-avatar-help { color: #666; font-size: 13px; }

.profile-edit .lk-portfolio-edit-controls { display: block !important; }
.profile-edit .portfolio_field-group {
	display: block !important;
	position: static !important;
	margin: 0 0 18px !important;
}
.profile-edit .portfolio_field-group .controls {
	display: block !important;
	width: 100% !important;
	height: auto !important;
	margin: 0 !important;
	position: relative !important;
	vertical-align: baseline !important;
	box-shadow: none !important;
	background: transparent !important;
	border: 0 !important;
	cursor: default !important;
}
.profile-edit .portfolio_field-group > .control-label {
	display: block !important;
	margin: 0 0 8px !important;
}
.profile-edit .portfolio_field-group .lk-portfolio-edit-controls {
	display: block !important;
	width: 100% !important;
	height: auto !important;
	margin: 0 !important;
	position: static !important;
}
.profile-edit .lk-portfolio-upload-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	height: 36px;
	padding: 0 16px;
	border-radius: 18px;
	background: #f9ce53;
	color: #000;
	font-family: "GothamPro-Bold", sans-serif;
	font-size: 14px;
	cursor: pointer;
	margin: 0 0 10px 0;
	white-space: nowrap;
}
.profile-edit #jform_upload_portfolio_field {
	display: none !important;
	width: auto !important;
	max-width: none !important;
}
.profile-edit #jsn-form #jform_vyberite_spetsialnos {
	display: block !important;
	flex-wrap: nowrap !important;
}
.profile-edit #jsn-form #jform_vyberite_spetsialnos > label.checkbox {
	display: flex !important;
	align-items: center !important;
	gap: 10px !important;
	flex: none !important;
	max-width: 100% !important;
	margin: 0 0 8px !important;
	line-height: 1.35 !important;
	overflow: visible !important;
	padding-right: 0 !important;
}
.profile-edit #jsn-form #jform_vyberite_spetsialnos > label.checkbox::before,
.profile-edit #jsn-form #jform_vyberite_spetsialnos > label.checkbox::after {
	content: none !important;
	display: none !important;
}
.profile-edit #jsn-form #jform_vyberite_spetsialnos .checkbox input[type="checkbox"] {
	display: inline-block !important;
	appearance: auto !important;
	-webkit-appearance: checkbox !important;
	position: static !important;
	opacity: 1 !important;
	width: 16px !important;
	height: 16px !important;
	margin: 0 !important;
	flex: 0 0 16px !important;
}
.profile-edit .lk-portfolio-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 10px;
}
.profile-edit .lk-edit-portfolio-grid {
	display: flex !important;
	flex-wrap: wrap !important;
	gap: 12px !important;
	align-items: flex-start !important;
	margin-top: 10px !important;
	clear: both !important;
	width: 100% !important;
}
.profile-edit .lk-edit-portfolio-item {
	position: relative !important;
	display: inline-block !important;
	flex: 0 0 140px !important;
	width: 140px !important;
	height: 140px !important;
	border-radius: 12px;
	overflow: hidden !important;
}
.profile-edit .lk-edit-portfolio-item img,
.profile-edit .lk-portfolio-item img {
	display: block !important;
	width: 100% !important;
	height: 100% !important;
	max-width: none !important;
	object-fit: cover !important;
	border: 1px solid #ddd;
	border-radius: 10px;
}
.profile-edit .lk-portfolio-remove {
	position: absolute;
	right: 6px;
	top: 6px;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	border: none;
	background: rgba(0, 0, 0, 0.65);
	color: #fff;
	font-size: 16px;
	line-height: 1;
	cursor: pointer;
	z-index: 2;
}
.profile-edit .lk-portfolio-remove:hover { background: rgba(0, 0, 0, 0.8); }

.profile-edit .lk-edit-form-footer {
	display: flex;
	flex-wrap: wrap;
	gap: 14px;
	align-items: center;
	position: static !important;
	width: 100%;
	margin: 0 !important;
	padding: 12px 0 0 !important;
	border-top: 1px solid #e9e9e9;
	clear: both;
	margin-top: 18px !important;
}
.profile-edit .lk-edit-form-footer .dale {
	margin: 0 !important;
	float: none !important;
	position: static !important;
}
@media (max-width: 768px) {
	.profile-edit {
		padding-bottom: 28px;
	}
	.profile-edit #member-profile {
		width: 100%;
	}
	.profile-edit #jform_courses_servis > label {
		max-width: 100% !important;
		margin-bottom: 28px !important;
	}
	.profile-edit #jform_courses_servis > label > b {
		margin-left: 12px !important;
	}
	.profile-edit #jform_courses_servis > label .flex_wrap,
	.profile-edit #jform_courses_servis > label .service_list {
		width: 100% !important;
	}
	.profile-edit #jform_courses_servis .service__item {
		width: 100% !important;
		max-width: 100% !important;
		margin: 0 !important;
		padding: 12px 0 22px !important;
		box-sizing: border-box !important;
	}
	.profile-edit #jform_courses_servis .service__item::after {
		left: 0 !important;
		right: 0 !important;
		width: 100% !important;
	}
	.profile-edit #jform_courses_servis .service__item .course_desc,
	.profile-edit #jform_courses_servis .service__item .course_title,
	.profile-edit #jform_courses_servis .service__item .course_media,
	.profile-edit #jform_courses_servis .service__item .course_price,
	.profile-edit #jform_courses_servis .service__item .course_duration,
	.profile-edit #jform_courses_servis .service__item .course_capacity,
	.profile-edit #jform_courses_servis .service__item .course_concurrent,
	.profile-edit #jform_courses_servis .service__item .course_mode,
	.profile-edit #jform_courses_servis .service__item .course_slot {
		flex-direction: column !important;
		align-items: flex-start !important;
		gap: 8px !important;
		padding: 0 0 12px !important;
		width: 100% !important;
		box-sizing: border-box !important;
	}
	.profile-edit #jform_courses_servis .service__item .course_desc label,
	.profile-edit #jform_courses_servis .service__item .course_title label,
	.profile-edit #jform_courses_servis .service__item .course_media label,
	.profile-edit #jform_courses_servis .service__item .course_price label,
	.profile-edit #jform_courses_servis .service__item .course_duration label,
	.profile-edit #jform_courses_servis .service__item .course_capacity label,
	.profile-edit #jform_courses_servis .service__item .course_concurrent label,
	.profile-edit #jform_courses_servis .service__item .course_mode label,
	.profile-edit #jform_courses_servis .service__item .course_slot label {
		min-width: 0 !important;
		width: 100% !important;
		padding-right: 0 !important;
	}
	.profile-edit #jform_courses_servis .service__item .course_media .course-media-field,
	.profile-edit #jform_courses_servis .service__item .course_desc textarea,
	.profile-edit #jform_courses_servis .service__item .course_title input,
	.profile-edit #jform_courses_servis .service__item .course_media input,
	.profile-edit #jform_courses_servis .service__item .course_slot input,
	.profile-edit #jform_courses_servis .service__item .course_media .course-media-file-input,
	.profile-edit #jform_courses_servis .service__item .course_price input,
	.profile-edit #jform_courses_servis .service__item .course_capacity input,
	.profile-edit #jform_courses_servis .service__item .course_concurrent input,
	.profile-edit #jform_courses_servis .service__item .course_duration select,
	.profile-edit #jform_courses_servis .service__item .course_mode select {
		width: 100% !important;
		max-width: 100% !important;
		box-sizing: border-box !important;
	}
	.profile-edit #jform_courses_servis .service__item .stock-remove {
		position: relative !important;
		right: auto !important;
		top: auto !important;
		margin: 4px 0 0 !important;
	}
	.profile-edit .lk-edit-form-footer {
		padding-top: 10px !important;
	}
	.profile-edit #jform_stocks_servis select {
		width: 100% !important;
		max-width: 100% !important;
		box-sizing: border-box !important;
		font-size: 16px !important;
		height: 44px !important;
	}
}
.profile-edit #jform_searches_servis .service__item .search_desc textarea,
.search-description-input {
    display: block !important;
    width: 100% !important;
    max-width: 100% !important;
    min-height: 60px !important;
    resize: vertical !important;
    box-sizing: border-box !important;
    font-family: inherit !important;
    font-size: 14px !important;
    line-height: 1.5 !important;
    padding: 8px 12px !important;
    border: 1px solid #d9d9d9 !important;
    border-radius: 8px !important;
    background: #fff !important;
    transition: height 0.1s ease !important;
}
.profile-edit #jform_stocks_servis .service__item .stock-remove {
    display: inline-block;
    width: 36px;
    height: 36px;
    border: 1px solid #000;
    border-radius: 50%;
    position: relative;
    cursor: pointer;
    background: #ffc107;
    margin-top: 10px;
    flex-shrink: 0;
    z-index: 2;
}
.profile-edit #jform_stocks_servis .service__item .stock-remove::before,
.profile-edit #jform_stocks_servis .service__item .stock-remove::after {
    content: "";
    position: absolute;
    left: 50%;
    top: 50%;
    width: 14px;
    height: 2px;
    background: #000;
    transform-origin: center;
}
.profile-edit #jform_stocks_servis .service__item .stock-remove::before {
    transform: translate(-50%, -50%) rotate(45deg);
}
.profile-edit #jform_stocks_servis .service__item .stock-remove::after {
    transform: translate(-50%, -50%) rotate(-45deg);
}
#jform_searches_servis .service__item {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    margin: 15px 0 !important;
}
#jform_searches_servis .service__item > span {
    display: block !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    padding: 4px 0 !important;
}
#jform_searches_servis .service__item input,
#jform_searches_servis .service__item select,
#jform_searches_servis .service__item textarea {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
#jform_searches_servis .service__item .search_duration select {
    max-width: 80px !important;
    width: auto !important;
    display: inline-block !important;
}
#jform_searches_servis .service__item .search_media .search-media-field {
    display: flex !important;
    flex-direction: column !important;
    gap: 6px !important;
    width: 100% !important;
    max-width: 100% !important;
}
.profile-edit #jform_searches_servis .search-add-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 37px;
    height: 37px;
    margin: 12px 0 0;
    border: 1px solid #000;
    border-radius: 50%;
    background: #fff;
    color: #000;
    font-size: 24px;
    font-weight: 300;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
    position: relative;
}

.profile-edit #jform_searches_servis .search-add-btn:hover {
    background: #f5f5f5;
    transform: scale(1.05);
}

.profile-edit #jform_searches_servis .search-add-btn:active {
    transform: scale(0.95);
}

</style>
<script>
(function(){
	var isAdmin = <?php echo $isAdministrator ? 'true' : 'false'; ?>;
	var isMaster = <?php echo $isMaster ? 'true' : 'false'; ?>;
	var servicesByCategory = <?php echo $servicesJson ?: '{}'; ?>;
	var missingServiceOptionsByCategory = <?php echo $missingServiceOptionsJson ?: '{}'; ?>;
	var durationOptions = <?php echo $durationJson ?: '[]'; ?>;
	var initialServiceRows = <?php echo $existingServiceRowsJson ?: '[]'; ?>;
	var initialStockRows = <?php echo $existingStockRowsJson ?: '[]'; ?>;
	var initialCourseRows = <?php echo $existingCourseRowsJson ?: '[]'; ?>;
	var initialSearchRows = <?php echo $existingSearchRowsJson ?: '[]'; ?>;
	var pendingServiceRows = Array.isArray(initialServiceRows) ? initialServiceRows.slice() : [];
	var pendingStockRows = Array.isArray(initialStockRows) ? initialStockRows.slice() : [];
	var pendingCourseRows = Array.isArray(initialCourseRows) ? initialCourseRows.slice() : [];
	var pendingSearchRows = Array.isArray(initialSearchRows) ? initialSearchRows.slice() : [];
	var tabs = document.querySelectorAll('#jsn-profile-tabs .z-tab');
	var contents = document.querySelectorAll('#jsn-form .z-container.lk-edit-tab-panels > .z-content');
	if (!tabs.length || !contents.length) return;

	function activateTab(idx){
		tabs.forEach(function(t, i){
			t.classList.toggle('z-active', i === idx);
		});
		contents.forEach(function(c, i){
			c.classList.toggle('z-active', i === idx);
			c.style.display = i === idx ? 'block' : 'none';
		});
	}

	tabs.forEach(function(tab, idx){
		tab.addEventListener('click', function(e){
			e.preventDefault();
			activateTab(idx);
		});
		var link = tab.querySelector('.z-link');
		if (link) {
			link.addEventListener('click', function(e){
				e.preventDefault();
				activateTab(idx);
			});
		}
	});
	activateTab(0);

	// If placeholders are empty, mirror from label text for better readability.
	document.querySelectorAll('.profile-edit #jsn-form .control-group').forEach(function(group){
		var label = group.querySelector('.control-label label');
		var input = group.querySelector('.controls input[type="text"], .controls input[type="email"], .controls input[type="password"], .controls input[type="tel"], .controls input[type="url"], .controls textarea');
		if (!label || !input) return;
		if (input.getAttribute('placeholder')) return;
		var text = (label.textContent || '').replace(/\*/g, '').trim();
		if (text) input.setAttribute('placeholder', text);
	});

	// Hide "Действия в компонентах" only for administrators in profile edit.
	if (isAdmin) {
		document.querySelectorAll('.profile-edit #jsn-form .control-group').forEach(function(group){
			var label = group.querySelector('.control-label label');
			var labelText = label ? (label.textContent || '').trim().toLowerCase() : '';
			var actionInput = group.querySelector('[name*=\"[actionlogs]\"], [id*=\"actionlogs\"], [name*=\"[action_logs]\"], [id*=\"action_logs\"]');
			if (labelText.indexOf('действия в компонентах') !== -1 || actionInput) {
				group.style.display = 'none';
			}
		});
	}

	// Clients: show email as login and keep it read-only.
	if (!isMaster) {
		document.querySelectorAll('.profile-edit input[type="email"][name*="[email]"], .profile-edit input[type="email"][name*="[email1]"], .profile-edit #jform_email1').forEach(function(input){
			input.readOnly = true;
			input.setAttribute('readonly', 'readonly');
			input.classList.add('readonly-email');
		});
	}

	// Specialty tab (same UX as registration): sync active state + filter by master type.
	function syncSpecialtyActiveState() {
		document.querySelectorAll('#jform_vyberite_spetsialnos > label').forEach(function(label){
			var checkbox = label.querySelector('input[type="checkbox"]');
			label.classList.toggle('active', !!(checkbox && checkbox.checked));
		});
	}
	function filterSpecialtiesByType() {
		var isRepairProfile = <?php echo $profileMasterType === '2' ? 'true' : 'false'; ?>;
		document.querySelectorAll('#jform_vyberite_spetsialnos > label').forEach(function(label){
			var type = label.getAttribute('data-type') || '';
			var shouldShow = isRepairProfile ? type === 'repair' : type === 'beauty';
			label.style.display = shouldShow ? '' : 'none';
			if (!shouldShow) {
				var checkbox = label.querySelector('input[type="checkbox"]');
				if (checkbox) {
					checkbox.checked = false;
				}
				label.classList.remove('active');
			}
		});
	}
	function selectedSpecialtyIds() {
		var ids = [];
		document.querySelectorAll('#jform_vyberite_spetsialnos input[type="checkbox"]:checked').forEach(function(checkbox){
			var id = parseInt(checkbox.value || '0', 10);
			if (id > 0) {
				ids.push(id);
			}
		});
		return ids;
	}
	function labelsBySpecialtyId() {
		var labels = {};
		document.querySelectorAll('#jform_vyberite_spetsialnos input[type="checkbox"]').forEach(function(checkbox){
			var label = checkbox.closest('label');
			if (!label) {
				return;
			}
			labels[parseInt(checkbox.value || '0', 10)] = (label.textContent || '').trim();
		});
		return labels;
	}
	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function(char){
			switch (char) {
				case '&': return '&amp;';
				case '<': return '&lt;';
				case '>': return '&gt;';
				case '"': return '&quot;';
				case "'": return '&#39;';
				default: return char;
			}
		});
	}
	function ensureSelectValue(select, value, label) {
		if (!select || !value) {
			return;
		}
		var exists = false;
		Array.prototype.slice.call(select.options || []).forEach(function(option){
			if (String(option.value || '') === String(value)) {
				exists = true;
			}
		});
		if (exists) {
			return;
		}
		var option = document.createElement('option');
		option.value = String(value);
		option.textContent = String(label || ('Услуга #' + value));
		option.setAttribute('data-restored', '1');
		select.appendChild(option);
	}
	function serviceOptionsHtml(categoryId) {
		var items = servicesByCategory[String(categoryId)] || servicesByCategory[categoryId] || [];
		var html = '<option value="">Выберите услугу...</option>';
		items.forEach(function(service){
			html += '<option value="' + escapeHtml(service.id) + '">' + escapeHtml(service.title) + '</option>';
			if (Array.isArray(service.tags) && service.tags.length) {
				service.tags.forEach(function(tag){
					html += '<option value="' + escapeHtml(service.id + '-' + tag.id) + '">' + escapeHtml(service.title + ' / ' + tag.title) + '</option>';
				});
			}
		});
		var missing = missingServiceOptionsByCategory[String(categoryId)] || missingServiceOptionsByCategory[categoryId] || [];
		missing.forEach(function(option){
			if (!option || !option.value) {
				return;
			}
			html += '<option value="' + escapeHtml(option.value) + '">' + escapeHtml(option.label || option.value) + '</option>';
		});
		return html;
	}
	function durationOptionsHtml() {
		var html = '';
		durationOptions.forEach(function(value){
			html += '<option value="' + value + '">' + value + '</option>';
		});
		return html;
	}
	function addServiceRow(categoryLabel, rowData) {
		var catId = parseInt(categoryLabel.getAttribute('data-id') || '0', 10);
		if (!catId) {
			return;
		}
		var serviceList = categoryLabel.querySelector('.service_list');
		if (!serviceList) {
			return;
		}
		var row = document.createElement('p');
		row.className = 'service__item';
		row.setAttribute('data-category-id', String(catId));
		row.innerHTML =
			'<select class="service-select">' + serviceOptionsHtml(catId) + '</select>' +
			'<span class="time"><label>Время:</label><select class="time-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
			'<span class="time2"><label>Перерыв:</label><select class="pause-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
			'<span class="price"><label>Стоимость:</label><input type="number" min="0" step="1" class="price-input" value="" /></span>' +
			'<button type="button" class="btn-remove-service">Удалить</button>';
		serviceList.appendChild(row);
		if (rowData) {
			var serviceSelect = row.querySelector('.service-select');
			var timeSelect = row.querySelector('.time-select');
			var pauseSelect = row.querySelector('.pause-select');
			var priceInput = row.querySelector('.price-input');
			if (serviceSelect) ensureSelectValue(serviceSelect, String(rowData.serviceRaw || ''), String(rowData.serviceLabel || ''));
			if (serviceSelect) serviceSelect.value = String(rowData.serviceRaw || '');
			if (timeSelect) timeSelect.value = String(parseInt(rowData.duration || '15', 10) || 15);
			if (pauseSelect) pauseSelect.value = String(parseInt(rowData.pause || '15', 10) || 15);
			if (priceInput) priceInput.value = String(parseInt(rowData.price || '0', 10) || 0);
		}
	}
	function collectServiceRows() {
		var rows = [];
		document.querySelectorAll('#jform_vyberite_usl .service__item').forEach(function(row){
			var catId = String(row.getAttribute('data-category-id') || '');
			var serviceSelect = row.querySelector('.service-select');
			var timeSelect = row.querySelector('.time-select');
			var pauseSelect = row.querySelector('.pause-select');
			var priceInput = row.querySelector('.price-input');
			rows.push({
				categoryId: parseInt(catId || '0', 10) || 0,
				serviceRaw: serviceSelect ? String(serviceSelect.value || '') : '',
				duration: parseInt(timeSelect ? String(timeSelect.value || '0') : '0', 10) || 0,
				pause: parseInt(pauseSelect ? String(pauseSelect.value || '0') : '0', 10) || 0,
				price: parseInt(priceInput ? String(priceInput.value || '0') : '0', 10) || 0
			});
		});
		return rows;
	}
	function buildLegacyPricesFromRows(rows) {
		var grouped = {};
		rows.forEach(function(row){
			var catId = String(row.categoryId || '');
			var serviceRaw = String(row.serviceRaw || '');
			var duration = parseInt(row.duration || 0, 10);
			var pause = parseInt(row.pause || 0, 10);
			var price = parseInt(row.price || 0, 10);
			if (!catId || !serviceRaw || !duration || !price) {
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
		rows.forEach(function(row){
			var catId = String(row.categoryId || '');
			var serviceRaw = String(row.serviceRaw || '');
			var duration = parseInt(row.duration || 0, 10);
			var pause = parseInt(row.pause || 0, 10);
			var price = parseInt(row.price || 0, 10);
			if (!catId || !serviceRaw || !duration || !price) {
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
	function buildLegacyStockPricesFromRows(rows) {
		var grouped = {};
		rows.forEach(function(row){
			var catId = String(row.categoryId || '');
			var serviceRaw = String(row.serviceRaw || '');
			var duration = parseInt(row.duration || 0, 10);
			var pause = parseInt(row.pause || 0, 10);
			var price = parseInt(row.price || 0, 10);
			var oldPrice = parseInt(row.oldPrice || 0, 10);
			var aboutStock = String(row.aboutStock || '').trim();
			var countStock = parseInt(row.countStock || 0, 10);
			if (!catId || !serviceRaw || !duration || !price) {
				return;
			}
			if (!grouped[catId]) {
				grouped[catId] = [];
			}
			grouped[catId].push([price, duration + '.' + pause, serviceRaw, oldPrice, aboutStock, countStock]);
		});
		return grouped;
	}
	function buildViglingStockPayload(rows) {
		var items = [];
		rows.forEach(function(row){
			var catId = String(row.categoryId || '');
			var serviceRaw = String(row.serviceRaw || '');
			var duration = parseInt(row.duration || 0, 10);
			var pause = parseInt(row.pause || 0, 10);
			var price = parseInt(row.price || 0, 10);
			var oldPrice = parseInt(row.oldPrice || 0, 10);
			var aboutStock = String(row.aboutStock || '').trim();
			var countStock = parseInt(row.countStock || 0, 10);
			if (!catId || !serviceRaw || !duration || !price) {
				return;
			}
			items.push({
				cat_id: catId,
				service_raw: serviceRaw,
				price: price,
				duration: String(duration + '.' + pause),
				old_price: oldPrice,
				about_stock: aboutStock,
				count_stock: countStock
			});
		});
		return {version: 1, items: items};
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
	function basenameFromPath(path) {
		var raw = String(path || '').trim();
		if (!raw) {
			return '';
		}
		var parts = raw.split('/');
		return parts.length ? parts[parts.length - 1] : raw;
	}
	function syncCourseMediaState(row) {
		if (!row) {
			return;
		}
		var fileInput = row.querySelector('.course-media-file-input');
		var hiddenInput = row.querySelector('.course-media-input');
		var currentNode = row.querySelector('.course-media-current');
		if (!currentNode) {
			return;
		}
		var fileName = '';
		if (fileInput && fileInput.files && fileInput.files.length > 0) {
			fileName = String(fileInput.files[0].name || '').trim();
		}
		if (fileName) {
			currentNode.textContent = 'Новый файл: ' + fileName;
			return;
		}
		var existingPath = hiddenInput ? String(hiddenInput.value || '').trim() : '';
		currentNode.textContent = existingPath ? ('Текущий файл: ' + basenameFromPath(existingPath)) : 'Файл не выбран';
	}
	function syncCourseModeState(row) {
		if (!row) {
			return;
		}
		var modeSelect = row.querySelector('.course-mode-select');
		var modeWrap = row.querySelector('.course_mode');
		var slotInput = row.querySelector('.course-slot-input');
		var capacityInput = row.querySelector('.course-capacity-input');
		var concurrentInput = row.querySelector('.course-concurrent-input');
		var mode = modeSelect ? String(modeSelect.value || 'free') : 'free';
		var capacity = parseInt(capacityInput ? String(capacityInput.value || '0') : '0', 10) || 0;
		if (modeWrap) {
			modeWrap.classList.toggle('is-free', mode !== 'fixed');
		}
		row.classList.toggle('is-free-mode', mode !== 'fixed');
		row.classList.toggle('has-capacity', capacity >= 1);
		if (slotInput && mode !== 'fixed') {
			slotInput.value = '';
		}
		if (concurrentInput) {
			var maxConcurrent = Math.max(1, capacity);
			concurrentInput.max = String(maxConcurrent);
			var concurrent = parseInt(String(concurrentInput.value || '1'), 10) || 1;
			if (concurrent < 1) {
				concurrent = 1;
			}
			if (concurrent > maxConcurrent) {
				concurrent = maxConcurrent;
			}
			concurrentInput.value = String(concurrent);
		}
	}
	function addCourseRow(categoryLabel, rowData) {
	var catId = parseInt(categoryLabel.getAttribute('data-id') || '0', 10);
	if (!catId) {
		return;
	}
	var serviceList = categoryLabel.querySelector('.service_list');
	if (!serviceList) {
		return;
	}
	var row = document.createElement('p');
	row.className = 'service__item';
	row.setAttribute('data-category-id', String(catId));
	row.setAttribute('data-course-id', rowData && rowData.id ? String(parseInt(rowData.id, 10) || 0) : '0');
	row.setAttribute('data-booking-count', rowData && rowData.bookingCount ? String(parseInt(rowData.bookingCount, 10) || 0) : '0');
	row.innerHTML =
		'<span class="course_title"><label>Название курса:</label><input type="text" maxlength="150" class="course-title-input" value="" /></span>' +
		'<span class="course_desc"><label>Описание:</label><textarea maxlength="150" placeholder="До 150 символов" class="course-description-input"></textarea></span>' +
		'<span class="course_media"><label>Изображение:</label><span class="course-media-field"><input type="hidden" class="course-media-input" value="" /><input type="file" name="jform[upload_course_media][]" accept="image/*" class="course-media-file-input" /><span class="course-media-current">Файл не выбран</span></span></span>' +
		'<span class="course_price"><label>Стоимость:</label><input type="number" min="0" step="1" class="course-price-input" value="" /></span>' +
		'<span class="course_duration"><label>Длительность:</label><select class="course-duration-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
		// Keep both fields: Лимит мест, then Одновременно участников.
		'<span class="course_capacity"><label>Лимит мест:</label><input type="number" min="1" step="1" class="course-capacity-input" value="1" /></span>' +
		'<span class="course_concurrent"><label>Одновременно участников:</label><input type="number" min="1" step="1" class="course-concurrent-input" value="1" /></span>' +
		'<span class="course_mode"><label>Режим записи:</label><select class="course-mode-select"><option value="free">Любое время</option><option value="fixed">Фиксированная дата</option></select><span class="course_slot"><label>Дата и время:</label><input type="datetime-local" class="course-slot-input" value="" /></span></span>' +
		'<button type="button" class="btn-remove-service">Удалить</button>';
	serviceList.appendChild(row);
	if (rowData) {
		var titleInput = row.querySelector('.course-title-input');
		var descriptionInput = row.querySelector('.course-description-input');
		var mediaInput = row.querySelector('.course-media-input');
		var priceInput = row.querySelector('.course-price-input');
		var durationSelect = row.querySelector('.course-duration-select');
		var capacityInput = row.querySelector('.course-capacity-input');
		var concurrentInput = row.querySelector('.course-concurrent-input');
		var modeSelect = row.querySelector('.course-mode-select');
		var slotInput = row.querySelector('.course-slot-input');
		if (titleInput) titleInput.value = String(rowData.title || rowData.description || '');
		if (descriptionInput) descriptionInput.value = String(rowData.description || '');
		if (mediaInput) mediaInput.value = String(rowData.mediaPath || '');
		if (priceInput) priceInput.value = String(parseInt(rowData.price || '0', 10) || 0);
		if (durationSelect) durationSelect.value = String(parseInt(rowData.duration || '60', 10) || 60);
		if (capacityInput) capacityInput.value = String(parseInt(rowData.capacity || '1', 10) || 1);
		if (concurrentInput) concurrentInput.value = String(Math.max(1, parseInt(rowData.concurrentParticipants || '1', 10) || 1));
		if (modeSelect) modeSelect.value = String(rowData.bookingMode || 'free');
		if (slotInput) slotInput.value = formatDatetimeLocal(String(rowData.slotStartUtc || ''));
	}
	syncCourseModeState(row);
	syncCourseMediaState(row);
	syncCourseEditConstraints(row);
	var modeSelect = row.querySelector('.course-mode-select');
	if (modeSelect) {
		modeSelect.addEventListener('change', function(){
			syncCourseModeState(row);
			syncCourseEditConstraints(row);
		});
	}
	var capacityInputListen = row.querySelector('.course-capacity-input');
	if (capacityInputListen) {
		capacityInputListen.addEventListener('input', function(){
			syncCourseModeState(row);
			syncCourseEditConstraints(row);
		});
		capacityInputListen.addEventListener('change', function(){
			syncCourseModeState(row);
			syncCourseEditConstraints(row);
		});
	}
	var concurrentInputListen = row.querySelector('.course-concurrent-input');
	if (concurrentInputListen) {
		concurrentInputListen.addEventListener('change', function(){
			syncCourseModeState(row);
		});
	}
	var mediaFileInput = row.querySelector('.course-media-file-input');
	if (mediaFileInput) {
		mediaFileInput.addEventListener('change', function(){
			syncCourseMediaState(row);
		});
	}
}
	function syncCourseEditConstraints(row) {
		if (!row) {
			return;
		}
		var bookingCount = parseInt(String(row.getAttribute('data-booking-count') || '0'), 10) || 0;
		var durationSelect = row.querySelector('.course-duration-select');
		var capacityInput = row.querySelector('.course-capacity-input');
		var modeSelect = row.querySelector('.course-mode-select');
		var bookingMode = modeSelect ? String(modeSelect.value || 'free') : 'free';
		if (durationSelect) {
			var durationLocked = bookingCount > 0 && bookingMode === 'fixed';
			durationSelect.disabled = durationLocked;
			durationSelect.title = durationLocked ? 'Нельзя менять длительность fixed-курса после появления участников' : '';
		}
		if (capacityInput) {
			var minCapacity = bookingCount > 0 ? bookingCount : 1;
			capacityInput.min = String(minCapacity);
			if ((parseInt(String(capacityInput.value || '0'), 10) || 0) < minCapacity) {
				capacityInput.value = String(minCapacity);
			}
		}
		syncCourseModeState(row);
	}
	function collectCourseRows() {
		var rows = [];
		document.querySelectorAll('#jform_courses_servis .service__item').forEach(function(row){
			var catId = String(row.getAttribute('data-category-id') || '');
			var titleInput = row.querySelector('.course-title-input');
			var descriptionInput = row.querySelector('.course-description-input');
			var mediaInput = row.querySelector('.course-media-input');
			var priceInput = row.querySelector('.course-price-input');
			var durationSelect = row.querySelector('.course-duration-select');
			var capacityInput = row.querySelector('.course-capacity-input');
			var concurrentInput = row.querySelector('.course-concurrent-input');
			var modeSelect = row.querySelector('.course-mode-select');
			var slotInput = row.querySelector('.course-slot-input');
			rows.push({
				id: parseInt(String(row.getAttribute('data-course-id') || '0'), 10) || 0,
				categoryId: parseInt(catId || '0', 10) || 0,
				title: titleInput ? String(titleInput.value || '') : '',
				description: descriptionInput ? String(descriptionInput.value || '') : '',
				mediaPath: mediaInput ? String(mediaInput.value || '') : '',
				price: parseInt(priceInput ? String(priceInput.value || '0') : '0', 10) || 0,
				duration: parseInt(durationSelect ? String(durationSelect.value || '0') : '0', 10) || 0,
				capacity: parseInt(capacityInput ? String(capacityInput.value || '1') : '1', 10) || 1,
				concurrentParticipants: parseInt(concurrentInput ? String(concurrentInput.value || '1') : '1', 10) || 1,
				bookingMode: modeSelect ? String(modeSelect.value || 'free') : 'free',
				slotStartLocal: slotInput ? String(slotInput.value || '') : '',
				slotStartUtc: normalizeDatetimeFromLocal(slotInput ? String(slotInput.value || '') : '')
			});
		});
		return rows;
	}
	function buildViglingCoursesPayload(rows) {
		var items = [];
		rows.forEach(function(row){
			var categoryId = parseInt(row.categoryId || 0, 10) || 0;
			var title = String(row.title || '').trim();
			var description = String(row.description || '').trim();
			var mediaPath = String(row.mediaPath || '').trim();
			var price = parseInt(row.price || 0, 10) || 0;
			var duration = parseInt(row.duration || 0, 10) || 0;
			var capacity = Math.max(1, parseInt(row.capacity || 1, 10) || 1);
			var bookingMode = String(row.bookingMode || 'free') === 'fixed' ? 'fixed' : 'free';
			var concurrent = Math.max(1, parseInt(row.concurrentParticipants || 1, 10) || 1);
			if (concurrent > capacity) {
				concurrent = capacity;
			}
			var slotStartUtc = String(row.slotStartUtc || '').trim();
			if (!categoryId || !title || !price || !duration) {
				return;
			}
			items.push({
				id: parseInt(row.id || 0, 10) || 0,
				category_id: categoryId,
				title: title,
				description: description,
				media_path: mediaPath,
				price: price,
				duration_min: duration,
				capacity: capacity,
				concurrent_participants: concurrent,
				booking_mode: bookingMode,
				slot_start_utc: bookingMode === 'fixed' ? slotStartUtc : '',
				slot_start_local: bookingMode === 'fixed' ? String(row.slotStartLocal || '').trim() : ''
			});
		});
		return {version: 1, items: items};
	}
	function syncSearchMediaState(row) {
		if (!row) {
			return;
		}
		var fileInput = row.querySelector('.search-media-file-input');
		var hiddenInput = row.querySelector('.search-media-input');
		var currentNode = row.querySelector('.search-media-current');
		if (!currentNode) {
			return;
		}
		var fileName = '';
		if (fileInput && fileInput.files && fileInput.files.length > 0) {
			fileName = String(fileInput.files[0].name || '').trim();
		}
		if (fileName) {
			currentNode.textContent = 'Новый файл: ' + fileName;
			return;
		}
		var existingPath = hiddenInput ? String(hiddenInput.value || '').trim() : '';
		currentNode.textContent = existingPath ? ('Текущий файл: ' + basenameFromPath(existingPath)) : 'Файл не выбран';
	}
	function syncSearchModeState(row) {
		if (!row) {
			return;
		}
		var modeSelect = row.querySelector('.search-mode-select');
		var modeWrap = row.querySelector('.search_mode');
		var slotInput = row.querySelector('.search-slot-input');
		var mode = modeSelect ? String(modeSelect.value || 'free') : 'free';
		if (modeWrap) {
			modeWrap.classList.toggle('is-free', mode !== 'fixed');
		}
		if (slotInput && mode !== 'fixed') {
			slotInput.value = '';
		}
	}
	function syncSearchEditConstraints(row) {
		if (!row) {
			return;
		}
		var bookingCount = parseInt(String(row.getAttribute('data-booking-count') || '0'), 10) || 0;
		var durationSelect = row.querySelector('.search-duration-select');
		var capacityInput = row.querySelector('.search-capacity-input');
		var modeSelect = row.querySelector('.search-mode-select');
		var bookingMode = modeSelect ? String(modeSelect.value || 'free') : 'free';
		if (durationSelect) {
			var durationLocked = bookingCount > 0 && bookingMode === 'fixed';
			durationSelect.disabled = durationLocked;
			durationSelect.title = durationLocked ? 'Нельзя менять длительность fixed-поиска после появления участников' : '';
		}
		if (capacityInput) {
			var minCapacity = bookingCount > 0 ? bookingCount : 1;
			capacityInput.min = String(minCapacity);
			if ((parseInt(String(capacityInput.value || '0'), 10) || 0) < minCapacity) {
				capacityInput.value = String(minCapacity);
			}
		}
	}
function addSearchRow(categoryLabel, rowData) {
	var catId = parseInt(categoryLabel.getAttribute('data-id') || '0', 10);
	if (!catId) {
		return;
	}
	var serviceList = categoryLabel.querySelector('.service_list');
	if (!serviceList) {
		return;
	}
	var row = document.createElement('p');
	row.className = 'service__item';
	row.setAttribute('data-category-id', String(catId));
	row.setAttribute('data-search-id', rowData && rowData.id ? String(parseInt(rowData.id, 10) || 0) : '0');
	row.setAttribute('data-booking-count', rowData && rowData.bookingCount ? String(parseInt(rowData.bookingCount, 10) || 0) : '0');
	row.innerHTML =
		'<span class="search_media"><label>Изображение:</label><span class="search-media-field"><input type="hidden" class="search-media-input" value="" /><input type="file" name="jform[upload_search_media][]" accept="image/*" class="search-media-file-input" style="max-width:100%;width:100%;" /><span class="search-media-current" style="word-break:break-all;overflow-wrap:break-word;white-space:normal;display:inline-block;max-width:100%;">Файл не выбран</span></span></span>' +
		'<span class="search_title"><label>Название поиска:</label><input type="text" maxlength="150" class="search-title-input" value="" style="width:100%;max-width:100%;box-sizing:border-box;" /></span>' +
		'<span class="search_desc"><label>Описание:</label><textarea maxlength="150" placeholder="До 150 символов" class="search-description-input" style="width:100%;max-width:100%;box-sizing:border-box;resize:vertical;"></textarea></span>' +
		'<span class="search_price"><label>Стоимость:</label><input type="number" min="0" step="1" class="search-price-input" value="" style="width:100%;max-width:200px;" /></span>' +
		'<span class="search_duration"><label>Длительность:</label><select class="search-duration-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
		'<span class="search_capacity"><label>Лимит мест:</label><input type="number" min="1" step="1" class="search-capacity-input" value="1" style="width:100%;max-width:100px;" /></span>' +
		'<span class="search_mode"><label>Режим записи:</label><select class="search-mode-select"><option value="free">Любое время</option><option value="fixed">Фиксированная дата</option></select><span class="search_slot"><label>Дата и время:</label><input type="datetime-local" class="search-slot-input" value="" style="width:100%;max-width:250px;" /></span></span>' +
		'<button type="button" class="btn-remove-service">Удалить</button>';
	serviceList.appendChild(row);
	if (rowData) {
		var titleInput = row.querySelector('.search-title-input');
		var descriptionInput = row.querySelector('.search-description-input');
		var mediaInput = row.querySelector('.search-media-input');
		var priceInput = row.querySelector('.search-price-input');
		var durationSelect = row.querySelector('.search-duration-select');
		var capacityInput = row.querySelector('.search-capacity-input');
		var modeSelect = row.querySelector('.search-mode-select');
		var slotInput = row.querySelector('.search-slot-input');
		if (titleInput) titleInput.value = String(rowData.title || rowData.description || '');
		if (descriptionInput) descriptionInput.value = String(rowData.description || '');
		if (mediaInput) mediaInput.value = String(rowData.mediaPath || '');
		if (priceInput) priceInput.value = String(parseInt(rowData.price || '0', 10) || 0);
		if (durationSelect) durationSelect.value = String(parseInt(rowData.duration || '60', 10) || 60);
		if (capacityInput) capacityInput.value = String(parseInt(rowData.capacity || '1', 10) || 1);
		if (modeSelect) modeSelect.value = String(rowData.bookingMode || 'free');
		if (slotInput) slotInput.value = formatDatetimeLocal(String(rowData.slotStartUtc || ''));
	}
	syncSearchModeState(row);
	syncSearchMediaState(row);
	syncSearchEditConstraints(row);
	var modeSelect = row.querySelector('.search-mode-select');
	if (modeSelect) {
		modeSelect.addEventListener('change', function(){
			syncSearchModeState(row);
			syncSearchEditConstraints(row);
		});
	}
	var mediaFileInput = row.querySelector('.search-media-file-input');
	if (mediaFileInput) {
		mediaFileInput.addEventListener('change', function(){
			syncSearchMediaState(row);
		});
	}
}
	function collectSearchRows() {
		var rows = [];
		document.querySelectorAll('#jform_searches_servis .service__item').forEach(function(row){
			var catId = String(row.getAttribute('data-category-id') || '');
			var titleInput = row.querySelector('.search-title-input');
			var descriptionInput = row.querySelector('.search-description-input');
			var mediaInput = row.querySelector('.search-media-input');
			var priceInput = row.querySelector('.search-price-input');
			var durationSelect = row.querySelector('.search-duration-select');
			var capacityInput = row.querySelector('.search-capacity-input');
			var modeSelect = row.querySelector('.search-mode-select');
			var slotInput = row.querySelector('.search-slot-input');
			rows.push({
				id: parseInt(String(row.getAttribute('data-search-id') || '0'), 10) || 0,
				categoryId: parseInt(catId || '0', 10) || 0,
				title: titleInput ? String(titleInput.value || '') : '',
				description: descriptionInput ? String(descriptionInput.value || '') : '',
				mediaPath: mediaInput ? String(mediaInput.value || '') : '',
				price: parseInt(priceInput ? String(priceInput.value || '0') : '0', 10) || 0,
				duration: parseInt(durationSelect ? String(durationSelect.value || '0') : '0', 10) || 0,
				capacity: parseInt(capacityInput ? String(capacityInput.value || '1') : '1', 10) || 1,
				bookingMode: modeSelect ? String(modeSelect.value || 'free') : 'free',
				slotStartLocal: slotInput ? String(slotInput.value || '') : '',
				slotStartUtc: normalizeDatetimeFromLocal(slotInput ? String(slotInput.value || '') : '')
			});
		});
		return rows;
	}
	function buildViglingSearchesPayload(rows) {
		var items = [];
		rows.forEach(function(row){
			var categoryId = parseInt(row.categoryId || 0, 10) || 0;
			var title = String(row.title || '').trim();
			var description = String(row.description || '').trim();
			var mediaPath = String(row.mediaPath || '').trim();
			var price = parseInt(row.price || 0, 10) || 0;
			var duration = parseInt(row.duration || 0, 10) || 0;
			var capacity = Math.max(1, parseInt(row.capacity || 1, 10) || 1);
			var bookingMode = String(row.bookingMode || 'free') === 'fixed' ? 'fixed' : 'free';
			var slotStartUtc = String(row.slotStartUtc || '').trim();
			if (!categoryId || !title || !price || !duration) {
				return;
			}
			items.push({
				id: parseInt(row.id || 0, 10) || 0,
				category_id: categoryId,
				title: title,
				description: description,
				media_path: mediaPath,
				price: price,
				duration_min: duration,
				capacity: capacity,
				booking_mode: bookingMode,
				slot_start_utc: bookingMode === 'fixed' ? slotStartUtc : '',
				slot_start_local: bookingMode === 'fixed' ? String(row.slotStartLocal || '').trim() : ''
			});
		});
		return {version: 1, items: items};
	}
	function renderServiceBuilders() {
		var holder = document.getElementById('jform_vyberite_usl');
		if (!holder) {
			return;
		}
		var ids = selectedSpecialtyIds();
		var currentRows = collectServiceRows();
		if (currentRows.length) {
			pendingServiceRows = currentRows;
		}
		holder.innerHTML = '';
		if (!ids.length) {
			holder.textContent = 'Выберите специальность, чтобы добавить услугу';
			return;
		}
		var labelsById = labelsBySpecialtyId();
		ids.forEach(function(catId){
			var block = document.createElement('label');
			block.className = 'checkbox type_master_open';
			block.setAttribute('data-id', String(catId));
			block.innerHTML =
				(labelsById[catId] || ('Категория #' + catId)) +
				'<b></b>' +
				'<div class="flex_wrap"><div class="service_list"></div><button type="button" class="btn-add-service dale">Добавить услугу</button></div>';
			holder.appendChild(block);
		});
		if (Array.isArray(pendingServiceRows) && pendingServiceRows.length) {
			pendingServiceRows.forEach(function(rowData){
				if (!rowData || !rowData.categoryId) {
					return;
				}
				var label = holder.querySelector('label[data-id="' + parseInt(rowData.categoryId, 10) + '"]');
				if (!label) {
					return;
				}
				addServiceRow(label, rowData);
			});
		}
	}
function addStockRow(categoryLabel, rowData) {
	var catId = parseInt(categoryLabel.getAttribute('data-id') || '0', 10);
	if (!catId) {
		return;
	}
	var serviceList = categoryLabel.querySelector('.service_list');
	if (!serviceList) {
		return;
	}
	var row = document.createElement('p');
	row.className = 'service__item';
	row.setAttribute('data-category-id', String(catId));
	row.innerHTML =
	'<select class="stock-service-select">' + serviceOptionsHtml(catId) + '</select>' +
	'<span class="time"><label>Время:</label><select class="stock-time-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
	'<span class="time2"><label>Перерыв:</label><select class="stock-pause-select">' + durationOptionsHtml() + '</select>&nbsp;мин.</span>' +
	'<span class="stock_price"><label>Акционная стоимость:</label><input type="number" min="0" step="1" class="stock-price-input" value="" /></span>' +
	'<span class="old_price"><label>Цена без скидки:</label><input type="number" min="0" step="1" class="stock-old-price-input" value="" /></span>' +
	'<span class="about_stock"><label>Условия акции:</label><textarea maxlength="150" placeholder="Условия акции" class="stock-about-input"></textarea></span>' +
	'<span class="count_stock"><label>Всего предложений:</label><input type="number" min="0" step="1" class="stock-count-input" value="" /></span>' +
	'<button type="button" class="btn-remove-service">Удалить</button>';
	serviceList.appendChild(row);
	if (rowData) {
		var serviceSelect = row.querySelector('.stock-service-select');
		var timeSelect = row.querySelector('.stock-time-select');
		var pauseSelect = row.querySelector('.stock-pause-select');
		var priceInput = row.querySelector('.stock-price-input');
		var oldPriceInput = row.querySelector('.stock-old-price-input');
		var countInput = row.querySelector('.stock-count-input');
		var aboutInput = row.querySelector('.stock-about-input');
		if (serviceSelect) ensureSelectValue(serviceSelect, String(rowData.serviceRaw || ''), String(rowData.serviceLabel || ''));
		if (serviceSelect) serviceSelect.value = String(rowData.serviceRaw || '');
		if (timeSelect) timeSelect.value = String(parseInt(rowData.duration || '15', 10) || 15);
		if (pauseSelect) pauseSelect.value = String(parseInt(rowData.pause || '15', 10) || 15);
		if (priceInput) priceInput.value = String(parseInt(rowData.price || '0', 10) || 0);
		if (oldPriceInput) oldPriceInput.value = String(parseInt(rowData.oldPrice || '0', 10) || 0);
		if (countInput) countInput.value = String(parseInt(rowData.countStock || '0', 10) || 0);
		if (aboutInput) aboutInput.value = String(rowData.aboutStock || '');
	}
}
	function collectStockRows() {
		var rows = [];
			document.querySelectorAll('#jform_stocks_servis .service__item').forEach(function(row){
			var catId = String(row.getAttribute('data-category-id') || '');
			var serviceSelect = row.querySelector('.stock-service-select');
			var timeSelect = row.querySelector('.stock-time-select');
			var pauseSelect = row.querySelector('.stock-pause-select');
			var priceInput = row.querySelector('.stock-price-input');
			var oldPriceInput = row.querySelector('.stock-old-price-input');
			var countInput = row.querySelector('.stock-count-input');
			var aboutInput = row.querySelector('.stock-about-input');
			rows.push({
				categoryId: parseInt(catId || '0', 10) || 0,
				serviceRaw: serviceSelect ? String(serviceSelect.value || '') : '',
				duration: parseInt(timeSelect ? String(timeSelect.value || '0') : '0', 10) || 0,
				pause: parseInt(pauseSelect ? String(pauseSelect.value || '0') : '0', 10) || 0,
				price: parseInt(priceInput ? String(priceInput.value || '0') : '0', 10) || 0,
				oldPrice: parseInt(oldPriceInput ? String(oldPriceInput.value || '0') : '0', 10) || 0,
				aboutStock: aboutInput ? String(aboutInput.value || '') : '',
				countStock: parseInt(countInput ? String(countInput.value || '0') : '0', 10) || 0
			});
		});
		return rows;
	}
	function renderStockBuilders() {
			var holder = document.getElementById('jform_stocks_servis');
		if (!holder) {
			return;
		}
		var ids = selectedSpecialtyIds();
		var currentRows = collectStockRows();
		if (currentRows.length) {
			pendingStockRows = currentRows;
		}
		holder.innerHTML = '';
		if (!ids.length) {
			holder.textContent = 'Выберите специальность, чтобы добавить акцию';
			return;
		}
		var labelsById = labelsBySpecialtyId();
		ids.forEach(function(catId){
			var block = document.createElement('label');
				block.className = 'checkbox type_master_open type_master_closed';
			block.setAttribute('data-id', String(catId));
			block.innerHTML =
				(labelsById[catId] || ('Категория #' + catId)) +
				'<b></b>' +
					'<div class="flex_wrap"><div class="service_list"></div><div class="stock_key" title="Добавить акцию"></div></div>';
			holder.appendChild(block);
		});
		if (Array.isArray(pendingStockRows) && pendingStockRows.length) {
			pendingStockRows.forEach(function(rowData){
				if (!rowData || !rowData.categoryId) {
					return;
				}
				var label = holder.querySelector('label[data-id="' + parseInt(rowData.categoryId, 10) + '"]');
				if (!label) {
					return;
				}
				addStockRow(label, rowData);
			});
		}
	}
	function renderCourseBuilders() {
		var holder = document.getElementById('jform_courses_servis');
		if (!holder) {
			return;
		}
		var ids = selectedSpecialtyIds();
		var currentRows = collectCourseRows();
		if (currentRows.length) {
			pendingCourseRows = currentRows;
		}
		holder.innerHTML = '';
		if (!ids.length) {
			holder.textContent = 'Выберите специальность, чтобы добавить курс';
			return;
		}
		var labelsById = labelsBySpecialtyId();
		ids.forEach(function(catId){
			var block = document.createElement('label');
			block.className = 'checkbox type_master_open type_master_closed';
			block.setAttribute('data-id', String(catId));
			block.innerHTML =
				(labelsById[catId] || ('Категория #' + catId)) +
				'<b></b>' +
				'<div class="flex_wrap"><div class="service_list"></div><div class="stock_key" title="Добавить курс"></div></div>';
			holder.appendChild(block);
		});
		if (Array.isArray(pendingCourseRows) && pendingCourseRows.length) {
			pendingCourseRows.forEach(function(rowData){
				if (!rowData || !rowData.categoryId) {
					return;
				}
				var label = holder.querySelector('label[data-id="' + parseInt(rowData.categoryId, 10) + '"]');
				if (!label) {
					return;
				}
				addCourseRow(label, rowData);
			});
		}
	}
	function renderSearchBuilders() {
		var holder = document.getElementById('jform_searches_servis');
		if (!holder) {
			return;
		}
		var ids = selectedSpecialtyIds();
		var currentRows = collectSearchRows();
		if (currentRows.length) {
			pendingSearchRows = currentRows;
		}
		holder.innerHTML = '';
		if (!ids.length) {
			holder.textContent = 'Выберите специальность, чтобы добавить поиск';
			return;
		}
		var labelsById = labelsBySpecialtyId();
		ids.forEach(function(catId){
			var block = document.createElement('label');
			block.className = 'checkbox type_master_open type_master_closed';
			block.setAttribute('data-id', String(catId));
			block.innerHTML =
				(labelsById[catId] || ('Категория #' + catId)) +
				'<b></b>' +
				'<div class="flex_wrap"><div class="service_list"></div><div class="search-add-btn" title="Добавить поиск">+</div></div>';
			holder.appendChild(block);
		});
		if (Array.isArray(pendingSearchRows) && pendingSearchRows.length) {
			pendingSearchRows.forEach(function(rowData){
				if (!rowData || !rowData.categoryId) {
					return;
				}
				var label = holder.querySelector('label[data-id="' + parseInt(rowData.categoryId, 10) + '"]');
				if (!label) {
					return;
				}
				addSearchRow(label, rowData);
			});
		}
	}
	filterSpecialtiesByType();
	syncSpecialtyActiveState();
	document.querySelectorAll('#jform_vyberite_spetsialnos input[type="checkbox"]').forEach(function(checkbox){
		checkbox.addEventListener('change', function(){
			syncSpecialtyActiveState();
			renderServiceBuilders();
			renderStockBuilders();
			renderCourseBuilders();
			renderSearchBuilders();
		});
	});
	renderServiceBuilders();
	renderStockBuilders();
	renderCourseBuilders();
	renderSearchBuilders();

	var servicesHolder = document.getElementById('jform_vyberite_usl');
	if (servicesHolder) {
		servicesHolder.addEventListener('click', function(e){
			var addButton = e.target.closest('.btn-add-service');
			if (addButton) {
				e.preventDefault();
				var categoryLabel = addButton.closest('label[data-id]');
				if (categoryLabel) {
					addServiceRow(categoryLabel);
				}
				return;
			}
			var removeButton = e.target.closest('.btn-remove-service');
			if (removeButton) {
				e.preventDefault();
				var row = removeButton.closest('.service__item');
				if (row) {
					row.remove();
				}
			}
			});
		}

	var stocksHolder = document.getElementById('jform_stocks_servis');
if (stocksHolder) {
	stocksHolder.addEventListener('click', function(e){
		var addButton = e.target.closest('.stock_key');
		if (addButton) {
			e.preventDefault();
			var categoryLabel = addButton.closest('label[data-id]');
			if (categoryLabel) {
				addStockRow(categoryLabel);
			}
			return;
		}
		var removeButton = e.target.closest('.btn-remove-service');
		if (removeButton) {
			e.preventDefault();
			var row = removeButton.closest('.service__item');
			if (row) {
				row.remove();
			}
		}
	});
}
	var coursesHolder = document.getElementById('jform_courses_servis');
if (coursesHolder) {
	coursesHolder.addEventListener('click', function(e){
		var addButton = e.target.closest('.stock_key');
		if (addButton) {
			e.preventDefault();
			var categoryLabel = addButton.closest('label[data-id]');
			if (categoryLabel) {
				addCourseRow(categoryLabel);
			}
			return;
		}
		var removeButton = e.target.closest('.btn-remove-service');
		if (removeButton) {
			e.preventDefault();
			var row = removeButton.closest('.service__item');
			if (row) {
				row.remove();
			}
		}
	});
}
	var searchesHolder = document.getElementById('jform_searches_servis');
if (searchesHolder) {
	searchesHolder.addEventListener('click', function(e){
		var addButton = e.target.closest('.search-add-btn');
		if (addButton) {
			e.preventDefault();
			var categoryLabel = addButton.closest('label[data-id]');
			if (categoryLabel) {
				addSearchRow(categoryLabel);
			}
			return;
		}
		var removeButton = e.target.closest('.btn-remove-service');
		if (removeButton) {
			e.preventDefault();
			var row = removeButton.closest('.service__item');
			if (row) {
				row.remove();
			}
		}
	});
}
	var profileForm = document.getElementById('member-profile');
	if (profileForm) {
		profileForm.addEventListener('submit', function(){
			var serviceRows = collectServiceRows();
			var stockRows = collectStockRows();
			var courseRows = collectCourseRows();
			var searchRows = collectSearchRows();
			var legacy = buildLegacyPricesFromRows(serviceRows);
			var stockLegacy = buildLegacyStockPricesFromRows(stockRows);
			var payload = buildViglingPayload(serviceRows);
			var stockPayload = buildViglingStockPayload(stockRows);
			var coursesPayload = buildViglingCoursesPayload(courseRows);
			var searchesPayload = buildViglingSearchesPayload(searchRows);
			var pricesInput = document.getElementById('jform_prices');
			var stockPricesInput = document.getElementById('jform_stock_prices');
			var servicesPayloadInput = document.getElementById('jform_vigling_services_payload');
			var stockPayloadInput = document.getElementById('jform_vigling_stock_services_payload');
			var coursesPayloadInput = document.getElementById('jform_vigling_courses_payload');
			var searchesPayloadInput = document.getElementById('jform_vigling_searches_payload');
			if (pricesInput) pricesInput.value = JSON.stringify(legacy);
			if (stockPricesInput) stockPricesInput.value = JSON.stringify(stockLegacy);
			if (servicesPayloadInput) servicesPayloadInput.value = JSON.stringify(payload);
			if (stockPayloadInput) stockPayloadInput.value = JSON.stringify(stockPayload);
			if (coursesPayloadInput) coursesPayloadInput.value = JSON.stringify(coursesPayload);
			if (searchesPayloadInput) searchesPayloadInput.value = JSON.stringify(searchesPayload);
		});
	}

	// Portfolio delete UX for profile edit.
	var deletedInput = document.getElementById('jform_portfolio_deleted');
	if (deletedInput) {
		var deleted = [];
		document.querySelectorAll('.profile-edit .lk-portfolio-remove').forEach(function(btn){
			btn.addEventListener('click', function(){
				var file = btn.getAttribute('data-file') || '';
				if (!file) return;
				if (deleted.indexOf(file) === -1) deleted.push(file);
				deletedInput.value = deleted.join(',');
				var item = btn.closest('.lk-edit-portfolio-item');
				if (item) item.remove();
			});
		});
	}

	// Avatar change trigger + preview.
	var avatarTrigger = document.getElementById('lk-avatar-trigger');
	var avatarInput = document.getElementById('jform_upload_avatar');
	var avatarPreview = document.getElementById('lk-avatar-preview');
	if (avatarTrigger && avatarInput) {
		avatarTrigger.addEventListener('click', function(){
			avatarInput.click();
		});
		avatarInput.addEventListener('change', function(){
			var file = avatarInput.files && avatarInput.files[0] ? avatarInput.files[0] : null;
			if (!file || !avatarPreview) return;
			var objectUrl = URL.createObjectURL(file);
			avatarPreview.src = objectUrl;
		});
	}
	document.addEventListener('input', function(e) {
		if (e.target.classList.contains('stock-about-input')) {
			e.target.style.height = 'auto';
			e.target.style.height = e.target.scrollHeight + 'px';
		}
	});
})();
</script>
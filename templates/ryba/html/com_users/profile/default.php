<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Access\Access;

$user = Factory::getApplication()->getIdentity();
$isOwn = $user->id == $this->data->id;
$userGroups = $user->id ? $user->getAuthorisedGroups() : [];
$isMaster = in_array(3, $userGroups) || in_array(8, $userGroups);
$profileOwnerId = (int) ($this->data->id ?? 0);
$profileGroups = $profileOwnerId > 0 ? Access::getGroupsByUser($profileOwnerId, false) : [];
$profileIsMaster = in_array(3, $profileGroups) || in_array(8, $profileGroups);
$profileIsAdministrator = in_array(8, $profileGroups, true) || in_array(7, $profileGroups, true) || in_array(6, $profileGroups, true);
$showMasterPublicCard = $isOwn && $profileIsMaster;
$profileMasterType = '';
$roleLabel = 'Клиент';
$avatarUrl = '';
$portfolioField = null;
$specialityText = Text::_('COM_USERS_PROFILE_VALUE_NOT_FOUND');
$pricesRaw = '';
$stockPricesRaw = '';
$avatarRaw = '';
if (!empty($this->data->jcfields)) {
	foreach ($this->data->jcfields as $f) {
		if (!isset($f->name)) continue;
		if ($f->name === 'avatar') {
			$v = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
			$avatarRaw = is_string($v) ? trim($v) : '';
		}
		if ($f->name === 'portfolio_field') {
			$portfolioField = $f;
		}
		if ($f->name === 'vyberite_spetsialnos' && !empty($f->value)) {
			$specialityText = $f->value;
		}
		if ($f->name === 'is_master') {
			$v = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
			$profileMasterType = is_scalar($v) ? trim((string) $v) : '';
		}
		if ($f->name === 'prices' && isset($f->rawvalue)) {
			$pricesRaw = is_string($f->rawvalue) ? $f->rawvalue : '';
		}
		if ($f->name === 'stock_prices' && isset($f->rawvalue)) {
			$stockPricesRaw = is_string($f->rawvalue) ? $f->rawvalue : '';
		}
	}
}
if ($portfolioField === null && !empty($this->data->id)) {
	$fields = \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::getFields('com_users.user', $this->data, true);
	foreach ($fields as $f) {
		if (isset($f->name) && $f->name === 'portfolio_field') {
			$portfolioField = $f;
			break;
		}
	}
}
if (empty($this->data->jcfields) && !empty($this->data->id)) {
	$fields = \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::getFields('com_users.user', $this->data, true);
	foreach ($fields as $f) {
		if (!isset($f->name)) continue;
		if ($f->name === 'is_master' && $profileMasterType === '') {
			$v = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
			$profileMasterType = is_scalar($v) ? trim((string) $v) : '';
		}
		if ($f->name === 'prices' && isset($f->rawvalue)) {
			$pricesRaw = is_string($f->rawvalue) ? $f->rawvalue : '';
		}
		if ($f->name === 'stock_prices' && isset($f->rawvalue)) {
			$stockPricesRaw = is_string($f->rawvalue) ? $f->rawvalue : '';
		}
	}
}

if ($profileIsAdministrator) {
	$roleLabel = 'Администратор';
} elseif ($profileMasterType === '2') {
	$roleLabel = 'Мастер - Заточка/Ремонт';
} elseif ($profileMasterType === '1' || $profileIsMaster) {
	$roleLabel = 'Мастер';
} else {
	$roleLabel = 'Клиент';
}
$profileIsClient = !$profileIsMaster && !$profileIsAdministrator && $profileMasterType !== '1' && $profileMasterType !== '2';
$pricesStructured = [];
$stockPricesStructured = [];
$pricesStructuredWithIds = [];
$stockPricesStructuredWithIds = [];
$coursesStructured = [];
$searchesStructured = [];
$formatProfileServiceDisplayName = static function (string $categoryTitle, string $itemName): string {
	$categoryTitle = trim($categoryTitle);
	$itemName = trim($itemName);
	if ($categoryTitle === '') {
		return $itemName !== '' ? $itemName : 'Услуга';
	}
	if ($itemName === '') {
		return $categoryTitle;
	}
	return $categoryTitle . ' - ' . $itemName;
};

if ($profileOwnerId > 0) {
	$newPricesWithIds = \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::getUserServicesStructuredWithIds($profileOwnerId);
	if ($newPricesWithIds !== []) {
		$pricesStructuredWithIds = $newPricesWithIds;
		$pricesStructured = \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::getUserServicesStructured($profileOwnerId);
	}
}

if ($profileOwnerId > 0) {
	$stockPricesStructured = \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::getUserStockServicesStructured($profileOwnerId);
	$stockPricesStructuredWithIds = \Joomla\Plugin\User\Vigling\Helper\JsnDecodeHelper::getUserStockServicesStructuredWithIds($profileOwnerId);
	$coursesStructured = \Joomla\Plugin\User\Vigling\Service\UserCoursesService::getUserCoursesStructured($profileOwnerId);
	if (class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserSearchesService')) {
		$searchesStructured = \Joomla\Plugin\User\Vigling\Service\UserSearchesService::getUserSearchesStructured($profileOwnerId);
	}
}
if ($avatarRaw === '' && !empty($this->data->id)) {
	$fields = \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::getFields('com_users.user', $this->data, true);
	foreach ($fields as $f) {
		if (isset($f->name) && $f->name === 'avatar') {
			$v = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
			$avatarRaw = is_string($v) ? trim($v) : '';
			break;
		}
	}
}
if ($avatarRaw !== '') {
	$dec = json_decode($avatarRaw, true);
	$avatarUrl = is_string($dec) ? $dec : (is_array($dec) ? (string) reset($dec) : $avatarRaw);
	$avatarUrl = trim((string) $avatarUrl);
	if ($avatarUrl !== '' && strpos($avatarUrl, 'http') !== 0) {
		$avatarUrl = preg_replace('#^/?(images/profiler/?)?#i', '', str_replace('\\', '/', $avatarUrl));
		$avatarUrl = rtrim(Uri::root(), '/') . '/images/profiler/' . $avatarUrl;
	}
}

// Получить услуги из #__content (статьи мастера по категориям из field_id=29)
$masterServicesFromContent = [];
if ($profileIsMaster && !empty($profileOwnerId)) {
	try {
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$prefix = $db->getPrefix();

		// Получить специальности мастера (field_id=29)
		$specQuery = $db->getQuery(true)
			->select('DISTINCT value')
			->from($db->quoteName($prefix . 'fields_values'))
			->where('field_id = 29')
			->where('item_id = ' . $profileOwnerId);
		$db->setQuery($specQuery);
		$specValues = $db->loadColumn();

		if (!empty($specValues)) {
			$specIds = [];
			foreach ($specValues as $v) {
				$ids = array_filter(array_map('intval', explode(',', $v)));
				$specIds = array_merge($specIds, $ids);
			}

			if (!empty($specIds)) {
				// Получить категории специальностей
				$catQuery = $db->getQuery(true)
					->select(['id', 'title'])
					->from($db->quoteName($prefix . 'categories'))
					->where('id IN (' . implode(',', $specIds) . ')')
					->where('published = 1')
					->order('title ASC');
				$db->setQuery($catQuery);
				$categories = $db->loadAssocList();

				foreach ($categories as $cat) {
					$catId = (int)$cat['id'];
					// Получить услуги в этой категории и её дочерних
					$svcQuery = $db->getQuery(true)
						->select(['c.id', 'c.title', 'c.catid', 'c.state', 'cat.title as subcategory_title'])
						->from($db->quoteName($prefix . 'content', 'c'))
						->innerJoin($db->quoteName($prefix . 'categories', 'cat') . ' ON c.catid = cat.id')
						->where('cat.parent_id = ' . $catId)
						->where('c.created_by = ' . $profileOwnerId)
						->where('c.state IN (0, 1)')
						->order('c.title ASC');
					$db->setQuery($svcQuery);
					$services = $db->loadAssocList();

					if (!empty($services)) {
						// Загрузить доп. поля для каждой услуги
						foreach ($services as &$svc) {
							$fieldsQuery = $db->getQuery(true)
								->select(['field_id', 'value'])
								->from($db->quoteName($prefix . 'fields_values'))
								->where('item_id = ' . (int)$svc['id'])
								->where('field_id IN (56, 63, 64, 66, 67, 68, 69, 70)');
							$db->setQuery($fieldsQuery);
							$fields = $db->loadAssocList('field_id');
							$svc['fields'] = $fields;
						}

						$masterServicesFromContent[] = [
							'category_id' => $catId,
							'category_title' => $cat['title'],
							'services' => $services
						];
					}
				}
			}
		}
	} catch (Exception $e) {
		// Игнорировать ошибки БД
	}
}

$masterServicesList = [];
if (!$isOwn && $pricesStructuredWithIds !== []) {
	foreach ($pricesStructuredWithIds as $category) {
		if (!isset($category['items']) || !is_array($category['items'])) {
			continue;
		}
		foreach ($category['items'] as $item) {
			$svcId = isset($item['svc_id']) ? (string) $item['svc_id'] : '';
			$name = isset($item['name']) ? trim((string) $item['name']) : '';
			if ($svcId === '') {
				continue;
			}
			if ($name === '') {
				$name = 'Услуга #' . $svcId;
			}
			$masterServicesList[$svcId] = $name;
		}
	}
}
$displayName = isset($this->data->name) && (string) $this->data->name !== '' ? $this->data->name : $this->data->username;
$pushnotifyBase = Route::_('index.php?option=com_pushnotify');
$pushnotifySwUrl = rtrim(Uri::root(), '/') . '/firebase-messaging-sw.js';
$pushnotifyTokenName = Session::getFormToken();
$pushnotifyTokenValue = '1';
$pushnotifyRoot = rtrim(Uri::root(), '/');
$emailVerificationStatus = '';
$emailVerificationGraceUntil = '';
$emailVerificationGraceLabel = '3 дня';

$emailVerificationServicePath = JPATH_SITE . '/plugins/system/emailverification/src/Service/EmailVerificationService.php';
if (is_file($emailVerificationServicePath)) {
	require_once $emailVerificationServicePath;
	$emailVerificationServiceClass = '\\Joomla\\Plugin\\System\\Emailverification\\Service\\EmailVerificationService';
	if (class_exists($emailVerificationServiceClass)) {
		$emailVerificationGraceLabel = (string) $emailVerificationServiceClass::getGracePeriodHumanLabel();
	}
}

if ($isOwn && $profileOwnerId > 0) {
	try {
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$tableName = $db->replacePrefix('#__vigling_email_verifications');
		$db->setQuery('SHOW TABLES LIKE ' . $db->quote($tableName));
		if ($db->loadResult()) {
			$query = $db->getQuery(true)
				->select([$db->quoteName('status'), $db->quoteName('grace_until')])
				->from($db->quoteName('#__vigling_email_verifications'))
				->where($db->quoteName('user_id') . ' = ' . (int) $profileOwnerId)
				->setLimit(1);
			$db->setQuery($query);
			$row = $db->loadAssoc() ?: [];
			$emailVerificationStatus = isset($row['status']) ? (string) $row['status'] : '';
			$emailVerificationGraceUntil = isset($row['grace_until']) ? (string) $row['grace_until'] : '';
		}
	} catch (\Throwable $e) {
		$emailVerificationStatus = '';
		$emailVerificationGraceUntil = '';
	}
}
$defaultImg = Uri::root() . 'templates/ryba/images/master.png';
if (!is_file(JPATH_ROOT . '/templates/ryba/images/master.png')) {
	$defaultImg = Uri::root() . 'components/com_jsn/assets/img/default.jpg';
}

$decodeScalar = static function (string $raw): string {
	$raw = trim($raw);
	if ($raw === '') {
		return '';
	}
	$decoded = json_decode($raw, true);
	if (is_string($decoded)) {
		return trim($decoded);
	}
	return $raw;
};

$resolvePortfolioImage = static function (string $rawValue): string {
	$rawValue = trim($rawValue);
	if ($rawValue === '' || strtolower($rawValue) === 'true') {
		return '';
	}
	$decoded = json_decode($rawValue, true);
	if (is_string($decoded)) {
		$rawValue = trim($decoded);
	}
	if ($rawValue === '') {
		return '';
	}
	if (strpos($rawValue, 'http://') === 0 || strpos($rawValue, 'https://') === 0) {
		return $rawValue;
	}
	$clean = str_replace('\\', '/', ltrim($rawValue, '/'));
	if (strpos($clean, 'images/') === 0) {
		return rtrim(Uri::root(), '/') . '/' . $clean;
	}
	if (strpos($clean, 'portfolio/') === 0) {
		return rtrim(Uri::root(), '/') . '/images/' . $clean;
	}
	if (preg_match('/^portfolio_field/i', $clean)) {
		return rtrim(Uri::root(), '/') . '/images/portfolio/' . $clean;
	}
	return rtrim(Uri::root(), '/') . '/images/portfolio/' . $clean;
};

$portfolioImages = [];
if ($portfolioField && isset($portfolioField->rawvalue) && is_scalar($portfolioField->rawvalue)) {
	$rawPortfolio = (string) $portfolioField->rawvalue;
	$decodedPortfolio = json_decode($rawPortfolio, true);
	$candidates = [];
	if (is_array($decodedPortfolio)) {
		$iter = new RecursiveIteratorIterator(new RecursiveArrayIterator($decodedPortfolio));
		foreach ($iter as $item) {
			if (is_scalar($item)) {
				$candidates[] = (string) $item;
			}
		}
	} else {
		$candidates[] = $rawPortfolio;
	}
	foreach ($candidates as $candidate) {
		$url = $resolvePortfolioImage($candidate);
		if ($url !== '') {
			$portfolioImages[] = $url;
		}
	}
}
$portfolioImages = array_values(array_unique($portfolioImages));

$specialtyRaw = '';
if (!empty($this->data->jcfields)) {
	foreach ($this->data->jcfields as $f) {
		if (!isset($f->name) || $f->name !== 'vyberite_spetsialnos') {
			continue;
		}
		$raw = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
		if (is_scalar($raw)) {
			$specialtyRaw = trim((string) $raw);
		}
		break;
	}
}
if ($specialtyRaw === '') {
	$fieldsFallback = \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::getFields('com_users.user', $this->data, true);
	foreach ($fieldsFallback as $f) {
		if (!isset($f->name) || $f->name !== 'vyberite_spetsialnos') {
			continue;
		}
		$raw = isset($f->rawvalue) ? $f->rawvalue : (isset($f->value) ? $f->value : '');
		if (is_scalar($raw)) {
			$specialtyRaw = trim((string) $raw);
		}
		break;
	}
}
$selectedSpecialtyIds = [];
if ($specialtyRaw !== '') {
	$decodedSpecialty = json_decode($specialtyRaw, true);
	if (is_array($decodedSpecialty)) {
		foreach ($decodedSpecialty as $item) {
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

$specialityList = [];
if ($selectedSpecialtyIds !== []) {
	try {
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title']))
			->from($db->quoteName('#__categories'))
			->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
			->where($db->quoteName('published') . ' = 1')
			->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $selectedSpecialtyIds)) . ')');
		$db->setQuery($query);
		$rows = $db->loadAssocList() ?: [];
		$titleById = [];
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			$title = trim((string) ($row['title'] ?? ''));
			if ($id > 0 && $title !== '') {
				$titleById[$id] = $title;
			}
		}
		foreach ($selectedSpecialtyIds as $id) {
			if (isset($titleById[$id])) {
				$specialityList[] = $titleById[$id];
			}
		}
	} catch (\Throwable $e) {
		$specialityList = [];
	}
}
if ($specialityList === [] && $specialityText !== '' && $specialityText !== Text::_('COM_USERS_PROFILE_VALUE_NOT_FOUND')) {
	$specialityList = array_values(array_filter(array_map('trim', explode(',', $specialityText))));
}

// Public card for foreign profile: separate from private LK view.
if (!$isOwn && (int) ($this->data->id ?? 0) > 0) {
	echo $this->loadTemplate('public');
	return;
}
?>
<style>
.lk-notify-btn { position: relative; }
.lk-notify-badge { position: absolute; top: -4px; right: -4px; min-width: 16px; height: 16px; line-height: 16px; padding: 0 4px; font-size: 11px; text-align: center; background: #c00; color: #fff; border-radius: 8px; }
.lk-notify-item-read { color: #777; }
</style>
<div id="easyprofile" class="view_profile">
	<div class="jsn-p">
		<div class="view_profile-header">
			<div class="jsn-p-top jsn-p-top-a">
				<div class="jsn-p-avatar">
					<?php if ($avatarUrl) : ?>
						<img src="<?php echo $this->escape($avatarUrl); ?>" alt="<?php echo $this->escape($displayName); ?>" class="avatar">
					<?php else : ?>
						<div class="avatar avatar-placeholder"><?php echo $this->escape(mb_substr($displayName, 0, 1)); ?></div>
					<?php endif; ?>
					<span class="avatar-online" title="OnLine" aria-hidden="true"></span>
				</div>
				<div class="jsn-p-title">
					<h3><?php echo $this->escape($displayName); ?></h3>
					<span class="jsn-p-role"><?php echo $this->escape($roleLabel); ?></span>
				</div>
				<div class="jsn-p-before-fields"></div>
			</div>
			<div class="jsn-p-opt">
				<?php if ($isOwn) : ?>
					<?php
					$ordersComp = ComponentHelper::getComponent('com_orders');
					$ordersItem = $ordersComp->id ? Factory::getApplication()->getMenu()->getItems(['component_id'], [$ordersComp->id], true) : null;
					$ordersUrl = $ordersItem ? Route::_('index.php?Itemid=' . (int) $ordersItem->id) : Route::_('index.php?option=com_orders&view=orders');
					$clientsUrl = $ordersItem ? Route::_('index.php?option=com_orders&view=orders&layout=clients&Itemid=' . (int) $ordersItem->id) : Route::_('index.php?option=com_orders&view=orders&layout=clients');
					?>
					<div class="lk-notify-wrap" style="display:inline-block; position:relative; vertical-align:middle;">
						<button type="button" class="btn btn-xs btn-default lk-notify-btn" id="lk-notify-toggle" aria-expanded="false" aria-haspopup="true" title="Уведомления">
							<i class="fa fa-bell" aria-hidden="true"></i>
							<span class="lk-notify-label">Уведомления</span>
							<span class="lk-notify-badge" id="lk-notify-badge" style="display:none;">0</span>
						</button>
						<div class="lk-notify-dropdown" id="lk-notify-dropdown" style="display:none; position:absolute; top:100%; right:0; margin-top:4px; min-width:320px; max-width:400px; max-height:70vh; overflow:auto; background:#fff; border:1px solid #ddd; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:1000;">
							<div class="lk-notify-header" style="padding:8px 12px; border-bottom:1px solid #eee; font-weight:bold;">Уведомления</div>
							<div class="lk-notify-list" id="lk-notify-list"></div>
							<div class="lk-notify-empty" id="lk-notify-empty" style="display:none; padding:16px; color:#888;">Нет уведомлений</div>
							<div class="lk-notify-loading" id="lk-notify-loading" style="display:none; padding:16px; text-align:center;">Загрузка…</div>
						</div>
					</div>
					<a class="btn btn-xs btn-default" href="<?php echo Route::_('index.php?option=com_users&task=profile.edit&user_id=' . (int) $this->data->id); ?>">
						<i class="jsn-icon jsn-icon-cog"></i> Настройки профиля</a>
					<a class="btn btn-xs btn-default" href="<?php echo $ordersUrl; ?>">
						<i class="jsn-icon jsn-icon-cog"></i> Мои записи к мастерам</a>
					<?php if ($isMaster) : ?>
					<a class="btn btn-xs btn-default" href="<?php echo Route::_('index.php?option=com_orders&view=orders&layout=journal' . ($ordersItem ? '&Itemid=' . (int) $ordersItem->id : '')); ?>">
						<i class="jsn-icon jsn-icon-calendar"></i> Журнал</a>
					<a class="btn btn-xs btn-default" href="<?php echo $clientsUrl; ?>">
						<i class="jsn-icon jsn-icon-user"></i> Записи ко мне</a>
					<?php endif; ?>
				<?php else : ?>
					<button type="button" class="btn btn-xs btn-primary" id="lk-booking-toggle" aria-expanded="false">
						Записаться
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php if (!$isOwn && (int) $this->data->id > 0) : ?>
		<div id="lk-booking-block" class="view_profile-booking" style="display:none;">
			<form id="lk-booking-form" class="form-horizontal">
				<div class="form-group">
					<label class="control-label">Дата и время начала записи</label>
					<div class="controls lk-booking-datetime">
						<input type="date" name="booking_date" id="lk-booking-date" required class="form-control">
						<input type="time" name="booking_time" id="lk-booking-time" required class="form-control lk-booking-time-start">
					</div>
				</div>
				<?php if (!empty($masterServicesList)) : ?>
				<div class="form-group">
					<label class="control-label">Услуга</label>
					<div class="controls">
						<select name="service_id" id="lk-booking-service" class="form-control" required>
							<option value="">— выбрать —</option>
							<?php foreach ($masterServicesList as $sid => $title) : ?>
								<option value="<?php echo $this->escape($sid); ?>" data-name="<?php echo $this->escape($title); ?>"><?php echo $this->escape($title); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<?php else : ?>
				<div class="form-group">
					<label class="control-label">Услуга</label>
					<div class="controls">
						<input type="text" name="service_name" id="lk-booking-service-name" class="form-control" placeholder="Название услуги" required>
					</div>
				</div>
				<?php endif; ?>
				<div class="form-group">
					<label class="control-label">Комментарий</label>
					<div class="controls">
						<textarea name="note" id="lk-booking-note" class="form-control" rows="2" placeholder="Необязательно"></textarea>
					</div>
				</div>
				<div class="form-group">
					<div class="controls">
						<button type="submit" class="btn btn-primary" id="lk-booking-submit">Отправить запись</button>
						<span id="lk-booking-msg" class="lk-booking-msg"></span>
					</div>
				</div>
				<input type="hidden" name="master_id" value="<?php echo (int) $this->data->id; ?>">
				<input type="hidden" name="duration_min" value="60">
				<input type="hidden" name="time" id="lk-booking-time-combined" value="">
				<input type="hidden" name="service_name" id="lk-booking-service-name-hidden" value="">
				<input type="hidden" name="<?php echo $this->escape($pushnotifyTokenName); ?>" value="<?php echo $this->escape($pushnotifyTokenName); ?>">
			</form>
		</div>
		<?php endif; ?>
		<form class="jsn-p-fields">
			<div id="jsn-form" class="hover clean mini flat z-icons-light z-shadows z-spaced z-tabs horizontal top-compact top view_profile-tabs">
				<ul class="z-tabs-nav z-tabs-mobile" style="display: none;"><li><a class="z-link" style="text-align: left;"><span class="z-title">Профиль</span><span class="z-arrow"></span></a></li></ul>
				<i class="z-dropdown-arrow"></i>
				<ul id="jsn-profile-tabs" class="z-tabs-nav z-tabs-desktop">
					<?php if ($profileIsClient) : ?>
					<li data-index="0" data-link="profile-tab0" class="z-tab z-first z-active" style="width: 25%;"><a class="z-link" style="min-height: 18px;">Профиль<span></span></a></li>
					<li data-index="5" data-link="profile-tab5" class="z-tab" style="width: 25%;"><a class="z-link" style="min-height: 18px;">Уведомления<span></span></a></li>
					<li data-index="6" data-link="profile-tab6" class="z-tab" style="width: 25%;"><a class="z-link" style="min-height: 18px;">Email и пароль<span></span></a></li>
					<li data-index="7" data-link="profile-tab7" class="z-tab z-last" style="width: 25%;"><a class="z-link" style="min-height: 18px;">Активировать аккаунт<span></span></a></li>
					<?php else : ?>
					<li data-index="0" data-link="profile-tab0" class="z-tab z-first z-active" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Профиль<span></span></a></li>
					<li data-index="1" data-link="profile-tab1" class="z-tab" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Портфолио<span></span></a></li>
					<li data-index="2" data-link="profile-tab2" class="z-tab" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Специальность<span></span></a></li>
					<li data-index="3" data-link="profile-tab3" class="z-tab" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Услуги и цены<span></span></a></li>
					<li data-index="4" data-link="profile-tab4" class="z-tab" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Акции<span></span></a></li>
					<li data-index="5" data-link="profile-tab5" class="z-tab" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Курсы<span></span></a></li>
					<li data-index="6" data-link="profile-tab6" class="z-tab" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Поиск моделей<span></span></a></li>
					<li data-index="7" data-link="profile-tab7" class="z-tab" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Уведомления<span></span></a></li>
					<li data-index="8" data-link="profile-tab8" class="z-tab" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Email и пароль<span></span></a></li>
					<li data-index="9" data-link="profile-tab9" class="z-tab z-last" style="width: 10%;"><a class="z-link" style="min-height: 18px;">Активировать аккаунт<span></span></a></li>
					<?php endif; ?>
				</ul>
				<div class="z-container">
					<div class="z-content z-active" data-index="0" data-name="profile-tab0">
						<div class="z-content-inner">
							<fieldset id="jsn_default" class="jsn-form-fieldset" data-index="0" data-name="profile-tab0">
								<legend style="display: none;">Профиль</legend>
								<?php echo $this->loadTemplate('profile_main'); ?>
								<?php echo $this->loadTemplate('core'); ?>
								<?php echo $this->loadTemplate('params'); ?>
								<?php echo $this->loadTemplate('custom'); ?>
								<!-- Редактирование услуг/акций доступно только через "Настройки профиля". -->
							</fieldset>
						</div>
					</div>
					<?php if (!$profileIsClient) : ?>
					<div class="z-content" data-index="1" data-name="profile-tab1" style="display: none;">
						<div class="z-content-inner">
							<fieldset id="jsn_portfolio" class="jsn-form-fieldset" data-index="1" data-name="profile-tab1">
								<legend style="display: none;">Портфолио</legend>
								<div class="portfolio_fieldValue">
									<?php if (!empty($portfolioImages)) : ?>
									<div class="lk-portfolio-grid">
										<?php foreach ($portfolioImages as $img) : ?>
										<div class="lk-portfolio-item"><img src="<?php echo $this->escape($img); ?>" alt="Портфолио"></div>
										<?php endforeach; ?>
									</div>
									<?php else : ?>
									<fieldset class="readonly">Портфолио не заполнено</fieldset>
									<?php endif; ?>
								</div>
							</fieldset>
						</div>
					</div>
					<div class="z-content" data-index="2" data-name="profile-tab2" style="display: none;">
						<div class="z-content-inner">
							<fieldset id="jsn_spetsialnost" class="jsn-form-fieldset" data-index="2" data-name="profile-tab2">
								<legend style="display: none;">Специальность</legend>
								<div class="vyberite_spetsialnosValue">
									<?php if (!empty($specialityList)) : ?>
									<fieldset id="jform_vyberite_spetsialnos" class="checkboxes readonly">
										<?php foreach ($specialityList as $specialityName) : ?>
										<label class="checkbox active">
											<input type="checkbox" checked disabled>
											<?php echo $this->escape($specialityName); ?>
										</label>
										<?php endforeach; ?>
									</fieldset>
									<?php else : ?>
									<fieldset class="readonly">Специальности не выбраны</fieldset>
									<?php endif; ?>
								</div>
							</fieldset>
						</div>
					</div>
					<div class="z-content" data-index="3" data-name="profile-tab3" style="display: none;">
						<div class="z-content-inner">
							<fieldset id="jsn_addinfo" class="jsn-form-fieldset" data-index="3" data-name="profile-tab3">
								<legend style="display: none;">Услуги и цены</legend>
								<div class="pricesValue">
									<?php if (!empty($pricesStructuredWithIds)) : ?>
										<?php foreach ($pricesStructuredWithIds as $cat) : ?>
										<label class="checkbox type_master_open">
											<?php echo $this->escape((string) ($cat['title'] ?? 'Категория')); ?><b></b>
											<div class="flex_wrap">
												<div class="service__wrap">
													<?php foreach ((array) ($cat['items'] ?? []) as $item) : ?>
													<p class="service__item service__item--readonly">
														<span class="service-name"><?php echo $this->escape($formatProfileServiceDisplayName((string) ($cat['title'] ?? ''), (string) ($item['name'] ?? ''))); ?></span>
														<span class="time"><label>Время:</label><?php echo (int) ($item['duration'] ?? 0); ?>&nbsp;мин.</span>
														<span class="time2"><label>Перерыв:</label><?php echo (int) ($item['pause_min'] ?? 0); ?>&nbsp;мин.</span>
														<span class="price"><label>Стоимость:</label><?php echo (int) ($item['price'] ?? 0); ?>&nbsp;RUB</span>
													</p>
													<?php endforeach; ?>
												</div>
											</div>
										</label>
										<?php endforeach; ?>
									<?php else : ?>
										<fieldset id="jform_vyberite_usl" class="readonly">Услуги и цены не заполнены</fieldset>
									<?php endif; ?>
								</div>
							</fieldset>
						</div>
					</div>
						<div class="z-content" data-index="4" data-name="profile-tab4" style="display: none;">
							<div class="z-content-inner">
							<fieldset id="jsn_stocks" class="jsn-form-fieldset" data-index="4" data-name="profile-tab4">
								<legend style="display: none;">Акции</legend>
								<div class="stock_pricesValue">
									<?php if (!empty($stockPricesStructuredWithIds)) : ?>
										<?php foreach ($stockPricesStructuredWithIds as $cat) : ?>
										<label class="checkbox type_master_open">
											<?php echo $this->escape((string) ($cat['title'] ?? 'Категория')); ?><b></b>
											<div class="flex_wrap">
												<div class="service__wrap">
													<?php foreach ((array) ($cat['items'] ?? []) as $item) : ?>
													<p class="service__item service__item--readonly">
														<span class="service-name"><?php echo $this->escape($formatProfileServiceDisplayName((string) ($cat['title'] ?? ''), (string) ($item['name'] ?? ''))); ?></span>
														<span class="time"><label>Время:</label><?php echo (int) ($item['duration'] ?? 0); ?>&nbsp;мин.</span>
														<span class="time2"><label>Перерыв:</label><?php echo (int) ($item['pause_min'] ?? 0); ?>&nbsp;мин.</span>
														<span class="price"><label>Стоимость:</label><?php echo (int) ($item['price'] ?? 0); ?>&nbsp;RUB</span>
													</p>
													<?php endforeach; ?>
												</div>
											</div>
										</label>
										<?php endforeach; ?>
									<?php else : ?>
										<fieldset id="jform_stocks_servis" class="readonly">Акции не заполнены</fieldset>
									<?php endif; ?>
								</div>
								</fieldset>
							</div>
						</div>
						<div class="z-content" data-index="5" data-name="profile-tab5" style="display: none;">
							<div class="z-content-inner">
							<fieldset id="jsn_courses" class="jsn-form-fieldset" data-index="5" data-name="profile-tab5">
								<legend style="display: none;">Курсы</legend>
								<div class="coursesValue">
									<?php if (!empty($coursesStructured)) : ?>
										<?php foreach ((array) $coursesStructured as $course) : ?>
										<?php
											$courseTitle = trim((string) ($course['title'] ?? $course['description'] ?? ''));
											$courseDescription = trim((string) ($course['description'] ?? ''));
											$courseCategoryTitle = trim((string) ($course['category_title'] ?? ''));
											$coursePrice = (int) ($course['price'] ?? 0);
											$courseDurationMin = (int) ($course['duration_min'] ?? 0);
											$courseCapacity = (int) ($course['capacity'] ?? 1);
											$courseBookingMode = trim((string) ($course['booking_mode'] ?? 'free'));
											$courseSlotUtc = trim((string) ($course['slot_start_utc'] ?? ''));
											$courseSlotDisplay = '';
											$courseSlotIso = '';
											if ($courseSlotUtc !== '') {
												try {
													$courseSlotDate = new \DateTimeImmutable($courseSlotUtc, new \DateTimeZone('UTC'));
													$courseSlotIso = $courseSlotDate->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
													$courseSlotDisplay = $courseSlotDate->format('d.m.Y H:i');
												} catch (\Throwable $e) {
													$courseSlotDisplay = $courseSlotUtc;
												}
											}
										?>
										<label class="checkbox type_master_open">
											<?php echo $this->escape($courseTitle !== '' ? $courseTitle : 'Курс'); ?><b></b>
											<div class="flex_wrap">
												<div class="service__wrap">
													<p class="service__item service__item--readonly course__item--readonly">
														<?php if ($courseCategoryTitle !== '') : ?>
														<span class="service-name">Категория: <?php echo $this->escape($courseCategoryTitle); ?></span>
														<?php endif; ?>
														<?php if ($courseDescription !== '') : ?>
														<span class="course-description"><label>Описание:</label><?php echo $this->escape($courseDescription); ?></span>
														<?php endif; ?>
														<span class="price"><label>Стоимость:</label><?php echo $coursePrice; ?>&nbsp;RUB</span>
														<span class="time"><label>Время:</label><?php echo $courseDurationMin; ?>&nbsp;мин.</span>
														<span class="time2"><label>Лимит мест:</label><?php echo max(1, $courseCapacity); ?></span>
														<span class="course-mode"><label>Режим:</label><?php echo $this->escape($courseBookingMode === 'fixed' ? 'Фиксированная дата' : 'Любое время'); ?></span>
														<?php if ($courseSlotDisplay !== '') : ?>
														<span class="course-slot"><label>Дата и время:</label><span class="lk-time-utc" data-time-utc="<?php echo $this->escape($courseSlotIso); ?>"><?php echo $this->escape($courseSlotDisplay); ?></span></span>
														<?php endif; ?>
													</p>
												</div>
											</div>
										</label>
										<?php endforeach; ?>
									<?php else : ?>
										<fieldset id="jform_courses_servis" class="readonly">Курсы не заполнены</fieldset>
									<?php endif; ?>
								</div>
								</fieldset>
							</div>
						</div>
						<div class="z-content" data-index="6" data-name="profile-tab6" style="display: none;">
							<div class="z-content-inner">
							<fieldset id="jsn_searches" class="jsn-form-fieldset" data-index="6" data-name="profile-tab6">
								<legend style="display: none;">Поиск моделей</legend>
								<div class="searchesValue">
									<?php if (!empty($searchesStructured)) : ?>
										<?php foreach ((array) $searchesStructured as $search) : ?>
										<?php
											$searchTitle = trim((string) ($search['title'] ?? $search['description'] ?? ''));
											$searchDescription = trim((string) ($search['description'] ?? ''));
											$searchCategoryTitle = trim((string) ($search['category_title'] ?? ''));
											$searchPrice = (int) ($search['price'] ?? 0);
											$searchDurationMin = (int) ($search['duration_min'] ?? 0);
											$searchCapacity = (int) ($search['capacity'] ?? 1);
											$searchBookingMode = trim((string) ($search['booking_mode'] ?? 'free'));
											$searchSlotUtc = trim((string) ($search['slot_start_utc'] ?? ''));
											$searchSlotDisplay = '';
											$searchSlotIso = '';
											if ($searchSlotUtc !== '') {
												try {
													$searchSlotDate = new \DateTimeImmutable($searchSlotUtc, new \DateTimeZone('UTC'));
													$searchSlotIso = $searchSlotDate->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
													$searchSlotDisplay = $searchSlotDate->format('d.m.Y H:i');
												} catch (\Throwable $e) {
													$searchSlotDisplay = $searchSlotUtc;
												}
											}
										?>
										<label class="checkbox type_master_open">
											<?php echo $this->escape($searchTitle !== '' ? $searchTitle : 'Поиск моделей'); ?><b></b>
											<div class="flex_wrap">
												<div class="service__wrap">
													<p class="service__item service__item--readonly course__item--readonly">
														<?php if ($searchCategoryTitle !== '') : ?>
														<span class="service-name">Категория: <?php echo $this->escape($searchCategoryTitle); ?></span>
														<?php endif; ?>
														<?php if ($searchDescription !== '') : ?>
														<span class="course-description"><label>Описание:</label><?php echo $this->escape($searchDescription); ?></span>
														<?php endif; ?>
														<span class="price"><label>Стоимость:</label><?php echo $searchPrice; ?>&nbsp;RUB</span>
														<span class="time"><label>Время:</label><?php echo $searchDurationMin; ?>&nbsp;мин.</span>
														<span class="time2"><label>Лимит мест:</label><?php echo max(1, $searchCapacity); ?></span>
														<span class="course-mode"><label>Режим:</label><?php echo $this->escape($searchBookingMode === 'fixed' ? 'Фиксированная дата' : 'Любое время'); ?></span>
														<?php if ($searchSlotDisplay !== '') : ?>
														<span class="course-slot"><label>Дата и время:</label><span class="lk-time-utc" data-time-utc="<?php echo $this->escape($searchSlotIso); ?>"><?php echo $this->escape($searchSlotDisplay); ?></span></span>
														<?php endif; ?>
													</p>
												</div>
											</div>
										</label>
										<?php endforeach; ?>
									<?php else : ?>
										<fieldset id="jform_searches_servis" class="readonly">Поиск моделей не заполнен</fieldset>
									<?php endif; ?>
								</div>
								</fieldset>
							</div>
						</div>
						<?php endif; ?>
						<div class="z-content" data-index="7" data-name="profile-tab7" style="display: none;">
							<div class="z-content-inner">
							<fieldset id="jsn_notify" class="jsn-form-fieldset" data-index="7" data-name="profile-tab7">
								<legend style="display: none;">Уведомления</legend>
								<dl class="dl-horizontal">
									<dt class="no-title">Push-уведомления</dt>
									<dd>
										<?php if ($isOwn) : ?>
										<div class="push-notify-block">
											<div class="push-notify-row push-notify-row-status">
												<label class="push-notify-switch" title="Включить/выключить уведомления">
													<input type="checkbox" id="pushnotify-toggle-input">
													<span class="push-notify-slider"></span>
												</label>
												<span id="pushnotify-status">—</span>
											</div>
										</div>
										<?php else : ?>
										<span>—</span>
										<?php endif; ?>
									</dd>
								</dl>
							</fieldset>
						</div>
					</div>
					<?php if (!$profileIsClient) : ?>
					<div class="z-content" data-index="8" data-name="profile-tab8" style="display: none;">
						<div class="z-content-inner">
								<fieldset id="jsn_login" class="jsn-form-fieldset" data-index="8" data-name="profile-tab8">
									<legend style="display: none;">Email и пароль</legend>
									<dl class="dl-horizontal">
										<dt class="usernameLabel">Email</dt>
										<dd class="usernameValue"><?php echo $this->escape(isset($this->data->email) ? $this->data->email : ''); ?></dd>
									</dl>
								</fieldset>
						</div>
					</div>
					<?php else : ?>
					<div class="z-content" data-index="7" data-name="profile-tab7" style="display: none;">
						<div class="z-content-inner">
								<fieldset id="jsn_login" class="jsn-form-fieldset" data-index="7" data-name="profile-tab7">
									<legend style="display: none;">Email и пароль</legend>
									<dl class="dl-horizontal">
										<dt class="usernameLabel">Email</dt>
										<dd class="usernameValue"><?php echo $this->escape(isset($this->data->email) ? $this->data->email : ''); ?></dd>
									</dl>
								</fieldset>
						</div>
					</div>
					<?php endif; ?>
					<div class="z-content" data-index="<?php echo !$profileIsClient ? '9' : '8'; ?>" data-name="profile-tab<?php echo !$profileIsClient ? '9' : '8'; ?>" style="display: none;">
						<div class="z-content-inner">
							<fieldset id="jsn_activate" class="jsn-form-fieldset" data-index="<?php echo !$profileIsClient ? '9' : '8'; ?>" data-name="profile-tab<?php echo !$profileIsClient ? '9' : '8'; ?>">
								<legend style="display: none;">Активировать аккаунт</legend>
								<dl class="dl-horizontal">
									<dt>Статус</dt>
									<dd>
										<?php if ($emailVerificationStatus === 'verified') : ?>
											Аккаунт подтвержден ✓
										<?php elseif ($emailVerificationStatus === 'pending') : ?>
											Аккаунт не активирован. У вас есть <?php echo $this->escape($emailVerificationGraceLabel); ?> на активацию. Проверьте почту и папку «Спам».
									<?php elseif ($emailVerificationStatus === 'blocked') : ?>
											Аккаунт временно заблокирован, так как вы его не активировали. Проверьте почту и папку «Спам».
										<?php else : ?>
											Для этого аккаунта подтверждение email не требуется.
										<?php endif; ?>
									</dd>
									<?php if ($emailVerificationGraceUntil !== '') : ?>
									<dt>Активировать до</dt>
									<dd><?php echo $this->escape($emailVerificationGraceUntil); ?> (UTC)</dd>
									<?php endif; ?>
								</dl>
								<?php if ($emailVerificationStatus === 'pending' || $emailVerificationStatus === 'blocked') : ?>
								<div class="lk-email-activation-actions">
									<input type="hidden" id="lk-email-resend-token-name" value="<?php echo $this->escape($pushnotifyTokenName); ?>">
									<input type="hidden" id="lk-email-resend-token-value" value="<?php echo $this->escape($pushnotifyTokenValue); ?>">
									<input type="hidden" id="lk-email-resend-email" value="<?php echo $this->escape((string) ($this->data->email ?? '')); ?>">
									<button type="button" id="lk-email-resend-btn" class="btn btn-xs btn-primary">Отправить письмо подтверждения еще раз</button>
									<span id="lk-email-resend-msg" style="margin-left:10px;"></span>
								</div>
								<?php endif; ?>
							</fieldset>
						</div>
					</div>
				</div>
			</div>
		</form>
		<?php if ($showMasterPublicCard) : ?>
			<?php
			$this->lkEmbed = true;
			echo $this->loadTemplate('public');
			?>
		<?php endif; ?>
		<?php if (!$showMasterPublicCard && $profileIsMaster) : ?>
		<div class="jsn-p-bottom">
			<div class="jsn-p-after-fields"></div>
		</div>
		<div style="display:none;" id="del-images">
			<div>
				<h3>Вы действительно желаете удалить</h3>
				<div class="del-img">
					<img src="/" alt="">
				</div>
				<div class="del-but d-flex jsn-p">
					<input type="button" class="btn btn-xs btn-default goDel" value="Да" data-url="">
					<input type="button" class="btn btn-xs btn-default" value="Нет" onclick="jQuery.fancybox.close();">
				</div>
			</div>
		</div>
	</div>
	<div class="masters__big-img-cont col-md-6">
		<div class="arrows_master-slider">
			<button type="button" class="my-slick-prev slick-arrow"><i class="fa fa-angle-left" aria-hidden="true"></i></button>
			<button type="button" class="my-slick-next slick-arrow"><i class="fa fa-angle-right" aria-hidden="true"></i></button>
		</div>
		<div class="masters__big-img">
			<div style="background-image: url('<?php echo $avatarUrl ? $this->escape($avatarUrl) : $defaultImg; ?>'); width: 100%; display: inline-block;" class="masters__big-img-item"></div>
		</div>
	</div>
	<div class="masters__big-info col-md-6">
		<div class="masters__big-info-head">
			<div class="masters__big-info-head-master" style="background-image: url('<?php echo $avatarUrl ? $this->escape($avatarUrl) : $defaultImg; ?>'); background-size: cover;">
				<span class="masters__big-info-head-master-online"></span>
			</div>
			<h3 class="h3biginfo"><?php echo $this->escape($displayName); ?></h3>
			<a id="bookmarkme" href="#" data-id="<?php echo (int) $this->data->id; ?>" title="Добавить в избранное"></a>
			<div class="clearFloat"></div>
		</div>
		<div class="masters__big-info-attr">
			<h3 class="h3biginfo1"><?php echo $this->escape($displayName); ?></h3>
			<div class="masters__attr-left">
				<span class="attr_left1"></span>
				<span class="attr_left2"><i class="fa fa-map-marker" aria-hidden="true"></i></span>
				<span class="attr_left3">Форма работы: <b>Салон</b></span>
			</div>
			<div class="masters__attr-right">
				<span class="attr-rating">4.5</span>
				<div class="attr-div-rating">
					<ul class="category_cinfo-ratings" style="display:none">
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star" aria-hidden="true"></i></li>
						<li><i class="fa fa-star-half" aria-hidden="true"></i></li>
					</ul>
					<span style="display:none"></span>
				</div>
				<div class="clearFloat"></div>
			</div>
			<div class="clearFloat"></div>
		</div>
		<div class="masters__gall-small">
			<span class="masters__gall-small-count"><i>Еще 0<br> фотографий</i></span>
			<div class="masters__small-img">
				<div style="background-image: url('<?php echo $avatarUrl ? $this->escape($avatarUrl) : $defaultImg; ?>'); width: 100%; display: inline-block;" class="masters__small-img-item"></div>
			</div>
			<div class="clearFloat"></div>
		</div>
	</div>
	<div class="clearFloat"></div>
</div>
<?php endif; ?>
<style>
.view_profile-tabs .z-container { position: relative; min-height: 1px; }
.view_profile-tabs .z-container .z-content { position: relative !important; transition: opacity 0.25s ease-out; }
.view_profile-tabs .z-container .z-content.z-tab-animating { position: absolute !important; top: 0; left: 0; right: 0; width: 100%; box-sizing: border-box; }
.view_profile-tabs .lk-portfolio-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
.view_profile-tabs .lk-portfolio-item img { width: 100%; height: 140px; object-fit: cover; border-radius: 12px; border: 1px solid #ddd; }
.view_profile-tabs .service__item--readonly { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 10px 0; }
.view_profile-tabs .service__item--readonly .service-name { min-width: 260px; font-weight: 600; }
.view_profile-tabs .course__item--readonly {
	display: grid;
	grid-template-columns: repeat(2, minmax(220px, 1fr));
	gap: 10px 18px;
	align-items: start;
	width: 100%;
	max-width: 760px;
	padding: 14px 16px;
	border: 1px solid #eee;
	border-radius: 14px;
	background: #fff;
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
	box-sizing: border-box;
}
.view_profile-tabs .course__item--readonly .service-name,
.view_profile-tabs .course__item--readonly .course-description {
	grid-column: 1 / -1;
	min-width: 0;
}
.view_profile-tabs .course__item--readonly span {
	min-width: 0;
	word-break: break-word;
}
.view_profile-tabs .course__item--readonly label {
	margin-right: 4px;
	font-weight: 600;
}
.view_profile-services .accordionItemContent { display: block !important; max-height: none !important; opacity: 1 !important; overflow: visible !important; }
.view_profile-services .accordionItemHeading { cursor: default !important; }
.view_profile-services .accordionItemHeading:after { display: none !important; content: none !important; }
.view_profile-services { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
.view_profile-services .profile-services-block { margin-bottom: 1.5rem; }
.view_profile-services .profile-services-block:last-child { margin-bottom: 0; }
.view_profile-services .profile-services-title { margin: 0 0 0.75rem; font-size: 1.1rem; font-weight: 600; color: #333; }
.view_profile-services .profile-services-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.view_profile-services .profile-services-card { background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef; overflow: hidden; }
.view_profile-services .profile-services-card__head { padding: 0.6rem 0.9rem; font-weight: 600; font-size: 0.95rem; background: #e9ecef; color: #495057; }
.view_profile-services .profile-services-card__list { margin: 0; padding: 0.5rem 0.9rem 0.75rem; list-style: none; font-size: 0.9rem; line-height: 1.5; color: #212529; }
.view_profile-services .profile-services-card__list li { padding: 0.25rem 0; border-bottom: 1px solid #eee; }
.view_profile-services .profile-services-card__list li:last-child { border-bottom: none; }
.view_profile-services .profile-services-block--stocks .profile-services-card { border-color: #ffeaa7; }
.view_profile-services .profile-services-block--stocks .profile-services-card__head { background: #fff3cd; color: #856404; }
.push-notify-switch { position: relative; display: inline-block; width: 44px; height: 24px; margin: 0 10px 0 0; vertical-align: middle; flex-shrink: 0; }
.push-notify-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
.push-notify-slider { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #ccc; border-radius: 24px; transition: background 0.2s; cursor: pointer; }
.push-notify-slider:before { content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,.25); }
.push-notify-switch input:checked + .push-notify-slider { background: #5cb85c; }
.push-notify-switch input:checked + .push-notify-slider:before { transform: translateX(20px); }
.push-notify-switch input:disabled + .push-notify-slider { opacity: 0.6; cursor: not-allowed; }
.push-notify-row-status { display: flex; align-items: center; gap: 4px; }
@media (max-width: 576px) {
	.view_profile-tabs .service__item--readonly,
	.view_profile-tabs .course__item--readonly {
		display: grid;
		grid-template-columns: 1fr;
		gap: 8px;
		width: 100%;
		max-width: 100%;
	}
	.view_profile-tabs .service__item--readonly .service-name {
		min-width: 0;
	}
	.view_profile-services .profile-services-grid { grid-template-columns: 1fr; gap: 0.75rem; }
	.view_profile-services .profile-services-card__head { padding: 0.5rem 0.75rem; font-size: 0.9rem; }
	.view_profile-services .profile-services-card__list { padding: 0.4rem 0.75rem 0.6rem; font-size: 0.85rem; }
}
</style>
<script>
(function(){
	var tabs = document.querySelectorAll('#jsn-profile-tabs .z-tab');
	var contents = document.querySelectorAll('#jsn-form .z-container .z-content');
	if (!tabs.length || !contents.length) return;
	var activeIndex = 0;
	function showTab(index) {
		if (index === activeIndex) return;
		var prev = contents[activeIndex];
		var next = contents[index];
		tabs.forEach(function(t, i){ t.classList.toggle('z-active', i === index); });
		contents.forEach(function(c, i){
			c.classList.toggle('z-active', i === index);
		});
		next.style.display = 'block';
		next.style.opacity = '0';
		next.offsetHeight;
		prev.classList.add('z-tab-animating');
		prev.style.opacity = '0';
		next.style.opacity = '1';
		function onPrevEnd() {
			prev.removeEventListener('transitionend', onPrevEnd);
			prev.style.display = 'none';
			prev.classList.remove('z-tab-animating');
			prev.style.opacity = '';
		}
		prev.addEventListener('transitionend', onPrevEnd);
		activeIndex = index;
	}
	contents.forEach(function(c, i){
		c.style.display = i === 0 ? 'block' : 'none';
		if (i === 0) c.style.opacity = '1';
	});
	tabs.forEach(function(tab, index){
		var a = tab.querySelector('a');
		if (a) a.addEventListener('click', function(e){ e.preventDefault(); showTab(index); });
	});
})();
</script>
<script>
(function(){
	var resendBtn = document.getElementById('lk-email-resend-btn');
	var resendMsg = document.getElementById('lk-email-resend-msg');
	if (!resendBtn || !resendMsg) return;

	var tokenNameEl = document.getElementById('lk-email-resend-token-name');
	var tokenValueEl = document.getElementById('lk-email-resend-token-value');
	var emailEl = document.getElementById('lk-email-resend-email');
	var tokenName = tokenNameEl ? tokenNameEl.value : '';
	var tokenValue = tokenValueEl ? tokenValueEl.value : '1';
	var resendEmail = emailEl ? (emailEl.value || '').trim() : '';
	var endpoint = <?php echo json_encode(Route::_('index.php?option=com_ajax&group=ajax&plugin=quickauth&format=json', false)); ?>;
	var fallback = 'Не удалось выполнить повторную отправку. Попробуйте позже.';
	function normalizePayload(json) {
		if (!json || typeof json !== 'object') {
			return {};
		}
		if (Array.isArray(json.data)) {
			return json.data[0] || {};
		}
		if (json.data && typeof json.data === 'object') {
			return json.data;
		}
		if (json.success !== undefined || json.message !== undefined) {
			return json;
		}
		return {};
	}

	resendBtn.addEventListener('click', function(){
		resendBtn.disabled = true;
		resendMsg.textContent = '';
		var fd = new FormData();
		if (tokenName) fd.append(tokenName, tokenValue || '1');
		fd.append('action', 'resend_verification');
		if (resendEmail) fd.append('email', resendEmail);
		fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(json){
				var payload = normalizePayload(json);
				resendMsg.textContent = payload.message || fallback;
				resendMsg.style.color = (payload && payload.success) ? '#1b5e20' : '#b71c1c';
			})
			.catch(function(){
				resendMsg.textContent = fallback;
				resendMsg.style.color = '#b71c1c';
			})
			.finally(function(){
				resendBtn.disabled = false;
			});
	});
})();
</script>
<?php if ($isOwn && $emailVerificationStatus === 'pending' && $emailVerificationGraceUntil !== '') : ?>
<script>
(function(){
	var graceUtc = <?php echo json_encode($emailVerificationGraceUntil, JSON_UNESCAPED_UNICODE); ?>;
	if (!graceUtc) return;
	var expiresAt = Date.parse(graceUtc.replace(' ', 'T') + 'Z');
	if (!isFinite(expiresAt)) return;
	var delay = Math.max(1000, expiresAt - Date.now() + 1000);
	setTimeout(function(){
		window.location.reload();
	}, delay);
})();
</script>
<?php endif; ?>
<?php if (!$isOwn && (int) $this->data->id > 0) : ?>
<script>
(function(){
	var toggle = document.getElementById('lk-booking-toggle');
	var block = document.getElementById('lk-booking-block');
	var form = document.getElementById('lk-booking-form');
	var dateEl = document.getElementById('lk-booking-date');
	var timeEl = document.getElementById('lk-booking-time');
	if (!toggle || !block || !form) return;
	function setDefaultDateTime() {
		if (!dateEl || !timeEl) return;
		var now = new Date();
		var in30 = new Date(now.getTime() + 30 * 60 * 1000);
		var rounded = Math.ceil(in30.getMinutes() / 15) * 15;
		in30.setMinutes(rounded % 60);
		if (rounded >= 60) in30.setHours(in30.getHours() + 1);
		dateEl.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
		timeEl.value = String(in30.getHours()).padStart(2, '0') + ':' + String(in30.getMinutes()).padStart(2, '0');
	}
	toggle.addEventListener('click', function(){
		var open = block.style.display !== 'none';
		block.style.display = open ? 'none' : 'block';
		toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
		if (!open) setDefaultDateTime();
	});
	var isUserLoggedIn = <?php echo $user->id > 0 ? 'true' : 'false'; ?>;
	var pendingBookingData = null;
	var lkBookingAjaxUrl = <?php echo json_encode(Route::_('index.php?option=com_ajax&group=ajax&plugin=lkbooking&format=json', false)); ?>;
	var lkOwnProfileUrl = <?php echo json_encode(Route::_('index.php?option=com_users&view=profile', false)); ?>;

	window.LK_BOOKING = {
		ajaxUrl: lkBookingAjaxUrl,
		ownProfileUrl: lkOwnProfileUrl,
		submitPending: function(pendingData, formToken) {
			if (!pendingData || !dateEl || !timeEl || !form) return Promise.reject('no data');
			dateEl.value = pendingData.date || '';
			timeEl.value = pendingData.time || '';
			var serviceEl = document.getElementById('lk-booking-service');
			var serviceNameEl = document.getElementById('lk-booking-service-name');
			if (serviceEl && pendingData.serviceId) serviceEl.value = pendingData.serviceId;
			if (serviceNameEl && pendingData.serviceName) serviceNameEl.value = pendingData.serviceName;
			var timeCombinedEl = document.getElementById('lk-booking-time-combined');
			var serviceNameHiddenEl = document.getElementById('lk-booking-service-name-hidden');
			if (timeCombinedEl) timeCombinedEl.value = (pendingData.date || '') + ' ' + (pendingData.time || '');
			if (serviceNameHiddenEl) serviceNameHiddenEl.value = pendingData.serviceName || '';
			var localDate = new Date((pendingData.date || '') + 'T' + (pendingData.time || ''));
			var timeUtc = isNaN(localDate.getTime()) ? '' : localDate.toISOString();
			var fd = new FormData(form);
			if (formToken) fd.set(formToken, '1');
			if (timeUtc) fd.set('time_utc', timeUtc);
			try { fd.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone); } catch (e) {}
			return fetch(lkBookingAjaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r){ return r.json(); })
				.then(function(data){
					var res = (data.data && typeof data.data.success !== 'undefined') ? data.data : data;
					if (res && res.success) return;
					throw new Error(res && res.message ? res.message : 'Ошибка записи');
				});
		}
	};

	function getBookingFormData() {
		var serviceEl = document.getElementById('lk-booking-service');
		var serviceNameEl = document.getElementById('lk-booking-service-name');
		if (!dateEl || !timeEl) return null;
		var serviceName = '';
		var serviceId = '';
		if (serviceEl && serviceEl.selectedIndex > 0) {
			serviceName = serviceEl.options[serviceEl.selectedIndex].getAttribute('data-name') || serviceEl.options[serviceEl.selectedIndex].text;
			serviceId = serviceEl.value;
		} else if (serviceNameEl) {
			serviceName = serviceNameEl.value.trim();
		}
		if (!serviceName) return null;
		return {
			date: dateEl.value,
			time: timeEl.value,
			serviceName: serviceName,
			serviceId: serviceId
		};
	}

	function submitBookingForm() {
		var msgEl = document.getElementById('lk-booking-msg');
		var submitBtn = document.getElementById('lk-booking-submit');
		if (!dateEl || !timeEl || !msgEl || !submitBtn) return;

		var formData = getBookingFormData();
		if (!formData) {
			msgEl.textContent = 'Заполните все поля';
			msgEl.style.color = '#c00';
			return;
		}

		var localDate = new Date(formData.date + 'T' + formData.time);
		var timeUtc = isNaN(localDate.getTime()) ? '' : localDate.toISOString();
		var timeStr = formData.date + ' ' + formData.time;
		var timeCombinedEl = document.getElementById('lk-booking-time-combined');
		var serviceNameHiddenEl = document.getElementById('lk-booking-service-name-hidden');
		if (timeCombinedEl) timeCombinedEl.value = timeStr;
		if (serviceNameHiddenEl) serviceNameHiddenEl.value = formData.serviceName;

		var fd = new FormData(form);
		if (timeUtc) fd.set('time_utc', timeUtc);
		try { fd.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone); } catch (e) {}

		msgEl.textContent = '';
		submitBtn.disabled = true;
		fetch('<?php echo Route::_('index.php?option=com_ajax&group=ajax&plugin=lkbooking&format=json', false); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(data){
				var res = (data.data && typeof data.data.success !== 'undefined') ? data.data : data;
				if (res.success) {
					msgEl.textContent = res.message || 'Вы записались!';
					msgEl.style.color = '#0a0';
					msgEl.style.fontWeight = 'bold';
					form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(function(el){ el.disabled = true; });
					submitBtn.style.display = 'none';
					sessionStorage.removeItem('lk_pending_booking');
					setTimeout(function(){
						window.location.href = '<?php echo Route::_('index.php?option=com_users&view=profile', false); ?>';
					}, 1500);
				} else {
					msgEl.textContent = res.message || 'Ошибка';
					msgEl.style.color = '#c00';
				}
			})
			.catch(function(){ msgEl.textContent = 'Ошибка запроса'; msgEl.style.color = '#c00'; })
			.finally(function(){ submitBtn.disabled = false; });
	}

	try {
		var savedData = sessionStorage.getItem('lk_pending_booking');
		if (savedData && isUserLoggedIn) {
			pendingBookingData = JSON.parse(savedData);
			if (pendingBookingData && pendingBookingData.masterId == <?php echo (int) $this->data->id; ?>) {
				dateEl.value = pendingBookingData.date || '';
				timeEl.value = pendingBookingData.time || '';
				var serviceEl = document.getElementById('lk-booking-service');
				var serviceNameEl = document.getElementById('lk-booking-service-name');
				if (serviceEl && pendingBookingData.serviceId) {
					serviceEl.value = pendingBookingData.serviceId;
				} else if (serviceNameEl && pendingBookingData.serviceName) {
					serviceNameEl.value = pendingBookingData.serviceName;
				}
				block.style.display = 'block';
				toggle.setAttribute('aria-expanded', 'true');
				setTimeout(function() {
					submitBookingForm();
				}, 500);
			}
		}
	} catch (e) {}

	form.addEventListener('submit', function(e){
		e.preventDefault();
		if (!isUserLoggedIn) {
			var formData = getBookingFormData();
			if (!formData) {
				document.getElementById('lk-booking-msg').textContent = 'Заполните все поля';
				document.getElementById('lk-booking-msg').style.color = '#c00';
				return;
			}
			formData.masterId = <?php echo (int) $this->data->id; ?>;
			try {
				sessionStorage.setItem('lk_pending_booking', JSON.stringify(formData));
			} catch (e) {}

			if (window.QuickAuth && typeof window.QuickAuth.show === 'function') {
				window.QuickAuth.show('', {
					title: 'Авторизуйтесь, чтобы записаться',
					callback: function(res) {
						var raw = null;
						try { raw = sessionStorage.getItem('lk_pending_booking'); } catch (e) {}
						if (raw && window.LK_BOOKING && typeof window.LK_BOOKING.submitPending === 'function') {
							var data = JSON.parse(raw);
							var formToken = (res && res.form_token) ? res.form_token : null;
							window.LK_BOOKING.submitPending(data, formToken).then(function() {
								try { sessionStorage.removeItem('lk_pending_booking'); } catch (e) {}
								window.location.href = window.LK_BOOKING.ownProfileUrl || (res && res.redirect) || '';
							}).catch(function() {
								try { sessionStorage.removeItem('lk_pending_booking'); } catch (e) {}
								window.location.href = window.LK_BOOKING.ownProfileUrl || (res && res.redirect) || '';
							});
						} else {
							window.location.href = (res && res.redirect) || window.LK_BOOKING && window.LK_BOOKING.ownProfileUrl || '';
							if (!(res && res.redirect) && !(window.LK_BOOKING && window.LK_BOOKING.ownProfileUrl)) location.reload();
						}
					}
				});
			} else {
				document.getElementById('lk-booking-msg').textContent = 'Для записи необходима авторизация';
				document.getElementById('lk-booking-msg').style.color = '#c00';
			}
			return;
		}
		submitBookingForm();
	});
})();
</script>
<?php endif; ?>
<?php if ($isOwn) :
	$firebaseConfig = is_file(JPATH_ROOT . '/configuration/firebase-config.php') ? (include JPATH_ROOT . '/configuration/firebase-config.php') : [];
	if (is_array($firebaseConfig) && !empty($firebaseConfig['apiKey'])) :
?>
<script>
window.PUSHNOTIFY_BASE = <?php echo json_encode($pushnotifyBase); ?>;
window.PUSHNOTIFY_SW_URL = <?php echo json_encode($pushnotifySwUrl); ?>;
window.PUSHNOTIFY_TOKEN_NAME = <?php echo json_encode($pushnotifyTokenName); ?>;
window.PUSHNOTIFY_TOKEN_VALUE = <?php echo json_encode($pushnotifyTokenValue); ?>;
window.FIREBASE_VAPID_KEY = <?php echo json_encode($firebaseConfig['vapidKey'] ?? ''); ?>;
window.FIREBASE_CONFIG = <?php echo json_encode([
	'apiKey' => $firebaseConfig['apiKey'] ?? '',
	'authDomain' => $firebaseConfig['authDomain'] ?? '',
	'projectId' => $firebaseConfig['projectId'] ?? '',
	'storageBucket' => $firebaseConfig['storageBucket'] ?? '',
	'messagingSenderId' => $firebaseConfig['messagingSenderId'] ?? '',
	'appId' => $firebaseConfig['appId'] ?? '',
]); ?>;
</script>
<script src="https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js"></script>
<script>
(function(){ if (window.firebase && window.FIREBASE_CONFIG) { try { window.firebase.app(); } catch (e) { window.firebase.initializeApp(window.FIREBASE_CONFIG); } } })();
</script>
<script src="https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js"></script>
<script src="<?php echo $pushnotifyRoot; ?>/media/com_pushnotify/js/push-notifications.js"></script>
<script>
(function(){
	try {
		var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
		if (tz && window.PUSHNOTIFY_BASE) {
			var u = window.PUSHNOTIFY_BASE + (window.PUSHNOTIFY_BASE.indexOf('?') === -1 ? '?' : '&') + 'task=display.setTimezone&format=json&timezone=' + encodeURIComponent(tz);
			fetch(u, { method: 'GET', credentials: 'same-origin' }).catch(function(){});
		}
	} catch (e) {}
})();
</script>
<script>
(function() {
	var statusEl = document.getElementById('pushnotify-status');
	var toggleInput = document.getElementById('pushnotify-toggle-input');
	if (!statusEl || !toggleInput || !window.PushNotify) return;

	var baseUrl = window.PUSHNOTIFY_BASE || '';
	var tokenName = window.PUSHNOTIFY_TOKEN_NAME || '';
	var tokenValue = window.PUSHNOTIFY_TOKEN_VALUE || '';
	var notificationsEnabled = false;
	var currentFcmToken = '';
	function getSep(u) { return (u && u.indexOf('?') === -1) ? '?' : '&'; }
	function getPreferencesWithToken(fcmToken, callback) {
		var url = baseUrl + getSep(baseUrl) + 'task=display.getPreferences&format=json&' + encodeURIComponent(tokenName) + '=' + encodeURIComponent(tokenValue);
		if (fcmToken) url += '&token=' + encodeURIComponent(fcmToken);
		fetch(url, { credentials: 'same-origin' }).then(function(r) { return r.json(); }).then(callback).catch(function() { if (callback) callback({}); });
	}

	function setStatus(t) { statusEl.textContent = t || '—'; }

	function applyUI(r) {
		if (!r || !r.success) return;
		var tokenRegistered = r.current_token_registered === true;
		notificationsEnabled = !!(r.subscribed && tokenRegistered && r.notifications_enabled !== false);
		toggleInput.checked = notificationsEnabled;
		toggleInput.disabled = false;
		setStatus(notificationsEnabled ? 'Уведомления включены' : 'Уведомления отключены');
	}

	getPreferencesWithToken(null, function(r) {
		applyUI(r);
		if (!('Notification' in window) || Notification.permission !== 'granted' || !window.firebase || !window.FIREBASE_CONFIG) return;
		var swUrl = window.PUSHNOTIFY_SW_URL || window.PushNotify.swUrl;
		navigator.serviceWorker.register(swUrl, { scope: '/' }).then(function(reg) {
			return reg.active ? Promise.resolve(reg) : new Promise(function(resolve) {
				reg.addEventListener('updatefound', function() {
					var n = reg.installing;
					if (n) n.addEventListener('statechange', function() { if (n.state === 'activated') resolve(reg); });
				});
				setTimeout(function() { resolve(reg); }, 3000);
			});
		}).then(function(swRegistration) {
			var app = window.firebase.app().name ? window.firebase.app() : window.firebase.initializeApp(window.FIREBASE_CONFIG);
			var messaging = app.messaging();
			return messaging.getToken({
				vapidKey: window.FIREBASE_VAPID_KEY || undefined,
				serviceWorkerRegistration: swRegistration
			});
		}).then(function(token) {
			if (token) {
				currentFcmToken = token;
				getPreferencesWithToken(token, applyUI);
			}
		}).catch(function() {});
	});

	function sendTokenToServer(token) {
		if (!token) return;
		currentFcmToken = token;
		var ua = navigator.userAgent;
		var device = /Android|webOS|iPhone|iPad|iPod|BlackBerry/i.test(ua) ? 'android' : 'desktop';
		var browser = ua.indexOf('Chrome') >= 0 ? 'chrome' : (ua.indexOf('Firefox') >= 0 ? 'firefox' : (ua.indexOf('Edg') >= 0 ? 'edge' : ''));
		window.PushNotify.subscribe(token, device, browser, function(res) {
			if (res && res.success) getPreferencesWithToken(token, applyUI);
		});
	}

	function silentRefreshToken() {
		if (!('Notification' in window) || Notification.permission !== 'granted' || !window.firebase || !window.FIREBASE_CONFIG) return;
		var swUrl = window.PUSHNOTIFY_SW_URL || window.PushNotify.swUrl;
		navigator.serviceWorker.ready.then(function() {
			return navigator.serviceWorker.getRegistration('/');
		}).then(function(reg) {
			if (!reg) return navigator.serviceWorker.register(swUrl, { scope: '/' });
			return reg;
		}).then(function(reg) {
			var app;
			try { app = window.firebase.app(); } catch (e) { app = window.firebase.initializeApp(window.FIREBASE_CONFIG); }
			return app.messaging().getToken({
				vapidKey: window.FIREBASE_VAPID_KEY || undefined,
				serviceWorkerRegistration: reg
			});
		}).then(function(token) {
			if (token) sendTokenToServer(token);
		}).catch(function() {});
	}

	document.addEventListener('visibilitychange', function() {
		if (document.visibilityState === 'visible') silentRefreshToken();
	});
	setInterval(silentRefreshToken, 25 * 60 * 1000);

	function runSubscribeFlow() {
		if (!('Notification' in window) || !window.firebase) {
			setStatus('Браузер не поддерживает уведомления');
			return;
		}
		Notification.requestPermission().then(function(perm) {
			if (perm !== 'granted') {
				setStatus('Разрешение отклонено');
				return;
			}
			setStatus('Регистрация…');
			var swUrl = window.PUSHNOTIFY_SW_URL || window.PushNotify.swUrl;
			navigator.serviceWorker.getRegistrations().then(function(regs) {
				return Promise.all(regs.map(function(r) { return r.unregister(); }));
			}).then(function() {
				return navigator.serviceWorker.register(swUrl, { scope: '/' });
			}).then(function(reg) {
				return new Promise(function(resolve) {
					if (reg.active) return resolve(reg);
					reg.addEventListener('updatefound', function() {
						var n = reg.installing;
						if (n) n.addEventListener('statechange', function() { if (n.state === 'activated') resolve(reg); });
					});
					setTimeout(function() { resolve(reg); }, 5000);
				});
			}).then(function(swRegistration) {
				var app = window.firebase.app().name ? window.firebase.app() : window.firebase.initializeApp(window.FIREBASE_CONFIG);
				var messaging = app.messaging();
				return messaging.getToken({
					vapidKey: window.FIREBASE_VAPID_KEY || undefined,
					serviceWorkerRegistration: swRegistration
				}).then(function(t) { if (t) console.log('FCM token', t); return t; });
			}).then(function(token) {
				if (!token) { setStatus('Не удалось получить токен'); return; }
				sendTokenToServer(token);
			}).catch(function(e) {
				console.error('getToken error', e);
				setStatus('Ошибка: ' + (e && e.message ? e.message : 'неизвестно'));
			});
		});
	}
	toggleInput.addEventListener('change', function() {
		if (toggleInput.checked) {
			runSubscribeFlow();
			return;
		}
		toggleInput.disabled = true;
		if (currentFcmToken) {
			window.PushNotify.unsubscribe(currentFcmToken, function(r) {
				if (r && r.success) {
					window.PushNotify.updatePreferences(false, function() {
						notificationsEnabled = false;
						toggleInput.checked = false;
						toggleInput.disabled = false;
						setStatus('Уведомления отключены');
					});
					return;
				}
				toggleInput.checked = true;
				toggleInput.disabled = false;
				setStatus((r && r.message) ? r.message : 'Ошибка сохранения');
			});
			return;
		}
		window.PushNotify.updatePreferences(false, function(r) {
			toggleInput.disabled = false;
			if (r && r.success) {
				notificationsEnabled = false;
				toggleInput.checked = false;
				setStatus('Уведомления отключены');
				return;
			}
			toggleInput.checked = true;
			setStatus((r && r.message) ? r.message : 'Ошибка сохранения');
		});
	});
})();
</script>
<script>
(function(){
	window.PUSHNOTIFY_BASE = window.PUSHNOTIFY_BASE || <?php echo json_encode($pushnotifyBase); ?>;
	window.PUSHNOTIFY_TOKEN_NAME = window.PUSHNOTIFY_TOKEN_NAME || <?php echo json_encode($pushnotifyTokenName); ?>;
	window.PUSHNOTIFY_TOKEN_VALUE = window.PUSHNOTIFY_TOKEN_VALUE || <?php echo json_encode($pushnotifyTokenName); ?>;
	var toggle = document.getElementById('lk-notify-toggle');
	var dropdown = document.getElementById('lk-notify-dropdown');
	var listEl = document.getElementById('lk-notify-list');
	var emptyEl = document.getElementById('lk-notify-empty');
	var loadingEl = document.getElementById('lk-notify-loading');
	var badgeEl = document.getElementById('lk-notify-badge');
	if (!toggle || !dropdown || !listEl) return;
	var baseUrl = window.PUSHNOTIFY_BASE || '';
	var tokenName = window.PUSHNOTIFY_TOKEN_NAME || '';
	var tokenValue = window.PUSHNOTIFY_TOKEN_VALUE || '';
	function getSep(u) { return (u && u.indexOf('?') === -1) ? '?' : '&'; }
	function loadInbox(cb) {
		if (!baseUrl) { if (cb) cb(); return; }
		loadingEl.style.display = 'block';
		emptyEl.style.display = 'none';
		listEl.innerHTML = '';
		fetch(baseUrl + getSep(baseUrl) + 'task=display.getInbox&format=json')
			.then(function(r){ return r.json(); })
			.then(function(data){
				loadingEl.style.display = 'none';
				if (data && data.success && Array.isArray(data.items)) {
					if (data.unread_count > 0 && badgeEl) {
						badgeEl.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
						badgeEl.style.display = 'inline-block';
					} else if (badgeEl) {
						badgeEl.style.display = 'none';
					}
					if (data.items.length === 0) {
						emptyEl.style.display = 'block';
					} else {
						data.items.forEach(function(it){
							var read = !!it.read_at;
							var div = document.createElement('div');
							div.className = 'lk-notify-item' + (read ? ' lk-notify-item-read' : '');
							div.setAttribute('data-id', it.id);
							div.style.cssText = 'padding:10px 12px; border-bottom:1px solid #eee;';
							if (read) div.style.backgroundColor = '#f5f5f5';
							var title = document.createElement('div');
							title.style.fontWeight = 'bold';
							title.textContent = it.title || '';
							var body = document.createElement('div');
							body.style.fontSize = '0.9em';
							body.style.color = '#555';
							body.textContent = (it.body || '').substring(0, 120) + ((it.body && it.body.length > 120) ? '…' : '');
							var meta = document.createElement('div');
							meta.style.fontSize = '0.8em';
							meta.style.color = '#999';
							meta.style.marginTop = '4px';
							var d = it.event_time_utc || it.created_at;
							if (d) {
								var iso = (d + '').replace(' ', 'T');
								if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(iso) && iso.indexOf('Z') === -1 && iso.indexOf('+') === -1) iso += 'Z';
								try {
									var dateObj = new Date(iso);
									if (!isNaN(dateObj.getTime())) meta.textContent = dateObj.toLocaleString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
									else meta.textContent = d;
								} catch (e) { meta.textContent = d; }
							}
							var acts = document.createElement('div');
							acts.style.marginTop = '6px';
							if (!read) {
								var btnRead = document.createElement('button');
								btnRead.type = 'button';
								btnRead.className = 'btn btn-xs btn-default';
								btnRead.textContent = 'Прочитано';
								btnRead.addEventListener('click', function(){ markRead(it.id, div); });
								acts.appendChild(btnRead);
								acts.appendChild(document.createTextNode(' '));
							}
							var btnDel = document.createElement('button');
							btnDel.type = 'button';
							btnDel.className = 'btn btn-xs btn-default';
							btnDel.textContent = 'Удалить';
							btnDel.addEventListener('click', function(){ deleteNotif(it.id, div); });
							acts.appendChild(btnDel);
							div.appendChild(title);
							div.appendChild(body);
							div.appendChild(meta);
							div.appendChild(acts);
							listEl.appendChild(div);
						});
					}
				}
				if (cb) cb();
			})
			.catch(function(){ loadingEl.style.display = 'none'; if (cb) cb(); });
	}
	function markRead(id, rowEl) {
		var fd = new FormData();
		fd.append(tokenName, tokenValue);
		fd.append('id', id);
		fetch(baseUrl + getSep(baseUrl) + 'task=display.markRead&format=json', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(data){
				if (data && data.success && rowEl) {
					rowEl.classList.add('lk-notify-item-read');
					rowEl.style.backgroundColor = '#f5f5f5';
					var acts = rowEl.querySelector('div:last-child');
					if (acts) {
						var br = acts.querySelector('button');
						if (br && br.textContent === 'Прочитано') br.remove();
					}
					var n = badgeEl ? parseInt(badgeEl.textContent, 10) : 0;
					if (n > 1 && badgeEl) badgeEl.textContent = n - 1;
					else if (badgeEl) badgeEl.style.display = 'none';
				}
			});
	}
	function deleteNotif(id, rowEl) {
		var fd = new FormData();
		fd.append(tokenName, tokenValue);
		fd.append('id', id);
		fetch(baseUrl + getSep(baseUrl) + 'task=display.deleteNotification&format=json', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(data){
				if (data && data.success && rowEl) {
					rowEl.remove();
					var items = listEl.querySelectorAll('.lk-notify-item');
					if (items.length === 0) {
						emptyEl.style.display = 'block';
						var n = badgeEl ? parseInt(badgeEl.textContent, 10) : 0;
						if (n > 1 && badgeEl) badgeEl.textContent = n - 1;
						else if (badgeEl) badgeEl.style.display = 'none';
					}
				}
			});
	}
	toggle.addEventListener('click', function(e){
		e.stopPropagation();
		if (window.ViglingPushPrompt && typeof window.ViglingPushPrompt.show === 'function' && typeof window.ViglingPushPrompt.getPreferences === 'function') {
			window.ViglingPushPrompt.getPreferences().then(function(prefs) {
				if (prefs && prefs.success) {
					var needsPrompt = Notification.permission === 'default' || prefs.subscribed !== true || prefs.notifications_enabled === false;
					if (needsPrompt) {
						window.ViglingPushPrompt.show({
							reason: 'bell_click',
							force: true,
							remember: false
						});
						return;
					}
				}
				var open = dropdown.style.display === 'block';
				if (!open) {
					dropdown.style.display = 'block';
					toggle.setAttribute('aria-expanded', 'true');
					loadInbox();
				} else {
					dropdown.style.display = 'none';
					toggle.setAttribute('aria-expanded', 'false');
				}
			}).catch(function() {
				var open = dropdown.style.display === 'block';
				if (!open) {
					dropdown.style.display = 'block';
					toggle.setAttribute('aria-expanded', 'true');
					loadInbox();
				} else {
					dropdown.style.display = 'none';
					toggle.setAttribute('aria-expanded', 'false');
				}
			});
			return;
		}
		var open = dropdown.style.display === 'block';
		if (!open) {
			dropdown.style.display = 'block';
			toggle.setAttribute('aria-expanded', 'true');
			loadInbox();
		} else {
			dropdown.style.display = 'none';
			toggle.setAttribute('aria-expanded', 'false');
		}
	});
	document.addEventListener('click', function(){
		dropdown.style.display = 'none';
		toggle.setAttribute('aria-expanded', 'false');
	});
	dropdown.addEventListener('click', function(e){ e.stopPropagation(); });
	loadInbox();
})();
</script>
<?php
	endif;
endif; ?>

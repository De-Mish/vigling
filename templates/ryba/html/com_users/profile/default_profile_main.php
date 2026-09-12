<?php
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Access\Access;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Plugin\User\Vigling\Helper\UserProfileExtraFieldsHelper;

$jcfields = [];
if (!empty($this->data->jcfields)) {
	foreach ($this->data->jcfields as $f) {
		if (isset($f->name)) {
			$jcfields[$f->name] = $f;
		}
	}
}
if (empty($jcfields) && !empty($this->data->id)) {
	$fields = FieldsHelper::getFields('com_users.user', $this->data, true);
	if (!empty($fields)) {
		foreach ($fields as $f) {
			if (isset($f->name)) {
				$jcfields[$f->name] = $f;
			}
		}
	}
}

$profileFallback = [
	'firstname' => ['user' => 'name'], 'lastname' => ['profile' => 'lastname'],
	'telefon' => ['profile' => 'phone'], 'email' => ['user' => 'email'], 'sity' => ['profile' => 'city'],
	'area' => ['profile' => 'region'], 'street' => ['profile' => 'address1'], 'house_number' => ['profile' => 'address2'],
	'link' => ['profile' => 'website'], 'o_sebe' => ['profile' => 'aboutme'],
];
$profileOwnerId = (int) ($this->data->id ?? 0);
$profileGroups = $profileOwnerId > 0 ? Access::getGroupsByUser($profileOwnerId, false) : [];
$profileIsMaster = in_array(3, $profileGroups, true) || in_array(8, $profileGroups, true);
$profileMasterType = isset($jcfields['is_master']->rawvalue) && is_scalar($jcfields['is_master']->rawvalue)
	? trim((string) $jcfields['is_master']->rawvalue)
	: '';
$profileIsClient = !$profileIsMaster && $profileMasterType !== '1' && $profileMasterType !== '2';

if ($profileIsClient) {
	$rows = [
		'firstname' => 'Имя',
		'lastname' => 'Фамилия',
		'sity' => 'Город',
		'telefon' => 'Телефон',
		'email' => 'E-mail',
	];
} else {
	$rows = [
		'firstname' => 'Имя', 'lastname' => 'Фамилия', 'telefon' => 'Телефон',
		'email' => 'E-mail', 'sity' => 'Город', 'area' => 'Район', 'street' => 'Улица', 'house_number' => 'Номер дома',
		'doorway' => 'Подъезд', 'floor' => 'Этаж', 'apartment' => 'Квартира',
		'home' => 'Форма работы', 'payment_method' => 'Способ оплаты', 'suitable_for_children' => 'Подходит для детей',
		'link' => 'Vk', 'telegram' => 'Телеграм', 'max' => 'Макс', 'o_sebe' => 'О себе',
	];
}
$profile = isset($this->data->profile) && is_array($this->data->profile) ? $this->data->profile : [];
$notFound = 'Нет информации';
?>
<fieldset id="users-profile-main" class="users-profile-main">
	<dl class="dl-horizontal">
		<?php foreach ($rows as $fieldName => $label) : ?>
			<?php
			if ($fieldName === 'email') {
				$val = isset($this->data->email) ? trim((string) $this->data->email) : '';
			} else {
				$val = isset($jcfields[$fieldName]) && strlen(trim((string) $jcfields[$fieldName]->value)) > 0 ? trim((string) $jcfields[$fieldName]->value) : '';
				if ($val === '' && isset($jcfields[$fieldName]->rawvalue) && is_scalar($jcfields[$fieldName]->rawvalue)) {
					$val = trim((string) $jcfields[$fieldName]->rawvalue);
				}
				if ($val === '' && isset($profileFallback[$fieldName])) {
					$cfg = $profileFallback[$fieldName];
					if (!empty($cfg['user']) && isset($this->data->{$cfg['user']})) {
						$val = trim((string) $this->data->{$cfg['user']});
					} elseif (!empty($cfg['profile']) && isset($profile[$cfg['profile']])) {
						$val = is_scalar($profile[$cfg['profile']]) ? trim((string) $profile[$cfg['profile']]) : '';
					}
				}
				if (!class_exists(UserProfileExtraFieldsHelper::class, false)) {
					$vgExtraHelperFile = JPATH_PLUGINS . '/user/vigling/src/Helper/UserProfileExtraFieldsHelper.php';
					if (is_file($vgExtraHelperFile)) {
						require_once $vgExtraHelperFile;
					}
				}
				if (class_exists(UserProfileExtraFieldsHelper::class, false)) {
					if (in_array($fieldName, ['doorway', 'floor', 'apartment'], true)) {
						$val = UserProfileExtraFieldsHelper::decodeText($val);
					} elseif ($fieldName === 'home') {
						$val = UserProfileExtraFieldsHelper::homeDisplay($val);
					} elseif ($fieldName === 'payment_method') {
						$val = UserProfileExtraFieldsHelper::paymentDisplay($val);
					} elseif ($fieldName === 'suitable_for_children') {
						$val = UserProfileExtraFieldsHelper::isChildrenYes($val) ? 'Да' : '';
					}
				}
			}
			$defaultLabel = Text::_('COM_USERS_PROFILE_VALUE_NOT_FOUND');
			$isEmpty = ($val === '' || $val === $defaultLabel);
			if ($isEmpty) $val = '';
			?>
			<dt><?php echo $this->escape($label); ?></dt>
			<dd class="<?php echo $isEmpty ? 'profile-value-empty' : ''; ?>">
				<?php if ($fieldName === 'email' && $val !== '') : ?>
					<a href="mailto:<?php echo $this->escape($val); ?>"><?php echo $this->escape($val); ?></a>
				<?php elseif (in_array($fieldName, ['link', 'telegram', 'max'], true) && $val !== '' && (strpos($val, 'http') === 0 || strpos($val, '.') !== false)) : ?>
					<a href="<?php echo $this->escape(strpos($val, 'http') === 0 ? $val : 'https://' . ltrim($val, '/')); ?>" target="_blank" rel="noopener"><?php echo $this->escape($val); ?></a>
				<?php else : ?>
					<?php echo $isEmpty ? $this->escape($notFound) : $this->escape($val); ?>
				<?php endif; ?>
			</dd>
		<?php endforeach; ?>
	</dl>
</fieldset>

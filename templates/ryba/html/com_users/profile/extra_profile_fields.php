<?php
defined('_JEXEC') or die;

use Joomla\Plugin\User\Vigling\Helper\UserProfileExtraFieldsHelper;

if (!class_exists(UserProfileExtraFieldsHelper::class)) {
	require_once JPATH_PLUGINS . '/user/vigling/src/Helper/UserProfileExtraFieldsHelper.php';
}

$vgShowLabels = $vgShowLabels ?? true;
$vgExtraMasterClass = !empty($vgExtraMasterOnly) ? ' master-only-field' : '';
$vgExtraFieldsPart = $vgExtraFieldsPart ?? 'all';
$vgDoorway = isset($vgDoorway) ? (string) $vgDoorway : '';
$vgFloor = isset($vgFloor) ? (string) $vgFloor : '';
$vgApartment = isset($vgApartment) ? (string) $vgApartment : '';
$vgHomeSelected = isset($vgHomeSelected) && is_array($vgHomeSelected) ? $vgHomeSelected : [];
$vgPaymentSelected = isset($vgPaymentSelected) && is_array($vgPaymentSelected) ? $vgPaymentSelected : [];
$vgChildren = !empty($vgChildren);
$escape = static function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$showAddress = in_array($vgExtraFieldsPart, ['all', 'address'], true);
$showOptions = in_array($vgExtraFieldsPart, ['all', 'options'], true);
?>
<?php if ($showAddress) : ?>
<div class="control-group doorway-group<?php echo $vgExtraMasterClass; ?>">
	<?php if ($vgShowLabels) : ?>
	<div class="control-label"><label for="jform_doorway">Подъезд</label></div>
	<?php endif; ?>
	<div class="controls">
		<input type="text" name="jform[com_fields][doorway]" id="jform_doorway" value="<?php echo $escape($vgDoorway); ?>" placeholder="Подъезд" maxlength="80" autocomplete="off">
	</div>
</div>
<div class="control-group floor-group<?php echo $vgExtraMasterClass; ?>">
	<?php if ($vgShowLabels) : ?>
	<div class="control-label"><label for="jform_floor">Этаж</label></div>
	<?php endif; ?>
	<div class="controls">
		<input type="text" name="jform[com_fields][floor]" id="jform_floor" value="<?php echo $escape($vgFloor); ?>" placeholder="Этаж" maxlength="80" autocomplete="off">
	</div>
</div>
<div class="control-group apartment-group<?php echo $vgExtraMasterClass; ?>">
	<?php if ($vgShowLabels) : ?>
	<div class="control-label"><label for="jform_apartment">Квартира</label></div>
	<?php endif; ?>
	<div class="controls">
		<input type="text" name="jform[com_fields][apartment]" id="jform_apartment" value="<?php echo $escape($vgApartment); ?>" placeholder="Квартира" maxlength="80" autocomplete="off">
	</div>
</div>
<?php endif; ?>
<?php if ($showOptions) : ?>
<input type="hidden" name="jform[vigling_profile_extra]" value="1">
<div class="control-group home-group vg-profile-extra-group<?php echo $vgExtraMasterClass; ?>">
	<div class="control-label"><label>Форма работы</label></div>
	<div class="controls">
		<fieldset id="jform_home" class="checkboxes vg-profile-checkboxes">
			<?php foreach (UserProfileExtraFieldsHelper::HOME_LABELS as $homeId => $homeLabel) : ?>
			<label for="jform_home<?php echo (int) $homeId; ?>" class="checkbox<?php echo in_array((int) $homeId, $vgHomeSelected, true) ? ' active' : ''; ?>">
				<input type="checkbox" id="jform_home<?php echo (int) $homeId; ?>" name="jform[com_fields][home][]" value="<?php echo (int) $homeId; ?>"<?php echo in_array((int) $homeId, $vgHomeSelected, true) ? ' checked' : ''; ?>>
				<?php echo $escape($homeLabel); ?>
			</label>
			<?php endforeach; ?>
		</fieldset>
	</div>
</div>
<div class="control-group payment_method-group vg-profile-extra-group<?php echo $vgExtraMasterClass; ?>">
	<div class="control-label"><label>Способ оплаты</label></div>
	<div class="controls">
		<fieldset id="jform_payment_method" class="checkboxes vg-profile-checkboxes">
			<?php foreach (UserProfileExtraFieldsHelper::PAYMENT_LABELS as $payKey => $payLabel) : ?>
			<label for="jform_payment_method_<?php echo $escape($payKey); ?>" class="checkbox<?php echo in_array($payKey, $vgPaymentSelected, true) ? ' active' : ''; ?>">
				<input type="checkbox" id="jform_payment_method_<?php echo $escape($payKey); ?>" name="jform[com_fields][payment_method][]" value="<?php echo $escape($payKey); ?>"<?php echo in_array($payKey, $vgPaymentSelected, true) ? ' checked' : ''; ?>>
				<?php echo $escape($payLabel); ?>
			</label>
			<?php endforeach; ?>
		</fieldset>
	</div>
</div>
<div class="control-group suitable_for_children-group vg-profile-extra-group<?php echo $vgExtraMasterClass; ?>">
	<div class="control-label"><label for="jform_suitable_for_children">Подходит для детей</label></div>
	<div class="controls">
		<fieldset class="checkboxes vg-profile-checkboxes">
			<label for="jform_suitable_for_children" class="checkbox<?php echo $vgChildren ? ' active' : ''; ?>">
				<input type="checkbox" id="jform_suitable_for_children" name="jform[com_fields][suitable_for_children]" value="1"<?php echo $vgChildren ? ' checked' : ''; ?>>
				Да
			</label>
		</fieldset>
	</div>
</div>
<?php endif; ?>

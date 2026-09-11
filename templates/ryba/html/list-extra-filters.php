<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

if (!isset($currentPayment) || !is_array($currentPayment)) {
	$currentPayment = [];
	foreach ((array) Factory::getApplication()->getInput()->get('payment', [], 'array') as $payKey) {
		$payKey = strtolower(trim((string) $payKey));
		if (in_array($payKey, ['card', 'cash', 'transfer'], true)) {
			$currentPayment[] = $payKey;
		}
	}
	$currentPayment = array_values(array_unique($currentPayment));
}
if (!isset($currentChildren)) {
	$currentChildren = in_array(
		strtolower(trim((string) Factory::getApplication()->getInput()->get('children', '', 'string'))),
		['1', 'yes', 'on', 'true', 'да'],
		true
	);
}
$vgListPayLabels = [
	'card' => 'Банковская карта',
	'cash' => 'Наличные',
	'transfer' => 'Банковский перевод',
];
?>
<div class="vg-list-extra-filters">
	<div class="vg-list-filter-label">Способ оплаты</div>
	<?php foreach ($vgListPayLabels as $payKey => $payLabel) : ?>
	<label class="vg-list-check">
		<input type="checkbox" name="payment[]" value="<?php echo htmlspecialchars($payKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo in_array($payKey, $currentPayment, true) ? ' checked' : ''; ?>>
		<?php echo htmlspecialchars($payLabel, ENT_QUOTES, 'UTF-8'); ?>
	</label>
	<?php endforeach; ?>
	<label class="vg-list-check vg-list-check-children">
		<input type="checkbox" name="children" value="1"<?php echo !empty($currentChildren) ? ' checked' : ''; ?>>
		Подходит для детей
	</label>
</div>

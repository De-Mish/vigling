<?php
defined('_JEXEC') or die;

$srcFields = $listExtraFields ?? $fields ?? $masterFields ?? [];
$paymentVal = (string) ($srcFields['payment_method'] ?? '');
$paymentLabels = [
	'card' => 'Банковская карта',
	'cash' => 'Наличные',
	'transfer' => 'Банковский перевод',
];
$paymentParts = [];
if ($paymentVal !== '' && $paymentVal !== '[]') {
	$decodedPay = json_decode($paymentVal, true);
	$payValues = is_array($decodedPay) ? $decodedPay : (preg_split('/[,\s]+/', $paymentVal) ?: []);
	foreach ($payValues as $payKey) {
		$payKey = strtolower(trim((string) $payKey));
		if (isset($paymentLabels[$payKey])) {
			$paymentParts[] = $paymentLabels[$payKey];
		}
	}
	$paymentParts = array_values(array_unique($paymentParts));
}
$childrenRaw = strtolower(trim((string) ($srcFields['suitable_for_children'] ?? '')));
if ($childrenRaw !== '') {
	$decodedChild = json_decode($childrenRaw, true);
	if (is_array($decodedChild)) {
		$childrenRaw = strtolower(trim((string) reset($decodedChild)));
	}
}
$childrenYes = in_array($childrenRaw, ['1', 'yes', 'true', 'on', 'да'], true);
?>
<?php if ($paymentParts !== []) : ?>
	<span class="attr_left3">Способ оплаты: <b><?php echo htmlspecialchars(implode(', ', $paymentParts), ENT_QUOTES, 'UTF-8'); ?></b></span>
<?php endif; ?>
<?php if ($childrenYes) : ?>
	<span class="attr_left3">Подходит для детей</span>
<?php endif; ?>

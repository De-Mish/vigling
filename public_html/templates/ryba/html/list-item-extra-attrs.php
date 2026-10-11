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

static $specialtyTitlesCache = [];
$specialtyIds = [];
$specialtyRaw = trim((string) ($srcFields['vyberite_spetsialnos'] ?? ''));
if ($specialtyRaw !== '') {
	$decodedSpec = json_decode($specialtyRaw, true);
	if (is_array($decodedSpec)) {
		$iterSpec = new RecursiveIteratorIterator(new RecursiveArrayIterator($decodedSpec));
		foreach ($iterSpec as $specVal) {
			if (is_scalar($specVal) && preg_match('/^\d+$/', (string) $specVal)) {
				$specialtyIds[] = (int) $specVal;
			}
		}
	} else {
		preg_match_all('/\d+/', $specialtyRaw, $specMatches);
		foreach ($specMatches[0] ?? [] as $specNum) {
			$specialtyIds[] = (int) $specNum;
		}
	}
	$specialtyIds = array_values(array_unique(array_filter($specialtyIds)));
}
$specialtyParts = [];
if ($specialtyIds !== []) {
	$specialtyKey = implode(',', $specialtyIds);
	if (!isset($specialtyTitlesCache[$specialtyKey])) {
		$specialtyTitlesCache[$specialtyKey] = [];
		try {
			$specDb = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$specQuery = $specDb->getQuery(true)
				->select($specDb->quoteName('title'))
				->from($specDb->quoteName('#__categories'))
				->whereIn($specDb->quoteName('id'), $specialtyIds)
				->order($specDb->quoteName('title') . ' ASC');
			$specDb->setQuery($specQuery);
			$specialtyTitlesCache[$specialtyKey] = array_values(array_filter(array_map('trim', $specDb->loadColumn() ?: [])));
		} catch (\Throwable $e) {
			$specialtyTitlesCache[$specialtyKey] = [];
		}
	}
	$specialtyParts = $specialtyTitlesCache[$specialtyKey];
}
?>
<?php if ($paymentParts !== []) : ?>
	<span class="attr_left3">Способ оплаты: <b><?php echo htmlspecialchars(implode(', ', $paymentParts), ENT_QUOTES, 'UTF-8'); ?></b></span>
<?php endif; ?>
<?php if ($childrenYes) : ?>
	<span class="attr_left3">Можно с детьми</span>
<?php endif; ?>
<?php if ($specialtyParts !== []) : ?>
	<span class="attr_left3 attr_specialties"><?php echo htmlspecialchars(implode(', ', $specialtyParts), ENT_QUOTES, 'UTF-8'); ?></span>
<?php endif; ?>

<?php
defined('_JEXEC') or die;

$srcFields = $listExtraFields ?? $fields ?? $masterFields ?? [];
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
<?php if ($specialtyParts !== []) : ?>
	<span class="category_cinfo-specs"><?php echo htmlspecialchars(implode(', ', $specialtyParts), ENT_QUOTES, 'UTF-8'); ?></span>
<?php endif; ?>

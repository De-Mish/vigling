<?php

\defined('_JEXEC') or die;

/**
 * Quick category filter above the catalog lists: one chip per specialty, "Все" resets it.
 * Expects $categories (id => ['title' / 'filter_title']), $currentCatId and $baseUrl from the list view.
 */

if (empty($categories) || !is_array($categories)) {
	return;
}

$rdChipQuery = [];
foreach ((array) ($_GET ?? []) as $rdKey => $rdValue) {
	if (in_array((string) $rdKey, ['cat_id', 'service', 'tag', 'limitstart', 'start', 'filter_cat_id', 'filter_service', 'filter_tag'], true)) {
		continue;
	}
	$rdChipQuery[$rdKey] = $rdValue;
}

$rdChipUrl = static function (int $catId) use ($baseUrl, $rdChipQuery): string {
	$query = $rdChipQuery;
	if ($catId > 0) {
		$query['cat_id'] = $catId;
	}

	return htmlspecialchars($baseUrl . ($query !== [] ? '?' . http_build_query($query) : ''), ENT_QUOTES, 'UTF-8');
};
?>
<nav class="rd-chips" aria-label="Специальность">
	<a class="rd-chip<?php echo (int) $currentCatId === 0 ? ' is-on' : ''; ?>" href="<?php echo $rdChipUrl(0); ?>">Все</a>
	<?php foreach ($categories as $rdCatId => $rdCat) :
		$rdCatTitle = trim((string) ($rdCat['filter_title'] ?? $rdCat['title'] ?? ''));
		if ($rdCatTitle === '') {
			continue;
		}
		?>
	<a class="rd-chip<?php echo (int) $rdCatId === (int) $currentCatId ? ' is-on' : ''; ?>" href="<?php echo $rdChipUrl((int) $rdCatId); ?>"><?php echo htmlspecialchars($rdCatTitle, ENT_QUOTES, 'UTF-8'); ?></a>
	<?php endforeach; ?>
</nav>

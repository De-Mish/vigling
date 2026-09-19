<?php

defined('_JEXEC') or die;

$text = trim((string) ($serviceRecommendation ?? ''));
if ($text === '') {
	return;
}
$showLabel = $showServiceRecommendationLabel ?? true;
if (!defined('VIGLING_SERVICE_REC_JS')) {
	define('VIGLING_SERVICE_REC_JS', 1);
	?>
	<script>
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest ? e.target.closest('.service-rec-toggle') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		var wrap = btn.closest('.service-rec');
		if (!wrap) {
			return;
		}
		wrap.classList.toggle('is-open');
		btn.setAttribute('aria-expanded', wrap.classList.contains('is-open') ? 'true' : 'false');
	}, true);
	</script>
	<?php
}
?>
<div class="service-rec">
	<button type="button" class="service-rec-toggle" aria-expanded="false" aria-label="Описание услуги"><b></b></button><?php if ($showLabel) : ?>
	<span class="service-rec-label">Описание услуги</span><?php endif; ?>
	<div class="service-rec-body"><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></div>
</div>

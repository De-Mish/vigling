<?php
defined('_JEXEC') or die;

/**
 * Compact owner header of list cards (avatar, name, specialties).
 * Hidden by default; the mobile/PWA card layout in redesign.css shows it.
 *
 * Expects: $mhName, $mhUrl, $mhAvatarStyle, $mhFields.
 */
$mhName = trim((string) ($mhName ?? ''));
$mhUrl = (string) ($mhUrl ?? '#');
$mhAvatarStyle = (string) ($mhAvatarStyle ?? '');
$specialtyParts = [];
$listExtraFields = $mhFields ?? [];
ob_start();
include JPATH_ROOT . '/templates/ryba/html/list-item-specialties.php';
ob_end_clean();
$mhSpecs = implode(', ', (array) $specialtyParts);
?>
<div class="rd-mhead">
	<a class="rd-mhead__ava" href="<?php echo htmlspecialchars($mhUrl, ENT_QUOTES, 'UTF-8'); ?>" tabindex="-1" aria-hidden="true" style="<?php echo $mhAvatarStyle; ?>"></a>
	<span class="rd-mhead__txt">
		<a class="rd-mhead__name" href="<?php echo htmlspecialchars($mhUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($mhName, ENT_QUOTES, 'UTF-8'); ?></a>
		<?php if ($mhSpecs !== '') : ?><span class="rd-mhead__specs"><?php echo htmlspecialchars($mhSpecs, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
	</span>
</div>
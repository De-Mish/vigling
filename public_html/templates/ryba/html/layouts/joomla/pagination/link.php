<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$item = $displayData['data'];
$display = $item->text;
$app = Factory::getApplication();

$icon = null;
$aria = '';
switch ((string) $item->text) {
	case Text::_('JLIB_HTML_START'):
		$icon = 'icon-first';
		$aria = Text::_('JLIB_HTML_GOTO_POSITION_START');
		break;
	case Text::_('JPREV'):
		$icon = 'icon-previous';
		$aria = Text::_('JLIB_HTML_GOTO_POSITION_PREVIOUS');
		break;
	case Text::_('JNEXT'):
		$icon = 'icon-next';
		$aria = Text::_('JLIB_HTML_GOTO_POSITION_NEXT');
		break;
	case Text::_('JLIB_HTML_END'):
		$icon = 'icon-last';
		$aria = Text::_('JLIB_HTML_GOTO_POSITION_END');
		break;
	default:
		$aria = Text::sprintf('JLIB_HTML_GOTO_PAGE', strtolower($item->text));
		break;
}

if ($icon !== null) {
	$display = '<span class="' . $icon . '" aria-hidden="true"></span>';
}

$isActive = !empty($displayData['active']);
$isCurrent = isset($item->active) && $item->active;
$isPageNumber = ($icon === null);

if ($isActive) {
	$link = $app->isClient('site') ? 'href="' . htmlspecialchars($item->link) . '"' : 'href="#" onclick="return false;"';
}
$liClass = $isPageNumber ? ' hidden-phone' : '';
?>
<?php if ($isActive) : ?>
	<li class="<?php echo trim($liClass); ?>">
		<a title="<?php echo htmlspecialchars($item->text); ?>" <?php echo $link; ?> class="pagenav" aria-label="<?php echo htmlspecialchars($aria); ?>"><?php echo $display; ?></a>
	</li>
<?php elseif ($isCurrent) : ?>
	<?php $aria = Text::sprintf('JLIB_HTML_PAGE_CURRENT', strtolower($item->text)); ?>
	<li class="active<?php echo $liClass; ?>" style="color: #F9CE54; font-weight: 900">
		<a class="active" aria-current="true" aria-label="<?php echo htmlspecialchars($aria); ?>"><?php echo $display; ?></a>
	</li>
<?php else : ?>
	<li class="disabled">
		<a><span aria-hidden="true"><?php echo $display; ?></span></a>
	</li>
<?php endif; ?>

<?php
/**
 * @package     Joomla.Site
 * @subpackage  com_fields
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die;

if (!key_exists('field', $displayData))
{
	return;
}

$field = $displayData['field'];
$label = JText::_($field->label);
$value = $field->value;
$showLabel = $field->params->get('showlabel');
$labelClass = $field->params->get('label_render_class');

$items = json_decode($field->rawvalue);





if ($value == '')
{
	return;
}

?>
<?php if ($showLabel == 1) : ?>
	<h2 class="field-label <?php echo $labelClass; ?>"><?php echo htmlentities($label, ENT_QUOTES | ENT_IGNORE, 'UTF-8'); ?>: </h2>
<?php endif; ?>
<section class="cd-faq">
	<div class="cd-faq-items">
		<ul class="cd-faq-group">
			<?php foreach($items as $item) {?>
			<li>
				<a class="cd-faq-trigger" href="#0"><?php echo $item->Вопрос; ?></a>
				<div class="cd-faq-content"><p><?php echo $item->Ответ; ?></p></div>
			</li>
			<?php } ?>
		</ul>
	</div>
</section>

<?php
/**
 * @package     Joomla.Site
 * @subpackage  com_users
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

HTMLHelper::addIncludePath(JPATH_COMPONENT . '/helpers/html');

$fieldsets = $this->form->getFieldsets();

if (isset($fieldsets['core']))
{
	unset($fieldsets['core']);
}

if (isset($fieldsets['params']))
{
	unset($fieldsets['params']);
}
if (isset($fieldsets['actionlogs']))
{
	unset($fieldsets['actionlogs']);
}

$tmp          = isset($this->data->jcfields) ? $this->data->jcfields : array();
$customFields = array();
$profileMainNames = array('firstname', 'lastname', 'telefon', 'sity', 'area', 'street', 'house_number', 'link', 'o_sebe', 'about');

foreach ($tmp as $customField)
{
	$customFields[$customField->name] = $customField;
}

?>
<?php foreach ($fieldsets as $group => $fieldset) : ?>
	<?php $fields = $this->form->getFieldset($group); ?>
	<?php if (count($fields)) : ?>
		<fieldset id="users-profile-custom-<?php echo $group; ?>" class="users-profile-custom-<?php echo $group; ?>">
			<?php if (isset($fieldset->label) && ($legend = trim(Text::_($fieldset->label))) !== '') : ?>
				<legend><?php echo $legend; ?></legend>
			<?php endif; ?>
			<?php if (isset($fieldset->description) && trim($fieldset->description)) : ?>
				<p><?php echo $this->escape(Text::_($fieldset->description)); ?></p>
			<?php endif; ?>
			<dl class="dl-horizontal">
				<?php foreach ($fields as $field) : ?>
					<?php if (!$field->hidden && $field->type !== 'Spacer' && !in_array($field->fieldname, $profileMainNames, true)) : ?>
						<?php
						$isEmpty = false;
						$defaultLabel = Text::_('COM_USERS_PROFILE_VALUE_NOT_FOUND');
						if (key_exists($field->fieldname, $customFields)) {
							$v = trim((string) $customFields[$field->fieldname]->value);
							$isEmpty = ($v === '' || $v === $defaultLabel);
						} elseif (empty($field->value)) {
							$isEmpty = true;
						}
						?>
						<dt>
							<?php echo $field->title; ?>
						</dt>
						<dd class="<?php echo $isEmpty ? 'profile-value-empty' : ''; ?>">
							<?php if (key_exists($field->fieldname, $customFields)) : ?>
								<?php
								$fv = trim((string) $customFields[$field->fieldname]->value);
								$isDefaultLabel = ($fv === $defaultLabel);
								echo ($fv !== '' && !$isDefaultLabel) ? $customFields[$field->fieldname]->value : 'Нет информации';
								?>
							<?php elseif (HTMLHelper::isRegistered('users.' . $field->id)) : ?>
								<?php echo HTMLHelper::_('users.' . $field->id, $field->value); ?>
							<?php elseif (HTMLHelper::isRegistered('users.' . $field->fieldname)) : ?>
								<?php echo HTMLHelper::_('users.' . $field->fieldname, $field->value); ?>
							<?php elseif (HTMLHelper::isRegistered('users.' . $field->type)) : ?>
								<?php echo HTMLHelper::_('users.' . $field->type, $field->value); ?>
							<?php else : ?>
								<?php echo HTMLHelper::_('users.value', $field->value); ?>
							<?php endif; ?>
						</dd>
					<?php endif; ?>
				<?php endforeach; ?>
			</dl>
		</fieldset>
	<?php endif; ?>
<?php endforeach; ?>

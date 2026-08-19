<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

extract($displayData);

if (is_array($value)) {
    $value = implode("\n", array_map(function ($v) {
        return is_scalar($v) ? (string) $v : json_encode($v);
    }, $value));
} else {
    $value = (string) ($value ?? '');
}

$counterlabel = '';
if ($charcounter) {
    $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
    $wa->useScript('short-and-sweet');
    $charcounter = ' charcount';
    $counterlabel = 'data-counter-label="' . $this->escape(Text::_('JFIELD_META_DESCRIPTION_COUNTER')) . '"';
} else {
    $charcounter = '';
}

$attributes = [
    $columns ?: '',
    $rows ?: '',
    !empty($class) ? 'class="form-control ' . $class . $charcounter . '"' : 'class="form-control' . $charcounter . '"',
    !empty($description) ? 'aria-describedby="' . ($id ?: $name) . '-desc"' : '',
    strlen($hint) ? 'placeholder="' . htmlspecialchars($hint, ENT_COMPAT, 'UTF-8') . '"' : '',
    $disabled ? 'disabled' : '',
    $readonly ? 'readonly' : '',
    $onchange ? 'onchange="' . $onchange . '"' : '',
    $onclick ? 'onclick="' . $onclick . '"' : '',
    $required ? 'required' : '',
    !empty($autocomplete) ? 'autocomplete="' . $autocomplete . '"' : '',
    $autofocus ? 'autofocus' : '',
    $spellcheck ? '' : 'spellcheck="false"',
    $maxlength ?: '',
    !empty($counterlabel) ? $counterlabel : '',
    $dataAttribute,
];
?>
<textarea name="<?php
echo $name; ?>" id="<?php
echo $id; ?>" <?php
echo implode(' ', $attributes); ?> ><?php echo htmlspecialchars($value, ENT_COMPAT, 'UTF-8'); ?></textarea>

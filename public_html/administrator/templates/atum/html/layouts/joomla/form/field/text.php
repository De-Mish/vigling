<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

extract($displayData);

if (is_array($value)) {
    $value = implode(', ', array_map(function ($v) {
        return is_scalar($v) ? (string) $v : json_encode($v);
    }, $value));
} else {
    $value = (string) ($value ?? '');
}

$list = '';

if ($options) {
    $list = 'list="' . $id . '_datalist"';
}

$charcounterclass = '';

if ($charcounter) {
    $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
    $wa->useScript('short-and-sweet');
    $charcounterclass = ' charcount';
    $counterlabel = 'data-counter-label="' . $this->escape(Text::_('JFIELD_META_DESCRIPTION_COUNTER')) . '"';
}

$attributes = [
    !empty($class) ? 'class="form-control ' . $class . $charcounterclass . '"' : 'class="form-control' . $charcounterclass . '"',
    !empty($size) ? 'size="' . $size . '"' : '',
    !empty($description) ? 'aria-describedby="' . ($id ?: $name) . '-desc"' : '',
    $disabled ? 'disabled' : '',
    $readonly ? 'readonly' : '',
    $dataAttribute,
    $list,
    strlen($hint) ? 'placeholder="' . htmlspecialchars($hint, ENT_COMPAT, 'UTF-8') . '"' : '',
    $onchange ? 'onchange="' . $onchange . '"' : '',
    !empty($maxLength) ? $maxLength : '',
    $required ? 'required' : '',
    !empty($autocomplete) ? 'autocomplete="' . $autocomplete . '"' : '',
    $autofocus ? 'autofocus' : '',
    $spellcheck ? '' : 'spellcheck="false"',
    !empty($inputmode) ? $inputmode : '',
    !empty($counterlabel) ? $counterlabel : '',
    !empty($pattern) ? 'pattern="' . $pattern . '"' : '',
    !empty($validationtext) ? 'data-validation-text="' . $this->escape(Text::_($validationtext)) . '"' : '',
];

$addonBeforeHtml = '<span class="input-group-text">' . Text::_($addonBefore) . '</span>';
$addonAfterHtml  = '<span class="input-group-text">' . Text::_($addonAfter) . '</span>';
?>

<?php if (!empty($addonBefore) || !empty($addonAfter)) : ?>
<div class="input-group">
<?php endif; ?>

    <?php if (!empty($addonBefore)) : ?>
        <?php echo $addonBeforeHtml; ?>
    <?php endif; ?>

    <input
        type="text"
        name="<?php echo $name; ?>"
        id="<?php echo $id; ?>"
        value="<?php echo htmlspecialchars($value, ENT_COMPAT, 'UTF-8'); ?>"
        <?php echo $dirname; ?>
        <?php echo implode(' ', $attributes); ?>>

    <?php if (!empty($addonAfter)) : ?>
        <?php echo $addonAfterHtml; ?>
    <?php endif; ?>

<?php if (!empty($addonBefore) || !empty($addonAfter)) : ?>
</div>
<?php endif; ?>

<?php if ($options) : ?>
    <datalist id="<?php echo $id; ?>_datalist">
        <?php foreach ($options as $option) : ?>
            <?php if (!$option->value) : ?>
                <?php continue; ?>
            <?php endif; ?>
            <option value="<?php echo $option->value; ?>"><?php echo $option->text; ?></option>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>

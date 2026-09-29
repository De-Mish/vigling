<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Viglin\Component\Pushnotify\Site\Helper\UserNotifyChoices;

if (!class_exists(UserNotifyChoices::class, false)) {
	$choicesHelper = JPATH_SITE . '/components/com_pushnotify/src/Helper/UserNotifyChoices.php';
	if (is_file($choicesHelper)) {
		require_once $choicesHelper;
	}
}
if (!class_exists(UserNotifyChoices::class, false)) {
	return;
}

$choiceUserId = (int) ($this->data->id ?? 0);
$notifyChoices = UserNotifyChoices::get($choiceUserId);
$notifyChoiceAction = Route::_('index.php?option=com_pushnotify&task=display.saveChoices');
$notifyChoiceToken = Session::getFormToken();
?>
<form class="lk-notify-choices" method="post" action="<?php echo $notifyChoiceAction; ?>">
	<fieldset class="lk-notify-choices__group">
		<legend>Какие уведомления получать</legend>
		<?php foreach (UserNotifyChoices::EVENTS as $eventKey => $eventLabel) : ?>
		<label>
			<input type="checkbox" name="notify_events[]" value="<?php echo $this->escape($eventKey); ?>"<?php echo !empty($notifyChoices['events'][$eventKey]) ? ' checked' : ''; ?>>
			<?php echo $this->escape($eventLabel); ?>
		</label>
		<?php endforeach; ?>
	</fieldset>
	<fieldset class="lk-notify-choices__group">
		<legend>В каких блоках</legend>
		<?php foreach (UserNotifyChoices::KINDS as $kindKey => $kindLabel) : ?>
		<label>
			<input type="checkbox" name="notify_kinds[]" value="<?php echo $this->escape($kindKey); ?>"<?php echo !empty($notifyChoices['kinds'][$kindKey]) ? ' checked' : ''; ?>>
			<?php echo $this->escape($kindLabel); ?>
		</label>
		<?php endforeach; ?>
	</fieldset>
	<fieldset class="lk-notify-choices__group">
		<legend>Время уведомления</legend>
		<?php foreach (UserNotifyChoices::REMINDERS as $reminderMinutes => $reminderLabel) : ?>
		<label>
			<input type="checkbox" name="notify_reminders[]" value="<?php echo (int) $reminderMinutes; ?>"<?php echo !empty($notifyChoices['reminders'][(string) $reminderMinutes]) ? ' checked' : ''; ?>>
			<?php echo $this->escape($reminderLabel); ?>
		</label>
		<?php endforeach; ?>
	</fieldset>
	<button type="submit" class="btn btn-xs btn-primary">Сохранить</button>
	<input type="hidden" name="<?php echo $this->escape($notifyChoiceToken); ?>" value="1">
</form>

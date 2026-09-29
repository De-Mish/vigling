<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
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

$choiceUser = Factory::getApplication()->getIdentity();
$choiceUserId = (int) ($choiceUser->id ?? 0);
$notifyChoices = UserNotifyChoices::get($choiceUserId);
$notifyFormId = 'lk-notify-choices';

$notifyChecked = static function (array $row, string $key): string {
	return !empty($row[$key]) ? ' checked' : '';
};
$notifyRemind = static function (array $row): string {
	$remind = $row['remind'] ?? null;

	return $remind === null ? '' : (string) (int) $remind;
};
?>
<div class="lk-notify-settings">
	<div class="lk-notify-subnav" role="tablist">
		<button type="button" class="lk-notify-subnav__btn is-active" data-notify-panel="push" aria-expanded="true">Push-уведомления</button>
		<button type="button" class="lk-notify-subnav__btn" data-notify-panel="inbox" aria-expanded="false">Уведомления профиля</button>
	</div>

	<div class="lk-notify-panel is-open" data-notify-panel="push">
		<div class="lk-notify-grid-wrap">
			<table class="lk-notify-grid">
				<thead>
					<tr>
						<th></th>
						<?php foreach (UserNotifyChoices::EVENTS as $eventKey => $eventLabel) : ?>
						<th scope="col"><?php echo $this->escape($eventLabel); ?></th>
						<?php endforeach; ?>
						<th scope="col">Напоминание о начале за</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach (UserNotifyChoices::KINDS as $kindKey => $kindLabel) : ?>
					<?php $pushRow = $notifyChoices['push'][$kindKey] ?? []; ?>
					<tr>
						<th scope="row"><?php echo $this->escape($kindLabel); ?></th>
						<?php foreach (UserNotifyChoices::EVENTS as $eventKey => $eventLabel) : ?>
						<td>
							<input type="checkbox" form="<?php echo $notifyFormId; ?>" name="push_<?php echo $this->escape($kindKey); ?>_<?php echo $this->escape($eventKey); ?>" value="1" aria-label="<?php echo $this->escape($kindLabel . ', ' . $eventLabel); ?>"<?php echo $notifyChecked($pushRow, $eventKey); ?>>
						</td>
						<?php endforeach; ?>
						<td>
							<span class="lk-notify-remind">
								<select form="<?php echo $notifyFormId; ?>" name="push_<?php echo $this->escape($kindKey); ?>_remind" aria-label="<?php echo $this->escape($kindLabel . ', напоминание о начале за'); ?>">
									<option value=""<?php echo $notifyRemind($pushRow) === '' ? ' selected' : ''; ?>>-</option>
									<?php foreach (UserNotifyChoices::REMINDERS as $minutes => $reminderLabel) : ?>
									<option value="<?php echo (int) $minutes; ?>"<?php echo $notifyRemind($pushRow) === (string) (int) $minutes ? ' selected' : ''; ?>><?php echo $this->escape($reminderLabel); ?></option>
									<?php endforeach; ?>
								</select>
							</span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="lk-notify-panel" data-notify-panel="inbox">
		<div class="lk-notify-grid-wrap">
			<table class="lk-notify-grid">
				<thead>
					<tr>
						<th></th>
						<?php foreach (UserNotifyChoices::EVENTS as $eventLabel) : ?>
						<th scope="col"><?php echo $this->escape($eventLabel); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach (UserNotifyChoices::KINDS as $kindKey => $kindLabel) : ?>
					<?php $inboxRow = $notifyChoices['inbox'][$kindKey] ?? []; ?>
					<tr>
						<th scope="row"><?php echo $this->escape($kindLabel); ?></th>
						<?php foreach (UserNotifyChoices::EVENTS as $eventKey => $eventLabel) : ?>
						<td>
							<input type="checkbox" form="<?php echo $notifyFormId; ?>" name="inbox_<?php echo $this->escape($kindKey); ?>_<?php echo $this->escape($eventKey); ?>" value="1" aria-label="<?php echo $this->escape($kindLabel . ', ' . $eventLabel); ?>"<?php echo $notifyChecked($inboxRow, $eventKey); ?>>
						</td>
						<?php endforeach; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<button type="submit" class="btn btn-xs btn-primary lk-notify-save" form="<?php echo $notifyFormId; ?>">Сохранить</button>
</div>
<script>
(function(){
	var root = document.querySelector('#easyprofile.profile-edit .lk-notify-settings');
	if (!root) return;
	var buttons = root.querySelectorAll('.lk-notify-subnav__btn');
	var panels = root.querySelectorAll('.lk-notify-panel');
	buttons.forEach(function(btn){
		btn.addEventListener('click', function(){
			var name = btn.getAttribute('data-notify-panel');
			buttons.forEach(function(item){
				var on = item === btn;
				item.classList.toggle('is-active', on);
				item.setAttribute('aria-expanded', on ? 'true' : 'false');
			});
			panels.forEach(function(panel){
				panel.classList.toggle('is-open', panel.getAttribute('data-notify-panel') === name);
			});
		});
	});
})();
</script>

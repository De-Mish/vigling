<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$n = $this->notifications;
$email = $this->emailVerification;
$eventLabels = [
	'booking_confirmed' => 'Запись создана/подтверждена',
	'booking_cancelled' => 'Запись отменена',
	'booking_rescheduled' => 'Запись перенесена',
	'booking_reminder' => 'Напоминание до начала',
	'booking_in_30min' => 'Через 30 минут',
	'booking_started' => 'Начало записи',
	'course_created' => 'Курс создан/изменён',
	'course_cancelled' => 'Курс отменён',
	'course_rescheduled' => 'Курс перенесён',
	'booking_finished' => 'Окончание курса/записи',
];
$eventDefaultPreview = [
	'booking_confirmed' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. 27.04.2026 15:30 - 16:30',
	'booking_cancelled' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. 27.04.2026 15:30',
	'booking_rescheduled' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. Новое время: 27.04.2026 15:30 - 16:30',
	'booking_reminder' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. Через 30 минут: 27.04.2026 15:30 - 16:30',
	'booking_in_30min' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. 27.04.2026 15:30 - 16:30',
	'booking_started' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. 27.04.2026 15:30 - 16:30',
	'course_created' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. 27.04.2026 15:30 - 16:30',
	'course_cancelled' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. 27.04.2026 15:30',
	'course_rescheduled' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. Новое время: 27.04.2026 15:30 - 16:30',
	'booking_finished' => 'Курс: Архитектура бровей. Мастер: Анна Иванова. 27.04.2026 15:30 - 16:30',
];
$kindLabels = [
	'service' => 'Обычные услуги',
	'stock' => 'Акции',
	'course' => 'Курсы',
	'journal' => 'Журнал мастера',
];
$checked = static fn ($value): string => !empty($value) ? ' checked' : '';
?>
<form action="<?php echo Route::_('index.php?option=com_pushnotify&view=settings'); ?>" method="post" name="adminForm" id="adminForm">
	<div id="j-main-container" class="j-main-container">
		<div class="d-flex flex-wrap gap-2 mb-3">
			<a class="btn btn-outline-primary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=settings'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_SETTINGS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=subscribers'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_SUBSCRIBERS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=subscriptions'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_TOKENS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=logs'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_LOGS'); ?></a>
		</div>

		<div class="row g-3 mb-3">
			<?php foreach (['tokens' => 'Токенов', 'subscribers' => 'Подписчиков', 'inbox' => 'Inbox', 'logs' => 'Логов'] as $key => $label) : ?>
				<div class="col-sm-6 col-lg-3">
					<div class="card h-100">
						<div class="card-body">
							<div class="text-muted small"><?php echo $this->escape($label); ?></div>
							<div class="fs-3 fw-bold"><?php echo (int) ($this->stats[$key] ?? 0); ?></div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="card mb-3">
			<div class="card-header"><strong><?php echo Text::_('COM_PUSHNOTIFY_GLOBAL_SETTINGS'); ?></strong></div>
			<div class="card-body">
				<div class="row g-3">
					<?php foreach ([
						'enabled' => 'Все уведомления',
						'fcm_enabled' => 'FCM push',
						'inbox_enabled' => 'Inbox',
						'logging_enabled' => 'Логирование отправок',
					] as $key => $label) : ?>
						<div class="col-sm-6 col-lg-3">
							<label class="form-check">
								<input class="form-check-input" type="checkbox" name="jform[notifications][global][<?php echo $key; ?>]" value="1"<?php echo $checked($n['global'][$key] ?? true); ?>>
								<span class="form-check-label"><?php echo $this->escape($label); ?></span>
							</label>
						</div>
					<?php endforeach; ?>
					<div class="col-sm-6 col-lg-3">
						<label class="form-label">Попыток FCM</label>
						<input class="form-control" type="number" min="1" max="10" name="jform[notifications][global][fcm_retry_attempts]" value="<?php echo (int) ($n['global']['fcm_retry_attempts'] ?? 2); ?>">
					</div>
					<div class="col-sm-6 col-lg-3">
						<label class="form-label">Задержка повтора, мс</label>
						<input class="form-control" type="number" min="0" max="10000" step="100" name="jform[notifications][global][fcm_retry_delay_ms]" value="<?php echo (int) ($n['global']['fcm_retry_delay_ms'] ?? 300); ?>">
					</div>
				</div>
			</div>
		</div>

		<div class="card mb-3">
			<div class="card-header"><strong><?php echo Text::_('COM_PUSHNOTIFY_EVENT_SETTINGS'); ?></strong></div>
			<div class="table-responsive">
				<table class="table align-middle mb-0">
					<thead>
						<tr>
							<th>Событие</th>
							<th class="text-center">Вкл.</th>
							<th class="text-center" title="Если включено, уведомление по этому событию отправляется клиенту.">Клиент</th>
							<th class="text-center" title="Если включено, уведомление по этому событию отправляется мастеру.">Мастер</th>
							<th class="text-center" title="FCM push-уведомление: системное уведомление браузера/устройства.">FCM</th>
							<th class="text-center" title="Inbox — внутреннее уведомление в ЛК, отображается в колокольчике.">Inbox (Колокольчик в ЛК)</th>
							<th title="Заголовок уведомления. Используется в FCM и Inbox.">Заголовок</th>
							<th title="Текст уведомления. Если пустой, используется стандартный текст события. Можно использовать переменные вроде {service}, {master}, {client}, {time}.">Шаблон текста</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach (($n['events'] ?? []) as $event => $settings) : ?>
							<tr>
								<td title="Тип события уведомления: <?php echo $this->escape($event); ?>"><?php echo $this->escape($eventLabels[$event] ?? $event); ?></td>
								<td class="text-center" title="Главный переключатель события. Если выключен, событие не отправляется никуда."><input type="checkbox" name="jform[notifications][events][<?php echo $event; ?>][enabled]" value="1"<?php echo $checked($settings['enabled'] ?? true); ?>></td>
								<td class="text-center" title="Отправлять это событие клиенту."><input type="checkbox" name="jform[notifications][events][<?php echo $event; ?>][recipients][client]" value="1"<?php echo $checked($settings['recipients']['client'] ?? true); ?>></td>
								<td class="text-center" title="Отправлять это событие мастеру."><input type="checkbox" name="jform[notifications][events][<?php echo $event; ?>][recipients][master]" value="1"<?php echo $checked($settings['recipients']['master'] ?? true); ?>></td>
								<td class="text-center" title="Отправлять это событие как push через Firebase Cloud Messaging."><input type="checkbox" name="jform[notifications][events][<?php echo $event; ?>][fcm]" value="1"<?php echo $checked($settings['fcm'] ?? true); ?>></td>
								<td class="text-center" title="Создавать запись во внутреннем Inbox, который пользователь видит в колокольчике ЛК."><input type="checkbox" name="jform[notifications][events][<?php echo $event; ?>][inbox]" value="1"<?php echo $checked($settings['inbox'] ?? true); ?>></td>
								<td title="Заголовок уведомления для события «<?php echo $this->escape($eventLabels[$event] ?? $event); ?>»."><input class="form-control" name="jform[notifications][events][<?php echo $event; ?>][title]" value="<?php echo $this->escape((string) ($settings['title'] ?? '')); ?>"></td>
								<td style="min-width: 320px;">
									<textarea class="form-control js-notification-template" rows="3" name="jform[notifications][events][<?php echo $event; ?>][body]" placeholder="{service}, {master}, {client}, {time}"><?php echo $this->escape((string) ($settings['body'] ?? '')); ?></textarea>
									<div class="small text-muted mt-2">Preview:</div>
									<div class="border rounded p-2 js-notification-preview" data-default="<?php echo $this->escape($eventDefaultPreview[$event] ?? 'Курс: Архитектура бровей. Мастер: Анна Иванова. 27.04.2026 15:30 - 16:30'); ?>" style="background: var(--template-bg, rgba(127,127,127,.08)); color: var(--body-color, inherit);"></div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="card-body border-top">
				<div class="alert alert-info mb-3">
					<strong>Как работает «Шаблон текста»:</strong>
					если поле пустое, система использует стандартный текст для события. Если заполнить поле, именно этот текст уйдёт в FCM и Inbox для выбранного события.
				</div>
				<div class="row g-3">
					<div class="col-lg-6">
						<h4 class="h6">Доступные переменные</h4>
						<ul class="mb-0">
							<li><code>{service}</code> — услуга, акция или курс.</li>
							<li><code>{master}</code> — имя мастера.</li>
							<li><code>{client}</code> — имя/email клиента.</li>
							<li><code>{time}</code> или <code>{date}</code> — дата и время записи в часовом поясе получателя.</li>
							<li><code>{reminder}</code> — текст интервала напоминания, например «Через 30 минут».</li>
							<li><code>{recipient}</code> — «Клиент» или «Мастер».</li>
						</ul>
					</div>
					<div class="col-lg-6">
						<h4 class="h6">Примеры</h4>
						<ul class="mb-0">
							<li><code>{service}. Мастер: {master}. {time}</code></li>
							<li><code>{reminder}: {service}, {time}</code></li>
							<li><code>Клиент {client} записан на {service}. Время: {time}</code></li>
						</ul>
					</div>
				</div>
				<h4 class="h6 mt-3">Стандартные тексты, если шаблон пустой</h4>
				<ul class="small mb-0">
					<li>Подтверждение: услуга/курс, мастер, дата и время; мастеру дополнительно показывается клиент.</li>
					<li>Отмена: услуга/курс, мастер или клиент, дата отменённой записи.</li>
					<li>Перенос: услуга/курс и новое время записи.</li>
					<li>Напоминание: интервал напоминания, услуга/курс, мастер и время.</li>
					<li>Начало записи: услуга/курс, мастер и время начала.</li>
				</ul>
				<p class="small text-muted mt-3 mb-0">
					Переменные можно менять местами, удалять и комбинировать с любым текстом. Новые названия переменных без доработки кода не появятся: система заменяет только перечисленные выше маркеры.
				</p>
			</div>
		</div>

		<div class="row g-3">
			<div class="col-lg-6">
				<div class="card h-100">
					<div class="card-header"><strong><?php echo Text::_('COM_PUSHNOTIFY_REMINDER_SETTINGS'); ?></strong></div>
					<div class="card-body">
						<?php foreach (($n['reminders'] ?? []) as $i => $reminder) : ?>
							<div class="row g-2 align-items-center mb-2">
								<div class="col-2"><input class="form-check-input" type="checkbox" name="jform[notifications][reminders][<?php echo $i; ?>][enabled]" value="1"<?php echo $checked($reminder['enabled'] ?? false); ?>></div>
								<div class="col-4"><input class="form-control" type="number" min="0" name="jform[notifications][reminders][<?php echo $i; ?>][minutes]" value="<?php echo (int) ($reminder['minutes'] ?? 0); ?>"></div>
								<div class="col-6"><input class="form-control" name="jform[notifications][reminders][<?php echo $i; ?>][label]" value="<?php echo $this->escape((string) ($reminder['label'] ?? '')); ?>"></div>
							</div>
						<?php endforeach; ?>
						<label class="form-label mt-2">Добавить интервалы, минуты</label>
						<input class="form-control" name="jform[notifications][extra_reminder_minutes]" placeholder="Например: 60, 180">
					</div>
				</div>
			</div>
			<div class="col-lg-6">
				<div class="card h-100">
					<div class="card-header"><strong><?php echo Text::_('COM_PUSHNOTIFY_BOOKING_KIND_SETTINGS'); ?></strong></div>
					<div class="card-body">
						<?php foreach ($kindLabels as $key => $label) : ?>
							<label class="form-check mb-2">
								<input class="form-check-input" type="checkbox" name="jform[notifications][booking_kinds][<?php echo $key; ?>]" value="1"<?php echo $checked($n['booking_kinds'][$key] ?? true); ?>>
								<span class="form-check-label"><?php echo $this->escape($label); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<div class="col-12">
				<div class="card">
					<div class="card-header"><strong><?php echo Text::_('COM_PUSHNOTIFY_EMAIL_SETTINGS'); ?></strong></div>
					<div class="card-body">
						<div class="row g-3">
							<div class="col-sm-6 col-lg-3">
								<label class="form-label">Срок активации, минут</label>
								<input class="form-control" type="number" min="1" name="jform[email_verification][activation_grace_minutes]" value="<?php echo (int) ($email['activation_grace_minutes'] ?? 4320); ?>">
							</div>
							<div class="col-sm-6 col-lg-3">
								<label class="form-label">Жизнь ссылки, дней</label>
								<input class="form-control" type="number" min="1" name="jform[email_verification][token_ttl_days]" value="<?php echo (int) ($email['token_ttl_days'] ?? 30); ?>">
							</div>
							<div class="col-sm-6 col-lg-3">
								<label class="form-label">Cooldown повторной отправки, сек.</label>
								<input class="form-control" type="number" min="1" name="jform[email_verification][resend_cooldown_seconds]" value="<?php echo (int) ($email['resend_cooldown_seconds'] ?? 120); ?>">
							</div>
							<div class="col-sm-6 col-lg-3">
								<label class="form-check mt-4">
									<input class="form-check-input" type="checkbox" name="jform[email_verification][expiration_block_enabled]" value="1"<?php echo $checked($email['expiration_block_enabled'] ?? true); ?>>
									<span class="form-check-label">Блокировать после срока</span>
								</label>
								<label class="form-check">
									<input class="form-check-input" type="checkbox" name="jform[email_verification][resend_enabled]" value="1"<?php echo $checked($email['resend_enabled'] ?? true); ?>>
									<span class="form-check-label">Повторная отправка</span>
								</label>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<input type="hidden" name="task" value="">
		<?php echo HTMLHelper::_('form.token'); ?>
	</div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
	var sample = {
		'{service}': 'Курс: Архитектура бровей',
		'{master}': 'Анна Иванова',
		'{client}': 'Мария Петрова',
		'{time}': '27.04.2026 15:30 - 16:30',
		'{date}': '27.04.2026 15:30 - 16:30',
		'{reminder}': 'Через 30 минут',
		'{recipient}': 'Клиент',
		'{recipient_role}': 'client'
	};
	function renderPreview(textarea) {
		var preview = textarea.parentElement.querySelector('.js-notification-preview');
		if (!preview) {
			return;
		}
		var text = textarea.value.trim();
		if (!text) {
			preview.textContent = preview.getAttribute('data-default') || '';
			return;
		}
		Object.keys(sample).forEach(function (key) {
			text = text.split(key).join(sample[key]);
		});
		preview.textContent = text;
	}
	document.querySelectorAll('.js-notification-template').forEach(function (textarea) {
		renderPreview(textarea);
		textarea.addEventListener('input', function () {
			renderPreview(textarea);
		});
	});
});
</script>

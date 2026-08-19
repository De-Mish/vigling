<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$search = (string) $this->state->get('filter.search', '');
$status = (string) $this->state->get('filter.status', '');
?>
<form action="<?php echo Route::_('index.php?option=com_pushnotify&view=logs'); ?>" method="post" name="adminForm" id="adminForm">
	<div id="j-main-container" class="j-main-container">
		<div class="d-flex flex-wrap gap-2 mb-3">
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=settings'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_SETTINGS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=subscribers'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_SUBSCRIBERS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=subscriptions'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_TOKENS'); ?></a>
			<a class="btn btn-outline-primary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=logs'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_LOGS'); ?></a>
		</div>

		<div class="js-stools clearfix mb-3">
			<div class="js-stools-container-bar d-flex flex-wrap gap-2">
				<input type="text" name="filter_search" value="<?php echo $this->escape($search); ?>" placeholder="ID, пользователь, событие, текст">
				<select name="filter_status">
					<option value="">Все статусы</option>
					<?php foreach (['sent' => 'Отправлено', 'failed' => 'Ошибка', 'pending' => 'Ожидает'] as $key => $label) : ?>
						<option value="<?php echo $key; ?>"<?php echo $status === $key ? ' selected' : ''; ?>><?php echo $this->escape($label); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="btn btn-primary"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
			</div>
		</div>

		<?php if (empty($this->items)) : ?>
			<div class="alert alert-info"><?php echo Text::_('COM_PUSHNOTIFY_NO_LOGS'); ?></div>
		<?php else : ?>
			<div class="table-responsive">
				<table class="table">
					<thead>
						<tr>
							<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
							<th>ID</th>
							<th>Дата</th>
							<th>Пользователь</th>
							<th>Событие</th>
							<th>Получатель</th>
							<th>Статус</th>
							<th>Заголовок</th>
							<th>Текст</th>
							<th>Ответ FCM</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($this->items as $i => $item) : ?>
							<tr>
								<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
								<td><?php echo (int) $item->id; ?></td>
								<td><?php echo $item->sent_at ? HTMLHelper::_('date', $item->sent_at, Text::_('DATE_FORMAT_LC4')) : '—'; ?></td>
								<td>
									<a href="<?php echo Route::_('index.php?option=com_users&task=user.edit&id=' . (int) $item->user_id); ?>">
										<?php echo $this->escape($item->name ?: ($item->username ?: ('#' . $item->user_id))); ?>
									</a>
									<div class="small text-muted"><?php echo $this->escape($item->email ?? ''); ?></div>
								</td>
								<td><?php echo $this->escape($item->notification_type); ?></td>
								<td><?php echo $this->escape($item->recipient_role ?: '—'); ?></td>
								<td><?php echo $this->escape($item->status); ?></td>
								<td><?php echo $this->escape($item->title); ?></td>
								<td class="small text-break"><?php echo $this->escape($item->body); ?></td>
								<td class="small text-break"><?php echo $this->escape(mb_strlen((string) $item->fcm_response) > 160 ? mb_substr((string) $item->fcm_response, 0, 160) . '...' : (string) $item->fcm_response); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php echo $this->pagination->getListFooter(); ?>
		<?php endif; ?>

		<input type="hidden" name="task" value="">
		<?php echo HTMLHelper::_('form.token'); ?>
	</div>
</form>

<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('table.columns')->useScript('multiselect');
$search = (string) $this->state->get('filter.search', '');
?>
<form action="<?php echo Route::_('index.php?option=com_pushnotify&view=subscribers'); ?>" method="post" name="adminForm" id="adminForm">
	<div id="j-main-container" class="j-main-container">
		<div class="d-flex flex-wrap gap-2 mb-3">
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=settings'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_SETTINGS'); ?></a>
			<a class="btn btn-outline-primary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=subscribers'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_SUBSCRIBERS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=subscriptions'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_TOKENS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=logs'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_LOGS'); ?></a>
		</div>
		<div class="js-stools clearfix mb-3">
			<div class="js-stools-container-bar">
				<label for="filter_search" class="visually-hidden"><?php echo Text::_('JSEARCH_FILTER'); ?></label>
				<input type="text" name="filter_search" id="filter_search" value="<?php echo $this->escape($search); ?>" placeholder="ID, имя или email">
				<button type="submit" class="btn btn-primary"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
			</div>
		</div>
		<?php if (empty($this->items)) : ?>
			<div class="alert alert-info"><?php echo Text::_('COM_PUSHNOTIFY_NO_SUBSCRIBERS'); ?></div>
		<?php else : ?>
			<table class="table">
				<thead>
					<tr>
						<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
						<th>ID</th>
						<th><?php echo Text::_('COM_PUSHNOTIFY_USER'); ?></th>
						<th>Email</th>
						<th>Телефон</th>
						<th>Тип</th>
						<th class="text-center">Устройств</th>
						<th>Браузеры/платформы</th>
						<th>Первая подписка</th>
						<th>Последняя активность</th>
						<th>FCM</th>
						<th>Inbox</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($this->items as $i => $item) :
						$userUrl = Route::_('index.php?option=com_users&task=user.edit&id=' . (int) $item->user_id);
						$tokenUrl = Route::_('index.php?option=com_pushnotify&view=subscriptions&filter_search=' . (int) $item->user_id);
					?>
						<tr>
							<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $item->user_id); ?></td>
							<td><?php echo (int) $item->user_id; ?></td>
							<td><a href="<?php echo $userUrl; ?>"><?php echo $this->escape($item->name ?: ($item->username ?: ('#' . $item->user_id))); ?></a></td>
							<td><?php echo $this->escape($item->email ?? '—'); ?></td>
							<td><?php echo $this->escape($item->group_names ?: '—'); ?></td>
							<td>—</td>
							<td class="text-center"><a href="<?php echo $tokenUrl; ?>"><?php echo (int) $item->device_count; ?></a></td>
							<td><?php echo $this->escape(trim(($item->browsers ?: '') . ' ' . ($item->devices ?: '')) ?: '—'); ?></td>
							<td><?php echo $item->first_subscription ? HTMLHelper::_('date', $item->first_subscription, Text::_('DATE_FORMAT_LC4')) : '—'; ?></td>
							<td><?php echo $item->last_activity ? HTMLHelper::_('date', $item->last_activity, Text::_('DATE_FORMAT_LC4')) : '—'; ?></td>
							<td><?php echo ((int) $item->notifications_enabled === 0) ? 'Выключен' : 'Включен'; ?></td>
							<td>Включен</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php echo $this->pagination->getListFooter(); ?>
		<?php endif; ?>
		<input type="hidden" name="task" value="">
		<?php echo HTMLHelper::_('form.token'); ?>
	</div>
</form>

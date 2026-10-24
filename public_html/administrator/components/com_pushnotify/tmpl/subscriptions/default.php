<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$user = Factory::getApplication()->getIdentity();
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('table.columns')->useScript('multiselect');
$search = (string) $this->state->get('filter.search', '');
?>
<form action="<?php echo Route::_('index.php?option=com_pushnotify&view=subscriptions'); ?>" method="post" name="adminForm" id="adminForm">
	<div id="j-main-container" class="j-main-container">
		<div class="d-flex flex-wrap gap-2 mb-3">
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=settings'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_SETTINGS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=subscribers'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_SUBSCRIBERS'); ?></a>
			<a class="btn btn-outline-primary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=subscriptions'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_TOKENS'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_pushnotify&view=logs'); ?>"><?php echo Text::_('COM_PUSHNOTIFY_TAB_LOGS'); ?></a>
		</div>
		<div class="alert alert-info mb-2">
			<?php echo Text::sprintf('COM_PUSHNOTIFY_TOTAL_TOKENS', $this->total); ?>
		</div>
		<div class="js-stools clearfix mb-3">
			<div class="js-stools-container-bar">
				<input type="text" name="filter_search" value="<?php echo $this->escape($search); ?>" placeholder="Пользователь, токен, браузер">
				<button type="submit" class="btn btn-primary"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
			</div>
		</div>
		<?php if (empty($this->items)) : ?>
			<div class="alert alert-info">
				<?php echo Text::_('COM_PUSHNOTIFY_NO_TOKENS'); ?>
			</div>
		<?php else : ?>
			<table class="table">
				<thead>
					<tr>
						<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
						<th scope="col" class="w-1">ID</th>
						<th scope="col"><?php echo Text::_('COM_PUSHNOTIFY_USER'); ?></th>
						<th scope="col"><?php echo Text::_('COM_PUSHNOTIFY_TOKEN'); ?></th>
						<th scope="col"><?php echo Text::_('COM_PUSHNOTIFY_DEVICE'); ?></th>
						<th scope="col"><?php echo Text::_('COM_PUSHNOTIFY_BROWSER'); ?></th>
						<th scope="col"><?php echo Text::_('COM_PUSHNOTIFY_CREATED'); ?></th>
						<th scope="col"><?php echo Text::_('COM_PUSHNOTIFY_LAST_USED'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($this->items as $i => $item) : ?>
						<tr class="row<?php echo $i % 2; ?>">
							<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->id); ?></td>
							<td><?php echo (int) $item->id; ?></td>
							<td><?php echo $this->escape($item->username ?? '#' . $item->user_id); ?></td>
							<td class="small text-break"><?php echo $this->escape(strlen($item->fcm_token) > 40 ? substr($item->fcm_token, 0, 40) . '…' : $item->fcm_token); ?></td>
							<td><?php echo $this->escape($item->device_type ?? '—'); ?></td>
							<td><?php echo $this->escape($item->browser ?? '—'); ?></td>
							<td><?php echo $item->created ? HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC4')) : '—'; ?></td>
							<td><?php echo $item->last_used ? HTMLHelper::_('date', $item->last_used, Text::_('DATE_FORMAT_LC4')) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php echo $this->pagination->getListFooter(); ?>
		<?php endif; ?>
		<?php echo HTMLHelper::_('form.token'); ?>
	</div>
</form>

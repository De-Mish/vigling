<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
?>
<form action="<?php echo Route::_('index.php?option=com_viglingservices&view=services'); ?>" method="post" name="adminForm" id="adminForm">
	<div class="row">
		<div class="col-md-12">
			<div id="j-main-container" class="j-main-container">
				<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
				<div class="alert alert-light border mb-3">
					<div class="row g-2 align-items-end">
						<div class="col-md-4">
							<label for="mass_parent_id" class="form-label"><?php echo Text::_('COM_VIGLINGSERVICES_MASS_PARENT_LABEL'); ?></label>
							<select id="mass_parent_id" name="mass_parent_id" class="form-select">
								<?php foreach ((array) $this->parentOptions as $option) : ?>
									<option value="<?php echo (int) $option->value; ?>"><?php echo $this->escape($option->text); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-8">
							<div class="d-flex flex-wrap gap-2">
								<button type="button" class="btn btn-outline-success" onclick="Joomla.submitbutton('services.massActivate');">
									<?php echo Text::_('COM_VIGLINGSERVICES_BTN_MASS_ACTIVATE'); ?>
								</button>
								<button type="button" class="btn btn-outline-secondary" onclick="Joomla.submitbutton('services.massDeactivate');">
									<?php echo Text::_('COM_VIGLINGSERVICES_BTN_MASS_DEACTIVATE'); ?>
								</button>
								<button type="button" class="btn btn-outline-primary" onclick="Joomla.submitbutton('services.massSortUp');">
									<?php echo Text::_('COM_VIGLINGSERVICES_BTN_MASS_SORT_UP'); ?>
								</button>
								<button type="button" class="btn btn-outline-primary" onclick="Joomla.submitbutton('services.massSortDown');">
									<?php echo Text::_('COM_VIGLINGSERVICES_BTN_MASS_SORT_DOWN'); ?>
								</button>
								<button type="button" class="btn btn-primary" onclick="Joomla.submitbutton('services.massMoveParent');">
									<?php echo Text::_('COM_VIGLINGSERVICES_BTN_MASS_MOVE_PARENT'); ?>
								</button>
							</div>
						</div>
					</div>
				</div>
				<?php if (empty($this->items)) : ?>
					<div class="alert alert-info">
						<span class="icon-info-circle" aria-hidden="true"></span>
						<span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
						<?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
					</div>
				<?php else : ?>
					<table class="table" id="serviceList">
						<thead>
							<tr>
								<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
								<th scope="col" class="w-5"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?></th>
								<th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_VIGLINGSERVICES_FIELD_TITLE_LABEL', 'a.path', $listDirn, $listOrder); ?></th>
								<th scope="col" class="w-10 d-none d-lg-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_VIGLINGSERVICES_FIELD_PARENT_LABEL', 'p.title', $listDirn, $listOrder); ?></th>
								<th scope="col" class="w-10 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_VIGLINGSERVICES_FIELD_TYPE_LABEL', 'a.type', $listDirn, $listOrder); ?></th>
								<th scope="col" class="w-10 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_VIGLINGSERVICES_FIELD_SORT_ORDER_LABEL', 'a.sort_order', $listDirn, $listOrder); ?></th>
								<th scope="col" class="w-10 d-none d-lg-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_VIGLINGSERVICES_FIELD_IS_ACTIVE_LABEL', 'a.is_active', $listDirn, $listOrder); ?></th>
								<th scope="col" class="w-10 d-none d-xl-table-cell"><?php echo Text::_('COM_VIGLINGSERVICES_FIELD_LEGACY_LABEL'); ?></th>
								<th scope="col" class="w-10 d-none d-md-table-cell"><?php echo Text::_('COM_VIGLINGSERVICES_ACTIONS'); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($this->items as $i => $item) : ?>
							<tr>
								<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
								<td><?php echo (int) $item->id; ?></td>
								<td>
									<?php echo str_repeat('&mdash; ', (int) $item->level); ?>
									<a href="<?php echo Route::_('index.php?option=com_viglingservices&task=service.edit&id=' . (int) $item->id); ?>">
										<?php echo $this->escape($item->title); ?>
									</a>
									<div class="small text-muted"><?php echo $this->escape($item->path); ?></div>
								</td>
								<td class="d-none d-lg-table-cell"><?php echo $this->escape($item->parent_title ?: Text::_('COM_VIGLINGSERVICES_PARENT_ROOT')); ?></td>
								<td class="d-none d-md-table-cell"><?php echo Text::_('COM_VIGLINGSERVICES_TYPE_' . strtoupper((string) $item->type)); ?></td>
								<td class="d-none d-md-table-cell"><?php echo (int) $item->sort_order; ?></td>
								<td class="d-none d-lg-table-cell">
									<?php echo (int) $item->is_active ? Text::_('JPUBLISHED') : Text::_('JUNPUBLISHED'); ?>
								</td>
								<td class="d-none d-xl-table-cell">
									<?php if (!empty($item->legacy_source) || !empty($item->legacy_id)) : ?>
										<?php echo $this->escape(trim((string) $item->legacy_source . ':' . (string) $item->legacy_id, ':')); ?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td class="d-none d-md-table-cell">
									<a href="<?php echo Route::_('index.php?option=com_viglingservices&task=service.edit&id=' . (int) $item->id); ?>"><?php echo Text::_('JACTION_EDIT'); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php echo $this->pagination->getListFooter(); ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<input type="hidden" name="task" value="">
	<input type="hidden" name="boxchecked" value="0">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>

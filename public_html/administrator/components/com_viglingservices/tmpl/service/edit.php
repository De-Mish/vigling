<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')->useScript('form.validate');
?>
<form action="<?php echo Route::_('index.php?option=com_viglingservices&layout=edit&id=' . (int) $this->item->id); ?>" method="post" name="adminForm" id="service-form" class="form-validate">
	<div class="main-card">
		<div class="row">
			<div class="col-lg-8">
				<?php echo $this->form->renderField('id'); ?>
				<?php echo $this->form->renderField('title'); ?>
				<?php echo $this->form->renderField('parent_id'); ?>
				<?php echo $this->form->renderField('type'); ?>
				<?php echo $this->form->renderField('sort_order'); ?>
				<?php echo $this->form->renderField('is_active'); ?>
			</div>
			<div class="col-lg-4">
				<?php echo $this->form->renderField('slug'); ?>
				<?php echo $this->form->renderField('path'); ?>
				<?php echo $this->form->renderField('level'); ?>
				<?php echo $this->form->renderField('legacy_source'); ?>
				<?php echo $this->form->renderField('legacy_id'); ?>
			</div>
		</div>
	</div>
	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>

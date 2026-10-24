<?php

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$list = $displayData['list'];

?>
<div class="pagination__wrap" style="clear:both">
	<ul class="pagination">
		<?php echo $list['start']['data']; ?>
		<?php echo $list['previous']['data']; ?>
		<?php foreach ($list['pages'] as $page) : ?>
			<?php echo $page['data']; ?>
		<?php endforeach; ?>
		<?php echo $list['next']['data']; ?>
		<?php echo $list['end']['data']; ?>
	</ul>
</div>

<?php
/**
 * @package     Joomla.Site
 * @subpackage  com_users
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

?>
<div class="registration-complete<?php echo $this->pageclass_sfx; ?>">
	<?php if ($this->params->get('show_page_heading')) : ?>
	<div class="container header-bot">
		<h1><?php echo $this->escape($this->params->get('page_heading')); ?></h1>
		<div class="clearFloat"></div>
    </div>
	<?php endif; ?>
	
	<script language="JavaScript" type="text/javascript">
	function GoHome(){location="/index.php?option=com_users&view=login";} 
	setTimeout( 'GoHome()', 100 ); 
	</script>
</div>

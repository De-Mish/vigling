<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_wrapper
 *
 * @copyright   Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

JHtml::_('script', 'com_wrapper/iframe-height.min.js', array('version' => 'auto', 'relative' => true));
?>
<iframe onload="iFrameHeight<?php echo $id; ?>(this)"
	target="_parent"
	id="blockrandom-<?php echo $id; ?>"
	name="<?php echo $target; ?>"
	src="<?php echo $url; ?>"
	width="<?php echo $width; ?>"
	height="<?php echo $height; ?>"
	scrolling="<?php echo $scroll; ?>"
	frameborder="<?php echo $frameborder; ?>"
	title="<?php echo $ititle; ?>"
	class="wrapper<?php echo $moduleclass_sfx; ?>" >
	<?php echo JText::_('MOD_WRAPPER_NO_IFRAMES'); ?>
</iframe>
<script> 
jQuery(window).load(function(){	
	setInterval(function(){ iFrameHeight<?php echo $id; ?>(document.getElementById("blockrandom-<?php echo $id; ?>")); }, 500);
});
function iFrameHeight<?php echo $id; ?>(iframe){var doc="contentDocument"in iframe?iframe.contentDocument:iframe.contentWindow.document;var height=parseInt(doc.body.scrollHeight);if(!document.all){iframe.style.height=parseInt(height)+60+"px"}else if(document.all&&iframe.id){document.all[iframe.id].style.height=parseInt(height)+20+"px"}}
</script>

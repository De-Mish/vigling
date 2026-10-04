<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_articles_latest
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;



?>
<ul class="latestnews<?php echo $moduleclass_sfx; ?> mod-list">
<?php foreach ($list as $item) : 
$img = json_decode($item->images);
?>
	<li itemscope itemtype="https://schema.org/Article">
		<a href="<?php echo $item->link; ?>" itemprop="url">
			<img src="<?php echo $img->image_intro; ?>" alt="<?php echo $img->image_intro_alt; ?>" />
		</a>
		<a href="<?php echo $item->link; ?>" itemprop="url">
			<span itemprop="name">
				<?php echo $item->title; ?>
			</span>
		</a>
		<div class="publish"><?php echo date('d-m-Y', strtotime ($item->publish_up));?></div>
	</li>
<?php endforeach; ?>
</ul>



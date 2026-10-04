<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_articles_popular
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;
function strip_txt ($txt) {
	$string = strip_tags($txt);
	$string = substr($string, 0, 200);
	$string = rtrim($string, "!,.-");
	$string = substr($string, 0, strrpos($string, ' '));
	echo $string."… ";
};
?>

<h2>Популярные статьи</h2>
<?php if (!empty($list)) { ?>
<div class="news-slider <?php echo $moduleclass_sfx; ?>">
<?php foreach ($list as $item) : 

$img = json_decode($item->images);
if (empty($img->image_intro)) { 
	$postimg = '/templates/ryba/images/toppost_default_img.png';
} else { 
	$postimg = $img->image_intro;
};

?>
	<div class="news-item">
		<div style="background-image: url('<?php echo $postimg; ?>')" class="news__img">
			<div>
				<a href="<?php echo $item->link; ?>"><?php echo $item->title; ?></a>
			</div>
		</div>
		<p><?php echo strip_txt($item->introtext) ?></p>
		<a class="readmore" href="<?php echo $item->link; ?>">Читать дальше</a>
	</div>            
<?php endforeach; ?>                   
</div>
<?php } else {?>
<p>Скоро здесь появятся популярные статьи</p>
<?php } ?>

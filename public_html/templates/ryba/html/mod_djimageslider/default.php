<?php
/**
 * @version $Id$
 * @package DJ-ImageSlider
 * @subpackage DJ-ImageSlider Component
 * @copyright Copyright (C) 2017 DJ-Extensions.com, All rights reserved.
 * @license http://www.gnu.org/licenses GNU/GPL
 * @author url: http://dj-extensions.com
 * @author email contact@dj-extensions.com
 * @developer Szymon Woronowski - szymon.woronowski@design-joomla.eu
 *
 *
 * DJ-ImageSlider is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * DJ-ImageSlider is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with DJ-ImageSlider. If not, see <http://www.gnu.org/licenses/>.
 *
 */

// no direct access
defined('_JEXEC') or die ('Restricted access');

$wcag = $params->get('wcag', 1) ? ' tabindex="0"' : '';

// Customization parameters
$title_color = $params->get('title_color');
$desc_color = $params->get('desc_color');
$readmore_color = $params->get('readmore_color');
$desc_bg = $params->get('desc_bg');

$title_font = $params->get('title_font', '');
$desc_font = $params->get('desc_font', '');
$readmore_font = $params->get('readmore_font', '');

$title_size =  $params->get('title_size', '');
$desc_size =  $params->get('desc_size', '');
$readmore_size =  $params->get('readmore_size', '');


$title_style = (($title_color) ? 'color:' . $title_color . ';' : '') . (($title_font != '') ? 'font-family:\'' . $title_font . '\';' : '') . (($title_size != '') ? 'font-size: ' . $title_size . ';' : '');
$desc_style = (($desc_color) ? 'color:' . $desc_color . ';' : '') . (($desc_font != '') ? 'font-family:\'' . $desc_font . '\';' : '') . (($desc_size != '') ? 'font-size: ' . $desc_size . ';' : '');
$readmore_style = (($readmore_color) ? 'color:' . $readmore_color . ';' : '') . (($readmore_font != '') ? 'font-family:\'' . $readmore_font . '\';' : '') . (($readmore_size != '') ? 'font-size: ' . $readmore_size . ';' : '');

$border_radius = $params->get('border_radius', '0px 0px 0px 0px;');

?>
        	
<?php foreach ($slides as $slide) {

	if(isset($slide->params)) {
		$link_element = $slide->params->get('link_element', '');
	} else {
		$link_element = '';
	}
	$rel = (!empty($slide->rel) ? 'rel="'.$slide->rel.'"':''); ?>
	<div class="slider__home-item" style="background-image: url('<?php echo $slide->image?>')">
		<?php if($params->get('slider_source') && ($params->get('show_title') || ($params->get('show_desc') && !empty($slide->description) || ($params->get('show_readmore') && $slide->link)))) { ?>
			<?php if($params->get('show_desc')) { ?>
					<?php if($params->get('link_desc') && $slide->link) { ?>
					<a href="<?php echo $slide->link; ?>" style="<?php echo $desc_style ?>" target="<?php echo $slide->target; ?>" <?php echo $rel; ?>>
						<?php echo strip_tags($slide->description,"<br><span><em><i><b><strong><small><big>"); ?>
					</a>
					<?php } else { ?>
						<?php echo $slide->description; ?>
					<?php } ?>
			<?php } ?>
		<?php } ?>
	</div>
<?php } ?>


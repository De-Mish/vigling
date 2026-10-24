<?php
/**
* @copyright	Copyright (C) 2013 Jsn Project company. All rights reserved.
* @license		http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
* @package		Easy Profile
* website		www.easy-profile.com
* Technical Support : Forum -	http://www.easy-profile.com/support.html
*/

defined('_JEXEC') or die;

$jsnConfig=JComponentHelper::getParams('com_jsn');

$doc = JFactory::getDocument();
if($jsnConfig->get('bootstrap',0)) {
	$doc->addStylesheet(JURI::root().'media/jui/css/bootstrap.min.css');
	$dir = $doc->direction;
	if($dir=='rtl')
	{
		$doc->addStylesheet(JURI::root().'media/jui/css/bootstrap-rtl.css');
	}
}

$cols=$params->get('num_columns', 1);
$span=12/$cols;
$countUsers=0;

global $JSNLIST_DISPLAYED_ID;
?>

<div class="jsn-mod-userslist<?php echo $moduleclass_sfx; ?>">
	<div class="jsn-list">
	<?php
	if(is_array($list)) foreach($list as $item)
	{
		$JSNLIST_DISPLAYED_ID=$item->id;
		$user = JsnHelper::getUser($item->id);
		if(($countUsers%$cols)==0) echo('<div class="jsn-l-row">');
	?>
		<div class="jsn-l-w<?php echo $span; ?> jsn-l profile<?php echo substr(md5($user->username),0,10) ?>">
			<!-- Top Container -->
			<div class="jsn-l-top <?php echo ($jsnConfig->get('avatar',1) ? 'jsn-l-top-a' : ''); ?>">

				<!-- Avatar Container -->
				<?php
					if($jsnConfig->get('avatar',1)) :
				?> 
					<div class="jsn-l-avatar">
						<a href="<?php echo $user->getLink(array('Itemid'=>$params->get('profile_menuid', ''))); ?>">
						<?php
							echo $user->getField('avatar_mini',true);
						?>
						</a>
					</div>
				<?php
					endif;
				?>

				<!-- Title Container -->
				<div class="jsn-l-title">
					<h3>
						<a href="<?php echo $user->getLink(array('Itemid'=>$params->get('profile_menuid', ''))); ?>">
						<?php echo $user->getField('formatname'); ?>
						</a>
					</h3>

					<?php if($jsnConfig->get('status',1)) : ?>	
						<?php echo $user->getField('status'); ?>
					<?php endif; ?>
				</div>

				<div class="jsn-l-fields">
					<?php 
					$fields=$params->def('list_fields', array());
					if(is_array($fields)) foreach($fields as $field) : ?>
						<?php $value=$user->getField($field,true); if(!empty($value)) : ?>
						<div class="<?php echo $field ?>"><?php if($params->def('show_titles', 0)) : ?><span class="jsn-l-field-title"><?php echo JText::_($fields_title[$field]['title']); ?>: </span><?php endif; ?><span class="jsn-l-field-value"><?php  echo $value; ?></span></div>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>	
			</div>
		</div>
	<?php
		if(($countUsers%$cols)==($cols-1)) echo('</div>');
		$countUsers+=1;
	}
	if(($countUsers%$cols)!=0) echo('</div>');
	$JSNLIST_DISPLAYED_ID=false;
	?>
	</div>
</div>

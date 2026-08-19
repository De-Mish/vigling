<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

$app     = Factory::getApplication();
$input   = $app->getInput();
$sitename = htmlspecialchars($app->get('sitename'), ENT_QUOTES, 'UTF-8');
$tpl     = $this->template;
$base    = Uri::root(true) . '/templates/' . $tpl;
$option  = $input->getCmd('option', '');
$view    = $input->getCmd('view', '');
$layout  = $input->getCmd('layout', '');
$task    = $input->getCmd('task', '');
$itemid  = $input->getCmd('Itemid', '');
$format  = $input->getCmd('format', 'html');

$this->getWebAssetManager()
     ->registerAndUseStyle('ryba.error', $base . '/css/style.css');
?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
	<meta charset="utf-8">
	<title><?php echo $this->title; ?> <?php echo htmlspecialchars($this->error->getMessage(), ENT_QUOTES, 'UTF-8'); ?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="<?php echo $base; ?>/css/style.css">
	<link rel="icon" href="<?php echo $base; ?>/favicon.png" type="image/png">
</head>
<body class="site error-page">
	<div class="container">
		<header class="header">
			<a class="brand" href="<?php echo Uri::root(); ?>"><?php echo $sitename; ?></a>
		</header>
		<div id="content">
			<h1 class="page-header"><?php echo Text::_('JERROR_LAYOUT_PAGE_NOT_FOUND'); ?></h1>
			<div class="well">
				<p><strong><?php echo Text::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST'); ?></strong></p>
				<p><?php echo Text::_('JERROR_LAYOUT_NOT_ABLE_TO_VISIT'); ?></p>
				<blockquote>
					<span class="label"><?php echo $this->error->getCode(); ?></span> <?php echo htmlspecialchars($this->error->getMessage(), ENT_QUOTES, 'UTF-8'); ?>
				</blockquote>
				<p><a href="<?php echo Uri::root(); ?>" class="btn"><?php echo Text::_('JERROR_LAYOUT_GO_TO_THE_HOME_PAGE'); ?></a></p>
			</div>
		</div>
		<footer>
			<p>&copy; <?php echo date('Y'); ?> <?php echo $sitename; ?></p>
		</footer>
	</div>
</body>
</html>

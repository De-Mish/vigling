<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\AuthenticationHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

$app      = Factory::getApplication();
$sitename = htmlspecialchars($app->get('sitename'), ENT_QUOTES, 'UTF-8');
$tpl      = $this->template;
$base     = Uri::root(true) . '/templates/' . $tpl;

$this->getWebAssetManager()
     ->registerAndUseStyle('ryba.offline', $base . '/css/offline.css')
     ->registerAndUseStyle('ryba.style', $base . '/css/style.css');
?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
	<meta charset="utf-8">
	<title><?php echo Text::_('JOFFLINE_TITLE'); ?> - <?php echo $sitename; ?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="<?php echo $base; ?>/css/style.css">
	<link rel="stylesheet" href="<?php echo $base; ?>/css/offline.css">
	<link rel="icon" href="<?php echo $base; ?>/favicon.png" type="image/png">
</head>
<body class="site offline">
	<div class="outer">
		<div class="middle">
			<div class="inner well">
				<div class="header">
					<h1><?php echo $sitename; ?></h1>
					<?php if ($app->get('display_offline_message', 1) == 1 && trim($app->get('offline_message')) !== '') : ?>
						<p><?php echo $app->get('offline_message'); ?></p>
					<?php elseif ($app->get('display_offline_message', 1) == 2) : ?>
						<p><?php echo Text::_('JOFFLINE_MESSAGE'); ?></p>
					<?php endif; ?>
				</div>
				<jdoc:include type="message" />
				<form action="<?php echo Route::_('index.php', true); ?>" method="post" id="form-login">
					<fieldset>
						<label for="username"><?php echo Text::_('JGLOBAL_USERNAME'); ?></label>
						<input name="username" id="username" type="text" title="<?php echo Text::_('JGLOBAL_USERNAME'); ?>">
						<label for="password"><?php echo Text::_('JGLOBAL_PASSWORD'); ?></label>
						<input type="password" name="password" id="password" title="<?php echo Text::_('JGLOBAL_PASSWORD'); ?>">
						<?php if (count(AuthenticationHelper::getTwoFactorMethods()) > 1) : ?>
							<label for="secretkey"><?php echo Text::_('JGLOBAL_SECRETKEY'); ?></label>
							<input type="text" name="secretkey" id="secretkey" title="<?php echo Text::_('JGLOBAL_SECRETKEY'); ?>">
						<?php endif; ?>
						<input type="submit" name="Submit" class="btn btn-primary" value="<?php echo Text::_('JLOGIN'); ?>">
						<input type="hidden" name="option" value="com_users">
						<input type="hidden" name="task" value="user.login">
						<input type="hidden" name="return" value="<?php echo base64_encode(Uri::base()); ?>">
						<?php echo HTMLHelper::_('form.token'); ?>
					</fieldset>
				</form>
			</div>
		</div>
	</div>
</body>
</html>

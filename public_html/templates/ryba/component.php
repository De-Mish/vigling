<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

$app = Factory::getApplication();
$wa  = $this->getWebAssetManager();
$tpl = $this->template;
$base = Uri::root(true) . '/templates/' . $tpl;

$wa->registerAndUseStyle('ryba.style', $base . '/css/style.css')
   ->registerAndUseStyle('ryba.style-ext', $base . '/css/style-ext.css');
?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<jdoc:include type="metas" />
	<jdoc:include type="styles" />
	<jdoc:include type="scripts" />
</head>
<body class="contentpane component">
	<jdoc:include type="message" />
	<jdoc:include type="component" />
</body>
</html>

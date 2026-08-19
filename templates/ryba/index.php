<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;

$app   = Factory::getApplication();
$input = $app->getInput();
$tpl   = $this->template;
$tplPath = rtrim(Uri::root(), '/') . '/templates/' . $tpl . '/';
$templateParams = $app->getTemplate(true)->params;

$option   = $input->getCmd('option', '');
$view     = $input->getCmd('view', '');
$layout   = $input->getCmd('layout', '');
$task     = $input->getCmd('task', '');
$itemid   = $input->getCmd('Itemid', '');
$sitename = htmlspecialchars($app->get('sitename'), ENT_QUOTES, 'UTF-8');
$menu     = $app->getMenu()->getActive();
$pageclass = $menu !== null ? $menu->getParams()->get('pageclass_sfx', '') : '';

$isHome = ($menu && (int) $menu->home === 1);
$page  = $isHome ? 'home' : 'page';
if ($option === 'com_poisk' || $option === 'com_specialists' || $option === 'com_kurs') {
	$page = 'page';
}
if ($option === 'com_users' && $view === 'profile') {
	$page = 'page';
}
$isPwaInstallPage = (int) $input->getInt('pwa_install', 0) === 1;
$requestPath = trim((string) Uri::getInstance()->getPath(), '/');
$isApplicationGuidePage = ((int) $input->getInt('app_install_guide', 0) === 1)
	|| in_array(strtolower($requestPath), ['priloshenie'], true);
$contactsPageHtml = (string) $templateParams->get('contacts_page_html', '');
$isContactsPage = ((int) $input->getInt('contacts_page', 0) === 1)
	|| $option === 'com_contact'
	|| in_array(strtolower($requestPath), ['kontakty', 'contacts'], true);
if ($isApplicationGuidePage) {
	$page = 'page';
}
if ($isContactsPage) {
	$page = 'page';
}

$this->setMetaData('viewport', 'width=device-width, initial-scale=1, maximum-scale=1');
?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
	<meta content="width=device-width, initial-scale=1, maximum-scale=1" name="viewport">
	<meta content="IE=edge" http-equiv="X-UA-Compatible">
	<link rel="icon" type="image/png" sizes="192x192" href="<?php echo rtrim(Uri::root(), '/'); ?>/icons/icon-192.png">
	<link rel="apple-touch-icon" sizes="180x180" href="<?php echo rtrim(Uri::root(), '/'); ?>/icons/apple-touch-icon.png">
	<meta name="theme-color" content="#111111">
	<link rel="manifest" href="<?php echo rtrim(Uri::root(), '/'); ?>/manifest.json">
	<script src="<?php echo $tplPath; ?>js/jquery.min.js"></script>
	<script src="<?php echo $tplPath; ?>js/slick.min.js"></script>
	<script src="<?php echo $tplPath; ?>js/scripts.js"></script>
	<script src="<?php echo $tplPath; ?>js/custom.js"></script>
	<script src="https://code.jquery.com/jquery-migrate-1.4.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.16.1/umd/popper.min.js"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-datetimepicker/2.5.20/jquery.datetimepicker.full.min.js"></script>
	<jdoc:include type="metas" />
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.css">
	<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-datetimepicker/2.5.20/jquery.datetimepicker.min.css">
	<link rel="stylesheet" href="<?php echo $tplPath; ?>css/slick.css">
	<link rel="stylesheet" href="<?php echo $tplPath; ?>css/slick-theme.css">
	<link rel="stylesheet" href="<?php echo $tplPath; ?>css/tabs.min.css">
	<link rel="stylesheet" href="<?php echo $tplPath; ?>css/font-awesome.min.css">
	<link rel="stylesheet" href="<?php echo $tplPath; ?>css/style.css">
	<link rel="stylesheet" href="<?php echo $tplPath; ?>css/style-ext.css">
	<jdoc:include type="styles" />
</head>
<body id="<?php echo $page; ?>" class="d-flex flex-column site <?php echo $option . ' view-' . $view . ($layout ? ' layout-' . $layout : '') . ($task ? ' task-' . $task : '') . ($itemid ? ' itemid-' . $itemid : '') . ($pageclass ? ' ' . $pageclass : ''); ?>">
	<header class="header header--desktop<?php echo $page !== 'home' ? ' single-header no_shadow' : ''; ?>">
		<div class="container d-flex">
			<div class="header__menu">
				<?php if ($this->countModules('topmenu')) : ?>
					<jdoc:include type="modules" name="topmenu" style="none" />
				<?php else :
					$jmenu = $app->getMenu();
					$default = $jmenu->getDefault();
					if ($default) :
						$topItems = $jmenu->getItems(['menutype', 'parent_id', 'published'], [$default->menutype, 1, 1]);
						$user = $app->getIdentity();
						$levels = $user ? $user->getAuthorisedViewLevels() : [];
						$topItems = $topItems ? array_filter($topItems, function ($it) use ($levels) {
							return in_array((int) $it->access, $levels, true);
						}) : [];
						if (!empty($topItems)) : ?>
					<nav class="jmoddiv jmodinside" id="mod-menu-ryba-fallback">
						<ul class="mod-menu mod-list nav">
							<?php foreach ($topItems as $mitem) :
								$href = ($mitem->type === 'url' && $mitem->params->get('url')) ? $mitem->params->get('url') : Route::_($mitem->link);
								$class = 'nav-item item-' . $mitem->id;
								if ($menu && (int) $menu->id === (int) $mitem->id) $class .= ' current';
							?>
							<li class="<?php echo $class; ?>">
								<a href="<?php echo htmlspecialchars($href); ?>"><?php echo htmlspecialchars($mitem->title); ?></a>
							</li>
							<?php endforeach; ?>
						</ul>
					</nav>
						<?php endif;
					endif;
				endif; ?>
			</div>
			<div class="header__logo">
				<a href="<?php echo Uri::root(); ?>"><img class="header__logo-img" src="/images/logo.jpg" width="65" alt="Лого Vigling.ru"><?php echo $sitename; ?></a>
			</div>
		</div>
	</header>
	<header class="header header--mobile" id="header-mobile" aria-hidden="false">
		<div class="header-mobile__bar">
			<a class="header-mobile__logo" href="<?php echo Uri::root(); ?>">
				<img src="/images/logo.jpg" width="48" height="48" alt="Лого Vigling.ru">
				<span class="header-mobile__sitename"><?php echo $sitename; ?></span>
			</a>
			<button type="button" class="header-mobile__toggle" id="header-mobile-toggle" aria-label="<?php echo htmlspecialchars($app->getLanguage()->_('JTOGGLE_NAVIGATION') ?: 'Меню'); ?>" aria-expanded="false" aria-controls="header-mobile-panel">
				<span class="header-mobile__toggle-bar"></span>
				<span class="header-mobile__toggle-bar"></span>
				<span class="header-mobile__toggle-bar"></span>
			</button>
		</div>
		<div class="header-mobile__overlay" id="header-mobile-overlay" aria-hidden="true"></div>
		<div class="header-mobile__panel" id="header-mobile-panel" aria-hidden="true">
			<div class="header-mobile__panel-head">
				<button type="button" class="header-mobile__close" id="header-mobile-close" aria-label="<?php echo htmlspecialchars($app->getLanguage()->_('JCLOSE') ?: 'Закрыть'); ?>">&times;</button>
			</div>
			<div class="header-mobile__panel-body">
			<nav class="header-mobile__nav">
				<?php if ($this->countModules('topmenu')) : ?>
					<jdoc:include type="modules" name="topmenu" style="none" />
				<?php else :
					$jmenu = $app->getMenu();
					$default = $jmenu->getDefault();
					if ($default) :
						$topItems = $jmenu->getItems(['menutype', 'parent_id', 'published'], [$default->menutype, 1, 1]);
						$user = $app->getIdentity();
						$levels = $user ? $user->getAuthorisedViewLevels() : [];
						$topItems = $topItems ? array_filter($topItems, function ($it) use ($levels) {
							return in_array((int) $it->access, $levels, true);
						}) : [];
						if (!empty($topItems)) : ?>
				<ul class="mod-menu mod-list nav">
					<?php foreach ($topItems as $mitem) :
						$href = ($mitem->type === 'url' && $mitem->params->get('url')) ? $mitem->params->get('url') : Route::_($mitem->link);
						$class = 'nav-item item-' . $mitem->id;
						if ($menu && (int) $menu->id === (int) $mitem->id) $class .= ' current';
					?>
					<li class="<?php echo $class; ?>">
						<a href="<?php echo htmlspecialchars($href); ?>"><?php echo htmlspecialchars($mitem->title); ?></a>
					</li>
					<?php endforeach; ?>
				</ul>
						<?php endif;
					endif;
				endif; ?>
			</nav>
			</div>
		</div>
	</header>
	<?php if ($isPwaInstallPage) : ?>
	<div class="pwa-install-page" id="pwa-install-overlay" role="dialog" aria-modal="true" aria-labelledby="pwa-install-title">
		<div class="pwa-install-backdrop" data-close-pwa-install="1"></div>
		<div class="pwa-install-card">
			<button type="button" class="pwa-install-close" data-close-pwa-install="1" aria-label="Закрыть">&times;</button>
			<h1 id="pwa-install-title">Установить приложение Vigling</h1>
			<p>Нажмите кнопку ниже. Если браузер поддерживает установку PWA, появится системное окно добавления приложения.</p>
			<div class="pwa-install-actions">
				<button type="button" id="pwa-install-btn" class="btn btn__time-zapis">Установить приложение</button>
				<a class="pwa-install-back" href="<?php echo htmlspecialchars(rtrim(Uri::root(), '/') . '/'); ?>">На главную</a>
			</div>
			<div id="pwa-install-status" class="pwa-install-status"></div>
		</div>
	</div>
	<?php endif; ?>
	<?php if ($page === 'home' && $this->countModules('slider')) : ?>
		<section class="slider__home slider-container">
			<jdoc:include type="modules" name="slider" />
		</section>
	<?php endif; ?>
	<?php if ($page !== 'home') : ?>
		<?php $contentClass = ($option === 'com_users' && $view === 'profile') ? 'content content__single single__master view-profile view_profile' : 'content content__single single__master'; ?>
		<section id="content" class="<?php echo $contentClass; ?>" role="main">
			<div class="container">
				<?php if ($this->countModules('breadcrumbs')) : ?>
					<jdoc:include type="modules" name="breadcrumbs" style="none" />
				<?php elseif ($option === 'com_users' && $view === 'profile') : ?>
					<?php
					$profileUserId = $input->getInt('user_id', 0);
					$profileBreadName = 'Профиль';
					$profileBreadExtra = null;
					if ($profileUserId > 0) {
						$profileUser = User::getInstance($profileUserId);
						if ($profileUser && $profileUser->id) {
							$profileBreadExtra = $profileUser->name;
						}
					}
					$rootUrl = rtrim(Uri::root(), '/') . '/';
					$profileUrl = Route::_('index.php?option=com_users&view=profile');
					?>
					<div aria-label="breadcrumbs" role="navigation">
						<ul itemscope itemtype="https://schema.org/BreadcrumbList" class="breadcrumb breadcrumbs">
							<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
								<a itemprop="item" href="<?php echo htmlspecialchars($rootUrl); ?>" class="pathway"><span itemprop="name">Главная страница</span></a>
								<meta itemprop="position" content="1">
							</li>
							<?php if ($profileBreadExtra) : ?>
							<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
								<a itemprop="item" href="<?php echo htmlspecialchars($profileUrl); ?>" class="pathway"><span itemprop="name"><?php echo $profileBreadName; ?></span></a>
								<meta itemprop="position" content="2">
							</li>
							<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem" class="active">
								<span itemprop="name"><?php echo htmlspecialchars($profileBreadExtra); ?></span>
								<meta itemprop="position" content="3">
							</li>
							<?php else : ?>
							<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem" class="active">
								<span itemprop="name"><?php echo $profileBreadName; ?></span>
								<meta itemprop="position" content="2">
							</li>
							<?php endif; ?>
						</ul>
					</div>
				<?php endif; ?>
				<jdoc:include type="message" />
				<?php $sbar = (!$isApplicationGuidePage && !$isContactsPage && $this->countModules('sidebar')) ? 'w_sidebar' : 'wo_sidebar'; ?>
				<div class="cont_<?php echo $sbar; ?> ">
					<div class="cont">
						<?php if ($isApplicationGuidePage) : ?>
						<section class="app-install-guide" aria-labelledby="app-install-guide-title">
							<h1 id="app-install-guide-title">Как установить приложение Vigling</h1>
							<p class="app-install-guide__lead">Сайт работает как SPA-приложение. Добавьте его на экран устройства через меню браузера.</p>
							<div class="app-install-guide__actions">
								<a class="btn btn__time-zapis app-install-guide__install-btn" href="<?php echo htmlspecialchars(rtrim(Uri::root(), '/') . '/?pwa_install=1'); ?>">Установить SPA приложение</a>
							</div>
							<div class="app-install-guide__grid">
								<article class="app-install-guide__card">
									<h2>iPhone / iPad (iOS)</h2>
									<ol>
										<li>Откройте сайт Vigling в Safari.</li>
										<li>Нажмите кнопку <strong>Поделиться</strong> (квадрат со стрелкой вверх).</li>
										<li>Выберите пункт <strong>На экран "Домой"</strong>.</li>
										<li>Подтвердите кнопкой <strong>Добавить</strong>.</li>
									</ol>
									<a href="/images/1.jpg" data-fancybox="app-install-guide" data-caption="Установка Vigling на iOS" class="app-install-guide__image-link">
										<img src="/images/1.jpg" alt="Инструкция установки Vigling на iOS через Safari" loading="lazy">
									</a>
								</article>
								<article class="app-install-guide__card">
									<h2>Android</h2>
									<ol>
										<li>Откройте сайт Vigling в Chrome.</li>
										<li>Откройте меню браузера (три точки).</li>
										<li>Выберите <strong>Установить приложение</strong> или <strong>Добавить на главный экран</strong>.</li>
										<li>Подтвердите установку.</li>
									</ol>
									<a href="/images/2.jpg" data-fancybox="app-install-guide" data-caption="Установка Vigling на Android" class="app-install-guide__image-link">
										<img src="/images/2.jpg" alt="Инструкция установки Vigling на Android через Chrome" loading="lazy">
									</a>
								</article>
							</div>
						</section>
						<?php elseif ($isContactsPage) : ?>
						<section class="contacts-settings-page" aria-labelledby="contacts-settings-title">
							<h1 id="contacts-settings-title">Контакты</h1>
							<?php if (trim($contactsPageHtml) !== '') : ?>
								<?php echo $contactsPageHtml; ?>
							<?php else : ?>
								<p>Контент страницы контактов пока не заполнен.</p>
							<?php endif; ?>
						</section>
						<?php else : ?>
						<jdoc:include type="component" />
						<?php endif; ?>
					</div>
					<div class="sbar"<?php echo ($isApplicationGuidePage || $isContactsPage) ? ' style="display:none;"' : ''; ?>>
						<jdoc:include type="modules" name="sidebar" style="html5" />
					</div>
				</div>
			</div>
		</section>
	<?php endif; ?>
	<?php if ($page === 'home') : ?>
		<?php if ($this->countModules('top')) : ?>
			<jdoc:include type="modules" name="top" style="html5" />
		<?php else :
			$searchCategories = [];
			try {
				$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
				$prefix = $db->getPrefix();
				$q = $db->getQuery(true)
					->select('id, title')
					->from($db->quoteName($prefix . 'categories'))
					->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
					->where($db->quoteName('published') . ' = 1')
					->where('(' . $db->quoteName('parent_id') . ' = 39 OR ' . $db->quoteName('path') . ' LIKE ' . $db->quote('uslugi/%') . ' OR ' . $db->quoteName('id') . ' IN (9,10,11,12,13,14,16,17,18,19,20,21))')
					->order('title ASC');
				$db->setQuery($q);
				$searchCategories = $db->loadAssocList('id') ?: [];
			} catch (\Throwable $e) {
			}
			$searchCategoriesJson = json_encode(array_values(array_map(function ($c) { return ['id' => (int)$c['id'], 'title' => $c['title']]; }, $searchCategories)));
		?>
		<section class="search__specialists search__section">
			<div class="container">
				<div class="search__coll-left">
					<h2 class="search_title">поиск специалистов</h2>
					<span class="search__sub"></span>
					<form action="<?php echo Route::_('index.php?option=com_poisk&view=list'); ?>" method="get">
						<input type="hidden" name="search" value="1">
						<div class="jsn_search_module-ext jsn_result_poisk">
							<div class="filed filed1">
								<input type="text" id="search_service_input" placeholder="Услуга или специальность" autocomplete="off">
								<input type="hidden" name="cat_id" id="search_cat_id" value="">
							</div>
							<div class="filed filed2">
								<input type="text" name="date" placeholder="Дата" class="date-input">
							</div>
							<div class="filed filed3">
								<div class="control-group">
									<div class="controls">
										<fieldset class="checkboxes" id="at_home">
											<label class="checkbox"><input type="checkbox" name="home[]" value="1"><b>Салон</b></label>
											<label class="checkbox"><input type="checkbox" name="home[]" value="2"><b>Вызов на дом</b></label>
											<label class="checkbox"><input type="checkbox" name="home[]" value="3"><b>Мастер на дому</b></label>
										</fieldset>
									</div>
								</div>
							</div>
							<span class="form-sub">Популярные запросы: </span>
							<input type="submit" class="btn search-sbmt" value="Поиск">
						</div>
					</form>
				</div>
				<p class="search__text"></p>
				<div class="clearFloat"></div>
			</div>
		</section>
		<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.min.css">
		<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
		<script>
		(function(){
			var categories = <?php echo $searchCategoriesJson; ?>;
			jQuery(document).ready(function($){
				var $input = $('#search_service_input');
				var $catId = $('#search_cat_id');
				var $result = $('<div class="search_box-result"></div>');
				$input.after($result);
				function filterCategories(val){
					val = (val || '').toLowerCase().trim();
					if (val.length < 1) return [];
					return categories.filter(function(c){ return c.title.toLowerCase().indexOf(val) !== -1; });
				}
				$input.on('keyup', function(){
					var val = $(this).val();
					var list = filterCategories(val);
					$result.empty();
					if (list.length) {
						list.forEach(function(c){
							$result.append($('<div class="result" data-id="'+c.id+'">').text(c.title));
						});
						$result.show();
					} else {
						$result.hide();
					}
				});
				$input.on('focus', function(){
					if ($(this).val().length > 0 && $result.children().length) $result.show();
				});
				$(document).on('click', function(e){
					if (!$(e.target).closest('.filed1').length) $result.hide();
				});
				$result.on('click', '> div', function(){
					$catId.val($(this).data('id'));
					$input.val($(this).text());
					$result.hide().empty();
				});
				$('.filed2').on('focus', 'input', function(){
					if (!$(this).hasClass('hasDatepicker')) {
						$.datepicker.regional['ru'] = {closeText:'Закрыть',prevText:'&lt;Пред',nextText:'След&gt;',currentText:'Сегодня',monthNames:['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'],monthNamesShort:['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'],dayNames:['воскресенье','понедельник','вторник','среда','четверг','пятница','суббота'],dayNamesShort:['вск','пнд','втр','срд','чтв','птн','сбт'],dayNamesMin:['Вс','Пн','Вт','Ср','Чт','Пт','Сб'],weekHeader:'Не',dateFormat:'dd.mm.yy',firstDay:1,isRTL:false,yearSuffix:''};
						$.datepicker.setDefaults($.datepicker.regional['ru']);
						$('.filed2 input').datepicker({minDate:'now'});
					}
				});
			});
		})();
		</script>
		<?php endif; ?>
		<section class="search__catalog">
			<div class="container">
				<h2>поиск по услугам</h2>
				<span class="service__sub"></span>
				<?php
				$serviceLinks = [
					16 => 'Волосы',
					10 => 'Ресницы',
					18 => 'Ногти',
					12 => 'Косметология',
					13 => 'Эпиляция',
					14 => 'Визаж',
				];
				$serviceImages = ['service1.png', 'service2.png', 'service3.png', 'service4.png', 'service5.png', 'service6.png'];
				$si = 0;
				?>
				<div>
					<?php foreach ($serviceLinks as $catId => $label) : ?>
					<?php $serviceUrl = Route::_('index.php?option=com_poisk&view=list&cat_id=' . (int) $catId); ?>
					<div class="service__item">
						<a class="service__img-link" href="<?php echo $serviceUrl; ?>">
							<div style="background-image: url('/images/<?php echo $serviceImages[$si]; ?>')" class="service__img"><div></div></div>
						</a>
						<a class="service__title" href="<?php echo $serviceUrl; ?>"><?php echo htmlspecialchars($label); ?></a>
					</div>
					<?php $si++; endforeach; ?>
					<div class="clearFloat"></div>
				</div>
			</div>
		</section>
	<?php endif; ?>
	<?php if ($this->countModules('addmaster')) : ?>
		<section class="info__box">
			<div class="container">
				<jdoc:include type="modules" name="addmaster" style="none" />
			</div>
		</section>
	<?php endif; ?>
	<?php if ($this->countModules('loadapps')) : ?>
		<section class="app">
			<div class="container">
				<jdoc:include type="modules" name="loadapps" style="none" />
				<div class="clearFloat"></div>
			</div>
		</section>
	<?php endif; ?>
	<?php if ($this->countModules('topposts')) : ?>
		<section class="news">
			<div class="container2">
				<jdoc:include type="modules" name="topposts" style="none" />
			</div>
		</section>
	<?php endif; ?>
	<footer class="footer">
		<div class="container">
			<a class="footer__logo" href="<?php echo Uri::root(); ?>" aria-label="<?php echo $sitename; ?>">
				<img src="/images/logo.jpg" width="65" height="65" alt="<?php echo $sitename; ?>">
				<span class="footer__logo-text"><?php echo $sitename; ?></span>
			</a>
			<jdoc:include type="modules" name="bottommenu" style="none" />
			<div class="clearFloat"></div>
		</div>
		<div class="container">
			<span class="copy">@ Все права защищены. 2019-<?php echo date('Y'); ?></span>
		</div>
	</footer>
	<jdoc:include type="modules" name="debug" style="none" />
	<jdoc:include type="scripts" />
	<?php
	$pushnotifyUser = $app->getIdentity();
	$pushnotifyLoggedIn = $pushnotifyUser && (int) $pushnotifyUser->id > 0;
	$pushnotifyBase = $pushnotifyLoggedIn ? Route::_('index.php?option=com_pushnotify') : '';
	$pushnotifySwUrl = rtrim(Uri::root(), '/') . '/firebase-messaging-sw.js';
	$pushnotifyRoot = rtrim(Uri::root(), '/');
	$pushnotifyTokenName = $pushnotifyLoggedIn ? Session::getFormToken() : '';
	$pushnotifyTokenValue = $pushnotifyLoggedIn ? '1' : '';
	$pushnotifyFirebaseConfig = [];
	if ($pushnotifyLoggedIn && is_file(JPATH_ROOT . '/configuration/firebase-config.php')) {
		$pushnotifyFirebaseConfig = (include JPATH_ROOT . '/configuration/firebase-config.php');
		if (!is_array($pushnotifyFirebaseConfig)) $pushnotifyFirebaseConfig = [];
	}
	$pushnotifyHasFirebase = $pushnotifyLoggedIn && !empty($pushnotifyFirebaseConfig['apiKey']);
	$pushnotifyIsLkProfile = $option === 'com_users' && $view === 'profile';
	$pushnotifyIsClientsPage = $option === 'com_orders' && $view === 'orders' && $layout === 'clients';
	?>
	<?php if ($pushnotifyHasFirebase) : ?>
	<script>
		window.PUSHNOTIFY_GLOBAL = true;
		window.PUSHNOTIFY_BASE = <?php echo json_encode($pushnotifyBase); ?>;
		window.PUSHNOTIFY_SW_URL = <?php echo json_encode($pushnotifySwUrl); ?>;
		window.PUSHNOTIFY_TOKEN_NAME = <?php echo json_encode($pushnotifyTokenName); ?>;
		window.PUSHNOTIFY_TOKEN_VALUE = <?php echo json_encode($pushnotifyTokenValue); ?>;
		window.PUSHNOTIFY_IS_LK_PROFILE = <?php echo $pushnotifyIsLkProfile ? 'true' : 'false'; ?>;
		window.PUSHNOTIFY_IS_CLIENTS_PAGE = <?php echo $pushnotifyIsClientsPage ? 'true' : 'false'; ?>;
		window.FIREBASE_VAPID_KEY = <?php echo json_encode($pushnotifyFirebaseConfig['vapidKey'] ?? ''); ?>;
		window.FIREBASE_CONFIG = <?php echo json_encode([
			'apiKey' => $pushnotifyFirebaseConfig['apiKey'] ?? '',
			'authDomain' => $pushnotifyFirebaseConfig['authDomain'] ?? '',
			'projectId' => $pushnotifyFirebaseConfig['projectId'] ?? '',
			'storageBucket' => $pushnotifyFirebaseConfig['storageBucket'] ?? '',
			'messagingSenderId' => $pushnotifyFirebaseConfig['messagingSenderId'] ?? '',
			'appId' => $pushnotifyFirebaseConfig['appId'] ?? '',
		]); ?>;
	</script>
	<div class="modal fade" id="pushnotify-prompt-modal" tabindex="-1" role="dialog" aria-labelledby="pushnotify-prompt-title" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="pushnotify-prompt-title">Уведомления</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Закрыть"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="modal-body">
					<p>Получайте напоминания о записях и приёмах. Включить push-уведомления?</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-dismiss="modal" id="pushnotify-prompt-later">Позже</button>
					<button type="button" class="btn btn-primary" id="pushnotify-prompt-subscribe">Включить</button>
				</div>
			</div>
		</div>
	</div>
	<script>
		(function() {
			var modalEl = document.getElementById('pushnotify-prompt-modal');
			var laterBtn = document.getElementById('pushnotify-prompt-later');
			var subscribeBtn = document.getElementById('pushnotify-prompt-subscribe');
			var titleEl = document.getElementById('pushnotify-prompt-title');
			var bodyEl = modalEl ? modalEl.querySelector('.modal-body p') : null;
			if (!modalEl || !window.PUSHNOTIFY_BASE || !('Notification' in window)) return;
			var base = window.PUSHNOTIFY_BASE;
			var sep = base.indexOf('?') === -1 ? '?' : '&';
			var tokenName = window.PUSHNOTIFY_TOKEN_NAME || '';
			var tokenValue = window.PUSHNOTIFY_TOKEN_VALUE || '1';
			var subscribeBtnText = subscribeBtn ? subscribeBtn.textContent : 'Включить';
			var defaultTitle = titleEl ? titleEl.textContent : 'Уведомления';
			var defaultBody = bodyEl ? bodyEl.textContent : 'Получайте напоминания о записях и приёмах. Включить push-уведомления?';
			var activePromptKey = '';
			var activePromptRemember = true;
			var isLoggedIn = <?php echo $app->getIdentity()->guest ? 'false' : 'true'; ?>;
			var isProfilePage = window.PUSHNOTIFY_IS_LK_PROFILE === true;
			var isClientsPage = window.PUSHNOTIFY_IS_CLIENTS_PAGE === true;
			var prefsUrl = base + sep + 'task=display.getPreferences&format=json&' + encodeURIComponent(tokenName) + '=' + encodeURIComponent(tokenValue);
			function getStorageKey(reason) {
				return 'pushnotify_prompt_seen:' + reason;
			}
			function markPromptHandled() {
				if (!activePromptKey) return;
				try { localStorage.setItem(getStorageKey(activePromptKey), '1'); } catch (e) {}
			}
			function wasPromptHandled(reason) {
				try { return localStorage.getItem(getStorageKey(reason)) === '1'; } catch (e) { return false; }
			}
			function setPromptContent(opts) {
				var options = opts || {};
				if (titleEl) titleEl.textContent = options.title || defaultTitle;
				if (bodyEl) bodyEl.textContent = options.body || defaultBody;
			}
			function normalizePromptOptions(reasonOrOptions) {
				if (typeof reasonOrOptions === 'string') {
					return { reason: reasonOrOptions };
				}
				return reasonOrOptions || {};
			}
			function getReasonDefaults(reason) {
				if (reason === 'clients_first_visit') {
					return {
						title: 'Уведомления',
						body: 'Чтобы не пропускать записи клиентов включите уведомления.'
					};
				}
				if (reason === 'bell_click') {
					return {
						title: 'Уведомления',
						body: 'Чтобы получать новые записи и напоминания, включите push-уведомления.'
					};
				}
				if (reason === 'booking_success_modal') {
					return {
						title: 'Уведомления',
						body: 'Чтобы не пропустить предстоящую запись, включите push-уведомления.'
					};
				}
				return {
					title: defaultTitle,
					body: defaultBody
				};
			}
			function buildPromptReason() {
				try {
					var url = new URL(window.location.href);
					if (url.searchParams.get('booking_success') === '1') {
						return Promise.resolve('booking_success');
					}
				} catch (e) {}
				if (!isLoggedIn) {
					return Promise.resolve('');
				}
				if (isClientsPage) {
					return Promise.resolve('clients_first_visit');
				}
				if (isProfilePage) {
					return Promise.resolve('lk_first_visit');
				}
				return Promise.resolve('');
			}
			function hidePrompt(remember) {
				if (remember !== false && activePromptRemember !== false) {
					markPromptHandled();
				}
				$(modalEl).modal('hide');
			}
			function setSubscribeLoading(isLoading) {
				if (!subscribeBtn) return;
				if (isLoading) {
					subscribeBtn.disabled = true;
					subscribeBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>Подключаем...';
				} else {
					subscribeBtn.disabled = false;
					subscribeBtn.textContent = subscribeBtnText;
				}
			}
			function fetchPreferences() {
				return fetch(prefsUrl, { credentials: 'same-origin' }).then(function(r) { return r.json(); });
			}
			function canShowPrompt(prefs, force) {
				if (!prefs || !prefs.success) return false;
				if (Notification.permission === 'denied') return false;
				if (force) {
					return Notification.permission === 'default' || !prefs.subscribed || prefs.notifications_enabled === false;
				}
				return Notification.permission === 'default' || (Notification.permission === 'granted' && (!prefs.subscribed || prefs.notifications_enabled === false));
			}
			function openPrompt(reasonOrOptions) {
				var options = normalizePromptOptions(reasonOrOptions);
				var reason = String(options.reason || '').trim();
				if (!reason) return Promise.resolve(false);
				return fetchPreferences().then(function(prefs) {
					if (!canShowPrompt(prefs, !!options.force)) return false;
					if (!options.force && options.remember !== false && wasPromptHandled(reason)) return false;
					var defaults = getReasonDefaults(reason);
					activePromptKey = reason;
					activePromptRemember = options.remember !== false;
					setPromptContent({
						title: options.title || defaults.title,
						body: options.body || defaults.body
					});
					$(modalEl).modal('show');
					return true;
				}).catch(function() {
					return false;
				});
			}
			window.ViglingPushPrompt = {
				show: openPrompt,
				getPreferences: fetchPreferences
			};
			Promise.all([
				fetchPreferences().catch(function(){ return null; }),
				buildPromptReason()
			]).then(function(results) {
				var prefs = results[0];
				var promptReason = results[1] || '';
				if (!prefs || !prefs.success) return;
				if (!promptReason || wasPromptHandled(promptReason)) return;
				if (Notification.permission === 'denied') return;
				var needPrompt = Notification.permission === 'default' || (Notification.permission === 'granted' && (!prefs.subscribed || prefs.notifications_enabled === false));
				if (!needPrompt) return;
				activePromptKey = promptReason;
				activePromptRemember = true;
				setPromptContent(getReasonDefaults(promptReason));
				$(modalEl).modal('show');
			}).catch(function(){});
			if (laterBtn) laterBtn.addEventListener('click', function() { hidePrompt(true); });
			modalEl.addEventListener('hidden.bs.modal', function() {
				if (activePromptRemember !== false) {
					markPromptHandled();
				}
				activePromptKey = '';
				activePromptRemember = true;
				setPromptContent();
			});
			function sendToken(token) {
				if (!token) return;
				var fd = new FormData();
				fd.append(window.PUSHNOTIFY_TOKEN_NAME, window.PUSHNOTIFY_TOKEN_VALUE);
				fd.append('token', token);
				fd.append('device_type', /Android|webOS|iPhone|iPad|iPod|BlackBerry/i.test(navigator.userAgent) ? 'android' : 'desktop');
				fd.append('browser', navigator.userAgent.indexOf('Chrome') >= 0 ? 'chrome' : (navigator.userAgent.indexOf('Firefox') >= 0 ? 'firefox' : (navigator.userAgent.indexOf('Edg') >= 0 ? 'edge' : '')));
				return fetch(base + sep + 'task=display.subscribe&format=json', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
			}
			function loadFirebaseAndSubscribe() {
				function requestTokenWithCurrentFirebase() {
					return navigator.serviceWorker.ready.then(function() { return navigator.serviceWorker.getRegistration('/'); }).then(function(reg) {
						if (!reg) return navigator.serviceWorker.register(window.PUSHNOTIFY_SW_URL, { scope: '/' });
						return reg;
					}).then(function(reg) {
						var app = window.firebase.app();
						return app.messaging().getToken({ vapidKey: window.FIREBASE_VAPID_KEY || undefined, serviceWorkerRegistration: reg });
					});
				}
				if (window.firebase && window.firebase.messaging) {
					try {
						var a = window.firebase.app();
						if (!a || !a.name) window.firebase.initializeApp(window.FIREBASE_CONFIG);
					} catch (e) { window.firebase.initializeApp(window.FIREBASE_CONFIG); }
					return requestTokenWithCurrentFirebase();
				}
				return new Promise(function(resolve, reject) {
					var s1 = document.createElement('script');
					s1.src = 'https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js';
					s1.onload = function() {
						var s2 = document.createElement('script');
						s2.src = 'https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js';
						s2.onload = function() {
							try { var a = window.firebase.app(); if (!a || !a.name) window.firebase.initializeApp(window.FIREBASE_CONFIG); } catch (e) { window.firebase.initializeApp(window.FIREBASE_CONFIG); }
							requestTokenWithCurrentFirebase().then(resolve).catch(reject);
						};
						s2.onerror = reject;
						document.head.appendChild(s2);
					};
					s1.onerror = reject;
					document.head.appendChild(s1);
				});
			}
			function subscribeWithRetry(maxAttempts) {
				var attempt = 0;
				function run() {
					attempt++;
					return loadFirebaseAndSubscribe().then(function(token) {
						if (!token) {
							throw new Error('empty-token');
						}
						return Promise.resolve(sendToken(token)).then(function() { return true; });
					}).catch(function() {
						if (attempt >= maxAttempts) return false;
						return new Promise(function(resolve) {
							setTimeout(function() { resolve(run()); }, 1400 * attempt);
						});
					});
				}
				return run();
			}
			if (subscribeBtn) subscribeBtn.addEventListener('click', function() {
				setSubscribeLoading(true);
				Notification.requestPermission().then(function(perm) {
					if (perm === 'granted') {
						return subscribeWithRetry(3).catch(function(){});
					}
					return Promise.resolve(false);
				}).catch(function(){}).finally(function() {
					setSubscribeLoading(false);
					hidePrompt(true);
				});
			});
		})();
	</script>
	<script>
		(function() {
			if (!window.PUSHNOTIFY_GLOBAL || !window.PUSHNOTIFY_BASE || !('Notification' in window) || Notification.permission !== 'granted') return;
			function pushnotifySendToken(token) {
				if (!token) return;
				var base = window.PUSHNOTIFY_BASE;
				var sep = base.indexOf('?') === -1 ? '?' : '&';
				var fd = new FormData();
				fd.append(window.PUSHNOTIFY_TOKEN_NAME, window.PUSHNOTIFY_TOKEN_VALUE);
				fd.append('token', token);
				fd.append('device_type', /Android|webOS|iPhone|iPad|iPod|BlackBerry/i.test(navigator.userAgent) ? 'android' : 'desktop');
				fd.append('browser', navigator.userAgent.indexOf('Chrome') >= 0 ? 'chrome' : (navigator.userAgent.indexOf('Firefox') >= 0 ? 'firefox' : (navigator.userAgent.indexOf('Edg') >= 0 ? 'edge' : '')));
				fetch(base + sep + 'task=display.subscribe&format=json', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
			}
			function pushnotifyRefresh() {
				if (!window.firebase || !window.FIREBASE_CONFIG) {
					var load = function(cb) {
						if (window.firebase && window.firebase.messaging) return cb();
						var s1 = document.createElement('script');
						s1.src = 'https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js';
						s1.onload = function() {
							var s2 = document.createElement('script');
							s2.src = 'https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js';
							s2.onload = function() {
								try {
									var a = window.firebase.app();
									if (!a || !a.name) window.firebase.initializeApp(window.FIREBASE_CONFIG);
								} catch (e) {
									window.firebase.initializeApp(window.FIREBASE_CONFIG);
								}
								cb();
							};
							document.head.appendChild(s2);
						};
						document.head.appendChild(s1);
					};
					load(function() {
						navigator.serviceWorker.ready.then(function() { return navigator.serviceWorker.getRegistration('/'); }).then(function(reg) {
							if (!reg) return navigator.serviceWorker.register(window.PUSHNOTIFY_SW_URL, { scope: '/' });
							return reg;
						}).then(function(reg) {
							var app;
							try { app = window.firebase.app(); } catch (e) { app = window.firebase.initializeApp(window.FIREBASE_CONFIG); }
							return app.messaging().getToken({
								vapidKey: window.FIREBASE_VAPID_KEY || undefined,
								serviceWorkerRegistration: reg
							});
						}).then(pushnotifySendToken).catch(function(){});
					});
					return;
				}
				navigator.serviceWorker.ready.then(function() { return navigator.serviceWorker.getRegistration('/'); }).then(function(reg) {
					if (!reg) return navigator.serviceWorker.register(window.PUSHNOTIFY_SW_URL, { scope: '/' });
					return reg;
				}).then(function(reg) {
					var app;
					try { app = window.firebase.app(); } catch (e) { app = window.firebase.initializeApp(window.FIREBASE_CONFIG); }
					return app.messaging().getToken({
						vapidKey: window.FIREBASE_VAPID_KEY || undefined,
						serviceWorkerRegistration: reg
					});
				}).then(pushnotifySendToken).catch(function(){});
			}
			document.addEventListener('visibilitychange', function() {
				if (document.visibilityState === 'visible') pushnotifyRefresh();
			});
			setInterval(pushnotifyRefresh, 25 * 60 * 1000);
			setTimeout(pushnotifyRefresh, 3000);
		})();
	</script>
	<?php endif; ?>
	<script>
		if ('serviceWorker' in navigator) {
			window.addEventListener('load', function () {
				var u = '<?php echo rtrim(Uri::root(), '/') . '/firebase-messaging-sw.js'; ?>';
				navigator.serviceWorker.register(u, { scope: '/' }).catch(function () {});
			});
		}
		(function(){
			function formatTimeUtc(el) {
				var iso = el && el.getAttribute('data-time-utc');
				if (!iso) return;
				try {
					var d = new Date(iso);
					if (!isNaN(d.getTime())) el.textContent = d.toLocaleString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
				} catch (e) {}
			}
			document.querySelectorAll('.lk-time-utc[data-time-utc]').forEach(formatTimeUtc);
		})();
	</script>
	<script>
		(function() {
			var mobileHeader = document.getElementById('header-mobile');
			var toggle = document.getElementById('header-mobile-toggle');
			var panel = document.getElementById('header-mobile-panel');
			var overlay = document.getElementById('header-mobile-overlay');
			var closeBtn = document.getElementById('header-mobile-close');
			if (!mobileHeader || !panel) return;
			function openMenu() {
				mobileHeader.classList.add('menu-open');
				panel.setAttribute('aria-hidden', 'false');
				if (overlay) overlay.setAttribute('aria-hidden', 'false');
				if (toggle) toggle.setAttribute('aria-expanded', 'true');
			}
			function closeMenu() {
				mobileHeader.classList.remove('menu-open');
				panel.setAttribute('aria-hidden', 'true');
				if (overlay) overlay.setAttribute('aria-hidden', 'true');
				if (toggle) toggle.setAttribute('aria-expanded', 'false');
			}
			if (toggle) toggle.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); mobileHeader.classList.contains('menu-open') ? closeMenu() : openMenu(); });
			if (closeBtn) closeBtn.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); closeMenu(); });
			if (overlay) overlay.addEventListener('click', function(e) { e.preventDefault(); closeMenu(); });
			panel.querySelectorAll('.nav-item.parent').forEach(function(li) {
				li.classList.add('is-open');
			});
			panel.querySelectorAll('.mod-menu__sub').forEach(function(submenu) {
				submenu.setAttribute('aria-hidden', 'false');
			});
			panel.querySelectorAll('.mod-menu__toggle-sub').forEach(function(btn) {
				btn.setAttribute('aria-expanded', 'true');
			});
			panel.querySelectorAll('.nav-item a').forEach(function(a) {
				a.addEventListener('click', closeMenu);
			});
		})();
	</script>
		<style>
		.pwa-install-page {
			position: fixed;
			inset: 0;
			z-index: 110000;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 18px;
		}
		.pwa-install-backdrop {
			position: absolute;
			inset: 0;
			background: rgba(0, 0, 0, 0.42);
		}
		.pwa-install-card {
			position: relative;
			background: #fff;
			border: 1px solid #ececec;
			border-radius: 12px;
			padding: 22px;
			box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
			width: min(92vw, 760px);
			z-index: 1;
		}
		.pwa-install-close {
			position: absolute;
			top: 10px;
			right: 14px;
			border: 0;
			background: transparent;
			font-size: 40px;
			line-height: 1;
			color: #777;
			cursor: pointer;
			padding: 0;
		}
		.pwa-install-close:hover { color: #111; }
		.pwa-install-card h1 {
			margin: 0 40px 12px 0;
			font-size: 30px;
			line-height: 1.2;
			font-family: "GothamPro-Bold";
		}
	.pwa-install-card p {
		margin: 0 0 16px;
		color: #444;
		font-size: 16px;
		line-height: 1.45;
	}
		.pwa-install-actions {
			display: flex;
			align-items: center;
			gap: 14px;
			flex-wrap: wrap;
		}
		.pwa-install-actions #pwa-install-btn {
			margin-top: 0;
			display: inline-flex;
			align-items: center;
			justify-content: center;
		}
		.pwa-install-actions .pwa-install-back {
			display: inline-flex;
			align-items: center;
			min-height: 42px;
		}
		.pwa-install-back { color: #222; text-decoration: none; }
		.pwa-install-back:hover { text-decoration: underline; }
		.pwa-install-status { margin-top: 12px; min-height: 22px; color: #444; }
		@media (max-width: 768px) {
			.pwa-install-page { padding: 12px; align-items: flex-end; }
			.pwa-install-card { padding: 16px; border-radius: 10px; width: 100%; }
			.pwa-install-card h1 { font-size: 22px; margin-right: 34px; }
			.pwa-install-card p { font-size: 14px; }
			.pwa-install-close { top: 8px; right: 12px; font-size: 34px; }
			.pwa-install-actions {
				flex-direction: column;
				align-items: stretch;
				gap: 10px;
			}
			.pwa-install-actions .btn__time-zapis {
				margin-top: 0 !important;
				margin-left: 0 !important;
				margin-right: 0 !important;
			}
			.pwa-install-actions #pwa-install-btn,
			.pwa-install-actions .pwa-install-back {
				width: 100%;
				justify-content: center;
			}
		}
		</style>
		<script>
	(function() {
		var deferredPrompt = null;
		var installInProgress = false;

		window.addEventListener('beforeinstallprompt', function(e) {
			e.preventDefault();
			deferredPrompt = e;
			window.__viglingBeforeInstallPrompt = e;
			try { window.dispatchEvent(new CustomEvent('vigling:pwa-ready')); } catch (err) {}
		});

		window.ViglingPwaInstall = {
			isReady: function() { return !!deferredPrompt; },
			requestInstall: function() {
				var promptEvent = deferredPrompt;
				if (!promptEvent || installInProgress) {
					return Promise.resolve({ success: false, reason: 'not-ready' });
				}
				installInProgress = true;
				return promptEvent.prompt()
					.then(function() { return promptEvent.userChoice; })
					.then(function(choice) {
						installInProgress = false;
						deferredPrompt = null;
						window.__viglingBeforeInstallPrompt = null;
						return { success: choice && choice.outcome === 'accepted', choice: choice };
					})
					.catch(function(error) {
						installInProgress = false;
						return Promise.reject(error);
					});
			}
		};

		window.addEventListener('appinstalled', function() {
			deferredPrompt = null;
			window.__viglingBeforeInstallPrompt = null;
		});

			function initInstallPage() {
				var btn = document.getElementById('pwa-install-btn');
				var statusEl = document.getElementById('pwa-install-status');
				var overlay = document.getElementById('pwa-install-overlay');
				if (!btn || !statusEl || !overlay) return;

				function closeOverlay() {
					window.location.href = <?php echo json_encode(rtrim(Uri::root(), '/') . '/'); ?>;
				}

				function setStatus(text) { statusEl.textContent = text || ''; }
				function tryInstall() {
				if (!window.ViglingPwaInstall || !window.ViglingPwaInstall.isReady()) {
					setStatus('Установка недоступна. Откройте сайт по HTTPS и попробуйте в Chrome/Edge на Android или Desktop.');
					return;
				}
				setStatus('Ожидаем подтверждение установки...');
				window.ViglingPwaInstall.requestInstall().then(function(res) {
					if (res && res.success) {
						setStatus('Приложение установлено.');
					} else {
						setStatus('Установка отменена.');
					}
				}).catch(function() {
					setStatus('Не удалось запустить установку.');
				});
			}

				btn.addEventListener('click', function() { tryInstall(); });
				overlay.querySelectorAll('[data-close-pwa-install="1"]').forEach(function(el) {
					el.addEventListener('click', function() { closeOverlay(); });
				});
				document.addEventListener('keydown', function(e) {
					if (e.key === 'Escape') {
						closeOverlay();
					}
				});
				window.addEventListener('vigling:pwa-ready', function() {
					setStatus('Установка доступна. Нажмите кнопку.');
				});
				if (window.ViglingPwaInstall && window.ViglingPwaInstall.isReady()) {
					setStatus('Установка доступна. Нажмите кнопку.');
				} else {
					setStatus('Ожидание готовности установки...');
				}
		}

		document.addEventListener('DOMContentLoaded', function() {
			initInstallPage();
		});
	})();
	</script>
	<style>
	.app-install-guide {
		padding: 8px 0 24px;
	}
	.contacts-settings-page {
		padding: 8px 0 24px;
	}
	.contacts-settings-page h1 {
		margin: 0 0 14px;
		font-size: 36px;
		line-height: 1.2;
		font-family: "GothamPro-Bold";
	}
	@media (max-width: 991px) {
		.contacts-settings-page h1 {
			font-size: 30px;
		}
	}
	.app-install-guide h1 {
		margin: 0 0 10px;
		font-size: 36px;
		line-height: 1.2;
		font-family: "GothamPro-Bold";
	}
	.app-install-guide__lead {
		margin: 0 0 22px;
		font-size: 18px;
		color: #444;
	}
	.app-install-guide__actions {
		margin: 0 0 18px;
	}
	.app-install-guide__install-btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		margin-top: 0 !important;
		margin-left: 0 !important;
		margin-right: 0 !important;
		min-height: 52px;
		padding: 0 24px;
		text-decoration: none !important;
	}
	.app-install-guide__grid {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 18px;
	}
	.app-install-guide__card {
		background: #fff;
		border: 1px solid #ececec;
		border-radius: 14px;
		padding: 18px;
		box-shadow: 0 8px 22px rgba(0, 0, 0, .05);
	}
	.app-install-guide__card h2 {
		margin: 0 0 10px;
		font-size: 24px;
		line-height: 1.2;
	}
	.app-install-guide__card ol {
		margin: 0 0 14px;
		padding-left: 22px;
	}
	.app-install-guide__card ol li {
		margin: 0 0 8px;
		line-height: 1.4;
	}
	.app-install-guide__card img {
		display: block;
		width: 100%;
		height: auto;
		border-radius: 10px;
		border: 1px solid #efefef;
		background: #fafafa;
	}
	.app-install-guide__image-link {
		display: block;
	}
	@media (max-width: 991px) {
		.app-install-guide h1 {
			font-size: 30px;
		}
		.app-install-guide__lead {
			font-size: 16px;
		}
		.app-install-guide__actions {
			display: flex;
			justify-content: center;
		}
		.app-install-guide__grid {
			grid-template-columns: 1fr;
		}
	}
	.vig-notify-root {
		position: fixed;
		top: 14px;
		left: 50%;
		transform: translateX(-50%);
		z-index: 100000;
		width: min(92vw, 560px);
		pointer-events: none;
	}
	.vig-notify {
		pointer-events: auto;
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 10px;
		padding: 12px 14px;
		border-radius: 10px;
		border: 1px solid;
		box-shadow: 0 6px 16px rgba(0, 0, 0, 0.14);
		margin-bottom: 8px;
		font-family: "GothamPro-Medium";
		font-size: 16px;
		line-height: 1.3;
	}
	.vig-notify--success { background: #eef8f0; border-color: #6fb784; color: #2f6d43; }
	.vig-notify--error { background: #fdeaea; border-color: #d94a4a; color: #8f1e1e; }
	.vig-notify--info { background: #eaf4fd; border-color: #4f8fcf; color: #1f4f7a; }
	.vig-notify--warning { background: #fff4de; border-color: #dda232; color: #7b560e; }
	.vig-notify__close {
		flex: 0 0 auto;
		width: 24px;
		height: 24px;
		border: 0;
		background: transparent;
		color: currentColor;
		font-size: 20px;
		line-height: 1;
		cursor: pointer;
		padding: 0;
	}
	@media (max-width: 768px) {
		.vig-notify-root {
			top: 10px;
			width: calc(100vw - 16px);
		}
		.vig-notify {
			font-size: 14px;
			padding: 10px 12px;
			border-radius: 8px;
		}
	}
	</style>
	<script>
	(function() {
		var root = null;

		function ensureRoot() {
			if (root && root.parentNode) return root;
			root = document.getElementById('vig-notify-root');
			if (!root) {
				root = document.createElement('div');
				root.id = 'vig-notify-root';
				root.className = 'vig-notify-root';
				document.body.appendChild(root);
			}
			return root;
		}

		function show(message, type, options) {
			options = options || {};
			var kind = String(type || 'info').toLowerCase();
			var timeout = typeof options.timeout === 'number' ? options.timeout : 7000;
			var host = ensureRoot();
			var box = document.createElement('div');
			box.className = 'vig-notify vig-notify--' + kind;

			var text = document.createElement('div');
			text.className = 'vig-notify__text';
			text.textContent = String(message || '');

			var close = document.createElement('button');
			close.type = 'button';
			close.className = 'vig-notify__close';
			close.setAttribute('aria-label', 'Закрыть');
			close.textContent = '×';

			close.addEventListener('click', function() {
				if (box.parentNode) box.parentNode.removeChild(box);
			});

			box.appendChild(text);
			box.appendChild(close);
			host.appendChild(box);

			if (timeout > 0) {
				setTimeout(function() {
					if (box.parentNode) box.parentNode.removeChild(box);
				}, timeout);
			}
			return box;
		}

		window.ViglingNotify = {
			show: show,
			success: function(message, options) { return show(message, 'success', options); },
			error: function(message, options) { return show(message, 'error', options); },
			info: function(message, options) { return show(message, 'info', options); },
			warning: function(message, options) { return show(message, 'warning', options); }
		};

		var isLkPage = <?php echo ($option === 'com_users' && $view === 'profile') ? 'true' : 'false'; ?>;
		if (!isLkPage) return;

		try {
			var url = new URL(window.location.href);
			if (url.searchParams.get('booking_success') === '1') {
				window.ViglingNotify.success('Запись была успешно совершена', { timeout: 9000 });
				url.searchParams.delete('booking_success');
				window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
			}
		} catch (e) {}
	})();
	</script>
	<?php
	$quickAuthGuest = $app->getIdentity()->guest;
	if ($quickAuthGuest) :
		$quickAuthToken = Session::getFormToken();
		$quickAuthAjaxUrl = Route::_('index.php?option=com_ajax&plugin=Quickauth&format=json', false);
	?>
	<div id="quick-auth-modal" class="quick-auth-modal" role="dialog" aria-modal="true" aria-labelledby="quick-auth-title" style="display:none;">
		<div class="quick-auth-modal__backdrop"></div>
		<div class="quick-auth-modal__box">
			<button type="button" class="quick-auth-modal__close" aria-label="Закрыть">&times;</button>
			<h2 id="quick-auth-title" class="quick-auth-modal__title">Записаться к мастеру</h2>
			<div class="quick-auth-modal__tab quick-auth-modal__tab--reg" id="quick-auth-tab-reg">
				<p class="quick-auth-modal__hint">Быстрая регистрация</p>
				<form id="quick-auth-form-reg" class="quick-auth-form">
					<input type="hidden" name="<?php echo $quickAuthToken; ?>" value="1">
					<input type="hidden" name="action" value="register">
					<input type="hidden" name="return" id="quick-auth-return-reg" value="">
					<div class="quick-auth-field">
						<label for="quick-auth-name">Имя</label>
						<input type="text" id="quick-auth-name" name="jform[name]" required>
					</div>
					<div class="quick-auth-field">
						<label for="quick-auth-phone">Номер телефона</label>
						<input type="tel" id="quick-auth-phone" class="js-phone-mask" name="jform[profile][phone]" placeholder="+7 (___) ___-__-__">
					</div>
					<div class="quick-auth-field">
						<label for="quick-auth-email">Email *</label>
						<input type="email" id="quick-auth-email" name="jform[email1]" required>
						<span class="quick-auth-field__hint">* для восстановления доступа к вашему профилю</span>
					</div>
					<div class="quick-auth-field">
						<label for="quick-auth-pass1">Пароль</label>
						<input type="password" id="quick-auth-pass1" name="jform[password1]" required>
					</div>
					<div class="quick-auth-field">
						<label for="quick-auth-pass2">Пароль ещё раз</label>
						<input type="password" id="quick-auth-pass2" name="jform[password2]" required>
					</div>
					<input type="hidden" name="jform[username]" id="quick-auth-username" value="">
					<input type="hidden" name="jform[registration_type]" value="client">
					<div class="quick-auth-field quick-auth-msg" id="quick-auth-msg-reg"></div>
					<button type="submit" class="btn btn__time-zapis">Зарегистрироваться и записаться</button>
				</form>
				<p class="quick-auth-modal__switch"><a href="#" id="quick-auth-show-login">У меня уже есть аккаунт</a></p>
			</div>
			<div class="quick-auth-modal__tab quick-auth-modal__tab--login" id="quick-auth-tab-login" style="display:none;">
				<p class="quick-auth-modal__hint">Вход</p>
				<form id="quick-auth-form-login" class="quick-auth-form">
					<input type="hidden" name="<?php echo $quickAuthToken; ?>" value="1">
					<input type="hidden" name="action" value="login">
					<input type="hidden" name="return" id="quick-auth-return-login" value="">
						<div class="quick-auth-field">
							<label for="quick-auth-login-username">Email</label>
							<input type="text" id="quick-auth-login-username" name="username" required>
						</div>
					<div class="quick-auth-field">
						<label for="quick-auth-login-password">Пароль</label>
						<input type="password" id="quick-auth-login-password" name="password" required>
					</div>
					<div class="quick-auth-field quick-auth-msg" id="quick-auth-msg-login"></div>
					<button type="submit" class="btn btn__time-zapis">Войти и перейти к записи</button>
				</form>
				<p class="quick-auth-modal__switch"><a href="#" id="quick-auth-show-reg">Зарегистрироваться</a></p>
			</div>
		</div>
	</div>
	<script>
	(function() {
		var modal = document.getElementById('quick-auth-modal');
		var tabReg = document.getElementById('quick-auth-tab-reg');
		var tabLogin = document.getElementById('quick-auth-tab-login');
		var formReg = document.getElementById('quick-auth-form-reg');
		var formLogin = document.getElementById('quick-auth-form-login');
		var returnReg = document.getElementById('quick-auth-return-reg');
		var returnLogin = document.getElementById('quick-auth-return-login');
		var msgReg = document.getElementById('quick-auth-msg-reg');
		var msgLogin = document.getElementById('quick-auth-msg-login');
		var titleEl = document.getElementById('quick-auth-title');
		var ajaxUrl = <?php echo json_encode($quickAuthAjaxUrl); ?>;
		var currentCallback = null;
		var defaultTitle = 'Записаться к мастеру';

		function showModal(returnUrl, options) {
			options = options || {};
			returnReg.value = returnUrl || '';
			returnLogin.value = returnUrl || '';
			msgReg.textContent = '';
			msgLogin.textContent = '';
			tabReg.style.display = '';
			tabLogin.style.display = 'none';
			modal.style.display = '';
			currentCallback = options.callback || null;
			titleEl.textContent = options.title || defaultTitle;
		}
		function hideModal() {
			modal.style.display = 'none';
			currentCallback = null;
			titleEl.textContent = defaultTitle;
		}

		window.QuickAuth = {
			show: showModal,
			hide: hideModal
		};
		document.querySelectorAll('.btn__time-zapis[data-quick-auth-return]').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				var url = btn.getAttribute('data-quick-auth-return');
				if (!url) return;
				url = url.replace(/&amp;/g, '&');
				e.preventDefault();
				showModal(url);
			});
		});
		if (modal) {
			modal.querySelector('.quick-auth-modal__backdrop').addEventListener('click', hideModal);
			modal.querySelector('.quick-auth-modal__close').addEventListener('click', hideModal);
			document.getElementById('quick-auth-show-login').addEventListener('click', function(e) {
				e.preventDefault();
				tabReg.style.display = 'none';
				tabLogin.style.display = '';
				msgLogin.textContent = '';
			});
			document.getElementById('quick-auth-show-reg').addEventListener('click', function(e) {
				e.preventDefault();
				tabLogin.style.display = 'none';
				tabReg.style.display = '';
				msgReg.textContent = '';
			});
		}
		document.getElementById('quick-auth-email').addEventListener('input', function() {
			document.getElementById('quick-auth-username').value = this.value.trim();
		});
		var phoneInput = document.getElementById('quick-auth-phone');
		if (phoneInput) {
			function formatPhone(val) {
				var digits = val.replace(/\D/g, '');
				if (digits.charAt(0) === '8') digits = '7' + digits.slice(1);
				if (digits.charAt(0) !== '7') digits = '7' + digits;
				digits = digits.slice(0, 11);
				if (digits.length <= 1) return digits ? '+' + digits : '';
				var s = '+7';
				if (digits.length > 1) s += ' (' + digits.slice(1, 4);
				if (digits.length >= 4) s += ') ' + digits.slice(4, 7);
				if (digits.length >= 7) s += '-' + digits.slice(7, 9);
				if (digits.length >= 9) s += '-' + digits.slice(9, 11);
				return s;
			}
			phoneInput.addEventListener('keydown', function(e) {
				if (e.key !== 'Backspace') return;
				var pos = this.selectionStart, val = this.value;
				if (pos <= 0) return;
				var prev = val.charAt(pos - 1);
				if (prev >= '0' && prev <= '9') return;
				e.preventDefault();
				var digits = val.replace(/\D/g, '');
				if (digits.length <= 1) { this.value = ''; return; }
				this.value = formatPhone(digits.slice(0, -1));
				this.setSelectionRange(this.value.length, this.value.length);
			});
			phoneInput.addEventListener('input', function() { this.value = formatPhone(this.value); });
			phoneInput.addEventListener('focus', function() { if (this.value === '') this.value = '+7'; });
			phoneInput.addEventListener('blur', function() { if (this.value === '+7') this.value = ''; });
		}
		function submitForm(form, msgEl) {
			msgEl.textContent = '';
			var fd = new FormData(form);
			fd.append('format', 'json');
			var action = String(fd.get('action') || '');
			var btn = form.querySelector('button[type="submit"]');
			var origText = btn ? btn.textContent : '';
			if (btn) { btn.disabled = true; btn.textContent = '...'; }

			var withRecaptcha = Promise.resolve();
			if (
				action === 'register'
				&& window.ViglingRecaptcha
				&& typeof window.ViglingRecaptcha.getToken === 'function'
				&& typeof window.ViglingRecaptcha.isEnabled === 'function'
				&& window.ViglingRecaptcha.isEnabled()
			) {
				withRecaptcha = window.ViglingRecaptcha.getToken('quickauth_register').then(function (token) {
					if (!token) {
						throw new Error('empty token');
					}
					fd.append('recaptcha_token', token);
					fd.append('recaptcha_action', 'quickauth_register');
				});
			}

			function normalizeResponse(data) {
				if (!data || typeof data !== 'object') return {};
				if (Array.isArray(data.data)) return data.data[0] || {};
				if (data.data && typeof data.data === 'object') return data.data;
				return data;
			}

			withRecaptcha.then(function () {
				return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
			})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					var res = normalizeResponse(data);
					if (res && res.success) {
						try { sessionStorage.removeItem('vigling_registration_state_v3'); } catch (e) {}
						var cb = currentCallback;
						hideModal();
						if (cb && typeof cb === 'function') {
							cb(res);
							return;
						}
						if (res.redirect) {
							window.location.href = res.redirect;
							return;
						}
					}
					if (res && res.reason_key === 'email_verification_blocked' && res.redirect) {
						window.location.href = res.redirect;
						return;
					}
					msgEl.textContent = (res && res.message) ? res.message : 'Ошибка';
					if (btn) { btn.disabled = false; btn.textContent = origText; }
				})
				.catch(function(error) {
					var raw = String((error && error.message) || '');
					if (/recaptcha|token|robot|empty token/i.test(raw)) {
						msgEl.textContent = 'Подтвердите, что вы не робот';
					} else {
						msgEl.textContent = 'Ошибка соединения';
					}
					if (btn) { btn.disabled = false; btn.textContent = origText; }
				});
		}
		formReg.addEventListener('submit', function(e) {
			e.preventDefault();
			document.getElementById('quick-auth-username').value = document.getElementById('quick-auth-email').value.trim();
			submitForm(formReg, msgReg);
		});
		formLogin.addEventListener('submit', function(e) {
			e.preventDefault();
			submitForm(formLogin, msgLogin);
		});
	})();
	</script>
	<?php endif; ?>
	<?php if (!$app->getIdentity()->guest) : ?>
	<script>
	(function () {
		try { sessionStorage.removeItem('vigling_registration_state_v3'); } catch (e) {}
	})();
	</script>
	<?php endif; ?>
</body>
</html>

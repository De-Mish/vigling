<?php

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')->useScript('form.validate');
Factory::getApplication()->getLanguage()->load('plg_system_emailverification', JPATH_SITE . '/plugins/system/emailverification');
Factory::getApplication()->getLanguage()->load('plg_ajax_quickauth', JPATH_SITE);
$showResendVerification = (int) Factory::getApplication()->getUserState('vigling.emailverification.show_resend', 0) === 1;
$resendPrefillEmail = (string) Factory::getApplication()->getUserState('vigling.emailverification.resend_email', '');
$resendReason = (string) Factory::getApplication()->getUserState('vigling.emailverification.reason', '');
$resendInitialMessage = (string) Factory::getApplication()->getUserState('vigling.emailverification.message', '');
Factory::getApplication()->setUserState('vigling.emailverification.show_resend', 0);
Factory::getApplication()->setUserState('vigling.emailverification.resend_email', '');
Factory::getApplication()->setUserState('vigling.emailverification.reason', '');
Factory::getApplication()->setUserState('vigling.emailverification.message', '');
$loginDescription = (string) $this->params->get('login_description', '');
$hasLoginDescription = ((int) $this->params->get('logindescription_show') === 1) && trim($loginDescription) !== '';
$hasLoginImage = trim((string) $this->params->get('login_image', '')) !== '';
$hasTfa = property_exists($this, 'tfa') && !empty($this->tfa);

?>
<div class="login<?php echo $this->pageclass_sfx; ?>">
	<?php if ($this->params->get('show_page_heading')) : ?>
	<div class="container header-bot">
		<h1><?php echo $this->escape($this->params->get('page_heading')); ?></h1>
		<div class="clearFloat"></div>
    </div>	
	<?php endif; ?>
		<?php if ($hasLoginDescription || $hasLoginImage) : ?>
			<div class="login-description">
		<?php endif; ?>
		<?php if ($this->params->get('logindescription_show') == 1) : ?>
			<?php echo $loginDescription; ?>
		<?php endif; ?>
		<?php if ($hasLoginImage) : ?>
			<img src="<?php echo $this->escape($this->params->get('login_image')); ?>" class="login-image" alt="<?php echo Text::_('COM_USERS_LOGIN_IMAGE_ALT'); ?>" />
		<?php endif; ?>
		<?php if ($hasLoginDescription || $hasLoginImage) : ?>
			</div>
		<?php endif; ?>
	<form action="<?php echo Route::_('index.php?option=com_users&task=user.login'); ?>" method="post" class="form-validate form-horizontal well">
		<fieldset>
			<?php echo $this->form->renderFieldset('credentials'); ?>
				<?php if ($hasTfa) : ?>
					<?php echo $this->form->renderField('secretkey'); ?>
				<?php endif; ?>
			<?php if (PluginHelper::isEnabled('system', 'remember')) : ?>
				<div class="control-group">
					<div class="control-label">
						<label for="remember">
							<?php echo Text::_('COM_USERS_LOGIN_REMEMBER_ME'); ?>
						</label>
					</div>
					<div class="controls">
						<input id="remember" type="checkbox" name="remember" class="inputbox" value="yes" />
					</div>
				</div>
			<?php endif; ?>
			<div class="control-group">
				<div class="controls">
					<button type="submit" class="dale">
						<?php echo Text::_('JLOGIN'); ?>
					</button>
				</div>
			</div>
			<?php $return = $this->form->getValue('return', '', $this->params->get('login_redirect_url', $this->params->get('login_redirect_menuitem'))); ?>
			<input type="hidden" name="return" value="<?php echo base64_encode($return); ?>" />
			<?php echo HTMLHelper::_('form.token'); ?>
		</fieldset>
	</form>
	<?php if ($showResendVerification) : ?>
	<div id="login-resend-verification-modal" class="login-resend-modal" style="display:none;">
		<div class="login-resend-modal__backdrop"></div>
		<div class="login-resend-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="login-resend-title">
			<button type="button" class="login-resend-modal__close" aria-label="Закрыть">&times;</button>
			<h3 id="login-resend-title">Подтверждение email</h3>
			<?php if ($resendInitialMessage !== '') : ?>
			<div class="login-resend-modal__warning"><?php echo $this->escape($resendInitialMessage); ?></div>
			<?php endif; ?>
			<div class="login-resend-modal__text">Чтобы активировать аккаунт, отправьте письмо подтверждения еще раз.</div>
			<div class="login-resend-modal__email">
				<span class="login-resend-modal__email-label">Email:</span>
				<span class="login-resend-modal__email-value" id="resend-verification-email" data-email="<?php echo $this->escape($resendPrefillEmail); ?>"><?php echo $this->escape($resendPrefillEmail); ?></span>
			</div>
			<div class="login-resend-modal__actions">
				<button type="button" id="resend-verification-btn" class="dale">Отправить письмо повторно</button>
			</div>
			<div id="resend-verification-msg" class="login-resend-modal__msg"></div>
		</div>
	</div>
	<style>
	.login-resend-modal { position: fixed; inset: 0; z-index: 120000; display: flex; align-items: center; justify-content: center; padding: 14px; }
	.login-resend-modal__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.45); }
	.login-resend-modal__dialog { position: relative; z-index: 1; width: min(92vw, 520px); background: #fff; border-radius: 14px; padding: 22px; box-shadow: 0 10px 30px rgba(0,0,0,.2); }
	.login-resend-modal__close { position: absolute; top: 6px; right: 12px; border: 0; background: transparent; font-size: 34px; line-height: 1; color: #777; cursor: pointer; }
	.login-resend-modal__dialog h3 { margin: 0 24px 10px 0; font-family: "GothamPro-Bold"; font-size: 24px; line-height: 1.2; }
	.login-resend-modal__warning { margin: 0 0 10px; padding: 10px 12px; border-radius: 8px; background: #fff3cd; color: #664d03; font-size: 14px; line-height: 1.4; }
	.login-resend-modal__text { margin: 0 0 12px; color: #444; font-size: 14px; line-height: 1.5; }
	.login-resend-modal__email { margin: 0 0 10px; padding: 10px 12px; border: 1px solid #d0d5dd; border-radius: 8px; background: #f8fafc; word-break: break-word; }
	.login-resend-modal__email-label { color: #667085; margin-right: 6px; font-size: 13px; }
	.login-resend-modal__email-value { color: #111827; font-size: 15px; font-weight: 600; }
	.login-resend-modal__actions { margin-top: 12px; }
	.login-resend-modal__actions .dale { margin-left: 0; margin-top: 0; white-space: nowrap; display: inline-flex; align-items: center; justify-content: center; }
	.login-resend-modal__msg { margin-top: 10px; min-height: 18px; font-size: 14px; line-height: 1.4; }
	.login-resend-modal__msg.is-error { color: #b42318; }
	.login-resend-modal__msg.is-ok { color: #0f766e; }
	</style>
	<?php endif; ?>
</div>
<div>
		<ul class="nav nav-tabs log nav-stacked login-links">
			<li>
				<a class="login-links__item" href="<?php echo Route::_('index.php?option=com_users&view=reset'); ?>">
					<?php echo Text::_('COM_USERS_LOGIN_RESET'); ?>
				</a>
			</li>
			<?php $usersConfig = ComponentHelper::getParams('com_users'); ?>
			<?php if ($usersConfig->get('allowUserRegistration')) : ?>
				<li>
				<a class="login-links__item" href="<?php echo Route::_('index.php?option=com_users&view=registration'); ?>">
					<?php echo Text::_('COM_USERS_LOGIN_REGISTER'); ?>
				</a>
			</li>
		<?php endif; ?>
	</ul>
</div>
<script>
(function () {
	var modal = document.getElementById('login-resend-verification-modal');
	var btn = document.getElementById('resend-verification-btn');
	var emailNode = document.getElementById('resend-verification-email');
	var msg = document.getElementById('resend-verification-msg');
	if (!btn || !emailNode || !msg || !modal) return;

	var tokenName = <?php echo json_encode(Session::getFormToken(), JSON_UNESCAPED_UNICODE); ?>;
	var endpoint = <?php echo json_encode(Route::_('index.php?option=com_ajax&group=ajax&plugin=quickauth&format=json', false), JSON_UNESCAPED_UNICODE); ?>;
	var defaultText = <?php echo json_encode(Text::_('PLG_AJAX_QUICKAUTH_RESEND_GENERIC'), JSON_UNESCAPED_UNICODE); ?>;
	var autoShow = <?php echo $showResendVerification ? 'true' : 'false'; ?>;
	var resendReason = <?php echo json_encode($resendReason, JSON_UNESCAPED_UNICODE); ?>;
	function normalizePayload(json) {
		if (!json || typeof json !== 'object') {
			return {};
		}
		if (Array.isArray(json.data)) {
			return json.data[0] || {};
		}
		if (json.data && typeof json.data === 'object') {
			return json.data;
		}
		if (json.success !== undefined || json.message !== undefined) {
			return json;
		}
		return {};
	}

	function closeModal() {
		modal.style.display = 'none';
	}
	modal.querySelector('.login-resend-modal__backdrop').addEventListener('click', closeModal);
	modal.querySelector('.login-resend-modal__close').addEventListener('click', closeModal);
	if (autoShow && resendReason === 'email_verification_blocked') {
		modal.style.display = 'flex';
	}

	btn.addEventListener('click', function () {
		var email = (emailNode.getAttribute('data-email') || '').trim();
		if (!email) {
			msg.textContent = 'Не удалось определить email аккаунта.';
			msg.className = 'login-resend-modal__msg is-error';
			return;
		}
		btn.disabled = true;
		msg.textContent = '';
		msg.className = 'login-resend-modal__msg';

		var fd = new FormData();
		fd.append(tokenName, '1');
		fd.append('action', 'resend_verification');
		fd.append('email', email);

		fetch(endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		})
		.then(function (r) { return r.json(); })
		.then(function (json) {
			var payload = normalizePayload(json);
			msg.textContent = payload.message || defaultText;
			msg.className = 'login-resend-modal__msg ' + ((payload && payload.success) ? 'is-ok' : 'is-error');
		})
		.catch(function () {
			msg.textContent = defaultText;
			msg.className = 'login-resend-modal__msg is-error';
		})
		.finally(function () {
			btn.disabled = false;
		});
	});
})();
</script>

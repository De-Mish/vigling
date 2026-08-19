<?php
/**
 * @package     Joomla.Site
 * @subpackage  com_users
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
$nullDate = $db->getNullDate();
$emailVerificationText = '—';
$emailVerificationVerified = false;
$emailVerificationGraceLabel = '3 дня';
$emailVerificationHint = '';

$emailVerificationServicePath = JPATH_SITE . '/plugins/system/emailverification/src/Service/EmailVerificationService.php';
if (is_file($emailVerificationServicePath)) {
	require_once $emailVerificationServicePath;
	$emailVerificationServiceClass = '\\Joomla\\Plugin\\System\\Emailverification\\Service\\EmailVerificationService';
	if (class_exists($emailVerificationServiceClass)) {
		$emailVerificationGraceLabel = (string) $emailVerificationServiceClass::getGracePeriodHumanLabel();
	}
}
$emailVerificationHint = 'Аккаунт нужно активировать за ' . $emailVerificationGraceLabel . '. На почту отправлено письмо с подтверждением.';

try {
	$tableName = $db->replacePrefix('#__vigling_email_verifications');
	$db->setQuery('SHOW TABLES LIKE ' . $db->quote($tableName));
	$tableExists = (bool) $db->loadResult();

	if ($tableExists) {
		$query = $db->getQuery(true)
			->select($db->quoteName('status'))
			->from($db->quoteName('#__vigling_email_verifications'))
			->where($db->quoteName('user_id') . ' = ' . (int) ($this->data->id ?? 0))
			->setLimit(1);
		$db->setQuery($query);
		$status = (string) $db->loadResult();

		if ($status === 'verified') {
			$emailVerificationText = 'Да';
			$emailVerificationVerified = true;
		} elseif ($status !== '') {
			$emailVerificationText = 'Нет';
		}
	}
} catch (\Throwable $e) {
	// Ignore DB errors for optional profile indicator.
}

?>
<style>
#users-profile-core .email-verified-value {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	line-height: 1;
}
#users-profile-core .email-verified-check {
	font-size: 14px;
	line-height: 1;
}
</style>







<fieldset id="users-profile-core">
	<dl class="dl-horizontal">
		<dt>
			<?php echo Text::_('COM_USERS_PROFILE_REGISTERED_DATE_LABEL'); ?>
		</dt>
		<dd>
			<?php echo HTMLHelper::_('date', $this->data->registerDate, Text::_('DATE_FORMAT_LC1')); ?>
		</dd>
		<dt>
			<?php echo Text::_('COM_USERS_PROFILE_LAST_VISITED_DATE_LABEL'); ?>
		</dt>
		<?php if ($this->data->lastvisitDate != $nullDate) : ?>
			<dd>
				<?php echo HTMLHelper::_('date', $this->data->lastvisitDate, Text::_('DATE_FORMAT_LC1')); ?>
			</dd>
		<?php else : ?>
			<dd>
				<?php echo Text::_('COM_USERS_PROFILE_NEVER_VISITED'); ?>
			</dd>
		<?php endif; ?>
		<dt title="<?php echo $this->escape($emailVerificationHint); ?>">
			Аккаунт подтвержден
		</dt>
		<dd>
			<span title="<?php echo $this->escape($emailVerificationHint); ?>" class="email-verified-value">
				<?php echo $emailVerificationText; ?>
				<?php if ($emailVerificationVerified) : ?><span class="email-verified-check" aria-hidden="true">✓</span><?php endif; ?>
			</span>
		</dd>
	</dl>
</fieldset>

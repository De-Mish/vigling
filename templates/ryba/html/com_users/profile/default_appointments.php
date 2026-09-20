<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

if (!class_exists(\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::class, false)) {
	require_once JPATH_SITE . '/components/com_orders/src/Helper/AppointmentsHelper.php';
}

$openZapisi = in_array(Factory::getApplication()->getInput()->getCmd('zapisi', ''), ['day', 'week', 'month'], true);
$appointments = new \stdClass();
try {
	\Viglin\Component\Orders\Site\Helper\AppointmentsHelper::fill($appointments);
} catch (\Throwable $e) {
	$appointments->items = [];
	$appointments->appointmentsMode = 'day';
	$appointments->canBookTime = false;
	$appointments->dayUrl = \Viglin\Component\Orders\Site\Helper\AppointmentsHelper::profileUrl(['zapisi' => 'day']);
	$appointments->weekUrl = \Viglin\Component\Orders\Site\Helper\AppointmentsHelper::profileUrl(['zapisi' => 'week']);
	$appointments->monthUrl = \Viglin\Component\Orders\Site\Helper\AppointmentsHelper::profileUrl(['zapisi' => 'month']);
	$appointments->monthCurrentUrl = $appointments->monthUrl;
	$appointments->weekRangeUrl = \Joomla\CMS\Router\Route::_('index.php?option=com_orders&task=orders.weekRange&format=json');
	$appointments->appointmentsBaseUrl = $appointments->dayUrl;
	$appointments->weekPrevUrl = '#';
	$appointments->weekNextUrl = '#';
	$appointments->monthPrevUrl = '#';
	$appointments->monthNextUrl = '#';
	$appointments->appointmentsEmbed = false;
}
?>
<div class="z-content<?php echo $openZapisi ? ' z-active' : ''; ?>" data-index="11" data-name="profile-tab11"<?php echo $openZapisi ? '' : ' style="display: none;"'; ?>>
	<div class="z-content-inner">
		<fieldset id="jsn_zapisi" class="jsn-form-fieldset" data-index="11" data-name="profile-tab11">
			<legend style="display: none;">Записи</legend>
			<?php include JPATH_SITE . '/components/com_orders/tmpl/orders/appointments.php'; ?>
		</fieldset>
	</div>
</div>

<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

require_once __DIR__ . '/_reschedule_helper.php';
require_once __DIR__ . '/_appointments_lib.php';

/** @var \Viglin\Component\Orders\Site\View\Orders\HtmlView $this */
$src = (isset($appointments) && is_object($appointments)) ? $appointments : $this;
$mode = in_array((string) ($src->appointmentsMode ?? 'day'), ['day', 'week', 'month'], true)
	? (string) $src->appointmentsMode
	: 'day';
$items = is_array($src->items ?? null) ? $src->items : [];
$token = Session::getFormToken();
$returnEncoded = base64_encode(Uri::getInstance()->toString());
$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
$user = Factory::getApplication()->getIdentity();
$viewerId = (int) ($user->id ?? 0);
$rescheduleClientAction = Route::_('index.php?option=com_orders&task=orders.reschedule');
$rescheduleMasterAction = Route::_('index.php?option=com_orders&task=orders.rescheduleByMaster');
$rescheduleCourseAction = Route::_('index.php?option=com_orders&task=orders.rescheduleCourseSlotByMaster');
$rescheduleSearchAction = Route::_('index.php?option=com_orders&task=orders.rescheduleSearchSlotByMaster');
$repeatAction = Route::_('index.php?option=com_orders&task=orders.repeat');
$canBookTime = !empty($src->canBookTime);
$dayUrl = (string) ($src->dayUrl ?? $src->appointmentsBaseUrl ?? '');
$weekUrl = (string) ($src->weekUrl ?? '');
$monthUrl = (string) ($src->monthUrl ?? '');
$monthCurrentUrl = (string) ($src->monthCurrentUrl ?? $monthUrl);
$tzName = viglingOrdersGetUserTimezone($db, $viewerId, (string) Factory::getApplication()->get('offset', 'UTC'));
try {
	$tz = new \DateTimeZone($tzName !== '' ? $tzName : 'UTC');
} catch (\Throwable $e) {
	$tz = new \DateTimeZone('UTC');
}
$todayLocal = new \DateTimeImmutable('today', $tz);
$entriesArchive = $mode === 'day' && Factory::getApplication()->getInput()->getCmd('entries', '') === 'archive';
$dayArchiveUrl = (string) ($src->dayArchiveUrl ?? '');
$monthNames = [1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'];
$dowShort = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
?>
<div class="com_orders orders-list orders-list--clients appointments-page" data-mode="<?php echo $this->escape($mode); ?>" aria-label="Записи">
	<style>
	.appointments-page .appointments-toolbar {
		display: flex;
		flex-wrap: wrap;
		gap: 12px 16px;
		align-items: flex-start;
		justify-content: space-between;
		margin: 0 0 18px;
	}
	.appointments-page .appointments-toolbar h1 { margin: 0; }
	.appointments-page .appointments-day-heading {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 12px;
	}
	.appointments-page .appointments-archive-btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-height: 32px;
		padding: 4px 14px;
		border: 1px solid #d8d8d8;
		border-radius: 999px;
		background: #fff;
		color: #222;
		font-size: 13px;
		font-weight: 600;
		line-height: 1.2;
		text-decoration: none;
	}
	.appointments-page .appointments-archive-btn.is-active {
		background: #f9ce54;
		border-color: #f9ce54;
		color: #111;
	}
	.appointments-page .appointments-lead { margin: 6px 0 0; color: #707070; font-size: 13px; }
	.appointments-page .appointments-jump-btn {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		text-decoration: none;
	}
	.appointments-page .appointments-modes {
		display: inline-flex;
		gap: 4px;
		padding: 4px;
		background: #f3f3f3;
		border-radius: 999px;
	}
	.appointments-page .appointments-modes a {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 88px;
		padding: 8px 16px;
		border-radius: 999px;
		color: #222;
		text-decoration: none;
		font-weight: 600;
	}
	.appointments-page .appointments-modes a.is-active {
		background: #f9ce54;
		color: #111;
	}
	.appointments-page .appointments-person { display: block; line-height: 1.35; }
	.appointments-page .appointments-people { display: flex; flex-direction: column; gap: 2px; }
	.appointments-page .journal-card {
		margin-top: 18px;
		background: #fff;
		border: 1px solid #e2e2e2;
		border-radius: 14px;
		padding: 18px;
	}
	.appointments-page .journal-card h2 { margin-top: 0; font-size: 20px; }
	.appointments-page .journal-controls {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		align-items: flex-start;
		margin-bottom: 16px;
	}
	.appointments-page .journal-field { flex: 0 1 220px; }
	.appointments-page .journal-field label { display: block; font-weight: 600; margin-bottom: 6px; line-height: 1.2; }
	.appointments-page .journal-field input,
	.appointments-page .journal-field textarea {
		width: 100%;
		border: 1px solid #cfcfcf;
		border-radius: 10px;
		padding: 11px 12px;
	}
	.appointments-page .journal-field textarea { min-height: 84px; resize: vertical; }
	.appointments-page .journal-selected {
		flex: 1 1 220px;
		min-height: 44px;
		padding: 11px 12px;
		border: 1px dashed #d3d3d3;
		border-radius: 10px;
		background: #fafafa;
		color: #444;
	}
	.appointments-page .journal-submit-wrap { margin-left: auto; }
	.appointments-page .journal-submit,
	.appointments-page #journal-submit,
	.appointments-page #journal-submit.btn-primary {
		background: #f9ce54 !important;
		background-color: #f9ce54 !important;
		color: #3b3636 !important;
		border-color: #f9ce54 !important;
	}
	.appointments-page #journal-calendar { width: 100% !important; max-width: none; margin: 0 !important; }
	.appointments-page #journal-calendar.preload { visibility: visible; }
	.appointments-page .error-msg { display: none; margin-top: 12px; color: #a94442; }
	.appointments-page .appointments-month-wrap {
		width: 55%;
		max-width: 100%;
	}
	.appointments-page .appointments-month-head {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 12px;
		margin: 0 0 12px;
	}
	.appointments-page .appointments-month-title-block {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
		min-width: 0;
	}
	.appointments-page .appointments-month-title { margin: 0; font-size: 20px; font-weight: 700; }
	.appointments-page .appointments-month-nav { display: flex; gap: 8px; }
	.appointments-page .appointments-month-nav a {
		min-width: 40px;
		height: 36px;
		border: 1px solid #bbb;
		border-radius: 8px;
		background: #fff;
		font-size: 22px;
		line-height: 1;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		text-decoration: none;
		color: #111;
	}
	.appointments-page .appointments-cal {
		display: grid;
		grid-template-columns: repeat(7, minmax(0, 1fr));
		border: 1px solid #ececec;
		border-radius: 12px;
		overflow: hidden;
		background: #fff;
	}
	.appointments-page .appointments-cal__dow {
		padding: 10px 6px;
		text-align: center;
		font-size: 12px;
		color: #888;
		background: #fafafa;
		border-bottom: 1px solid #ececec;
	}
	.appointments-page .appointments-cal__day {
		min-height: 86px;
		padding: 8px 8px 10px;
		border-right: 1px solid #f0f0f0;
		border-bottom: 1px solid #f0f0f0;
		background: #fff;
		text-align: left;
		cursor: pointer;
		color: inherit;
		width: 100%;
	}
	.appointments-page .appointments-cal__day:nth-child(7n) { border-right: 0; }
	.appointments-page .appointments-cal__num { display: block; font-size: 16px; font-weight: 600; }
	.appointments-page .appointments-cal__count {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		width: 100%;
		max-width: 100%;
		box-sizing: border-box;
		margin-top: 8px;
		color: #666;
		line-height: 1.15;
		container-type: inline-size;
	}
	.appointments-page .appointments-cal__count-num {
		display: block;
		font-size: 12px;
		line-height: 1.2;
		white-space: nowrap;
	}
	.appointments-page .appointments-cal__count-word {
		display: block;
		max-width: 100%;
		font-size: 12px;
		font-size: min(12px, 24cqi);
		line-height: 1.15;
		white-space: nowrap;
	}
	.appointments-page .appointments-cal__day.is-today { background: #fff8df; }
	.appointments-page .appointments-cal__day.is-past,
	.appointments-page .appointments-cal__day.is-other { color: #9a9a9a; background: #f7f7f7; }
	.appointments-page .appointments-cal__day.is-other { color: #b0b0b0; }
	.appointments-page .appointments-day-panel { display: none; }
	.appointments-page .appointments-day-panel.is-open { display: block; }
	.appointments-page .appointments-day-backdrop {
		display: none;
		position: fixed;
		inset: 0;
		z-index: 1020;
		background: rgba(17, 17, 17, .45);
	}
	.appointments-page .appointments-day-overlay {
		display: none;
		position: fixed;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
		z-index: 1030;
		width: min(1180px, calc(100% - 24px));
		max-height: calc(100vh - 24px);
		background: #fff;
		border-radius: 12px;
		box-shadow: 0 10px 40px rgba(0,0,0,.22);
		overflow: auto;
	}
	.appointments-page.is-day-open .appointments-day-backdrop,
	.appointments-page.is-day-open .appointments-day-overlay { display: block; }
	.appointments-page .appointments-day-overlay__close {
		position: sticky;
		top: 8px;
		float: right;
		z-index: 2;
		width: 36px;
		height: 36px;
		margin: 8px 8px 0 0;
		border: 0;
		border-radius: 50%;
		background: #f3f3f3;
		font-size: 26px;
		line-height: 1;
		cursor: pointer;
	}
	.appointments-page .appointments-day-overlay__title {
		margin: 20px 56px 16px 20px;
		font-size: 22px;
	}
	.appointments-page .appointments-day-overlay__body { padding: 0 20px 20px; }
	.com_orders .orders-table .orders-row--past { background-color: #e9ecef; color: #6c757d; }
	.com_orders .orders-table .orders-row--past a { color: #495057; }
	.com_orders .orders-table .orders-actions .btn { margin-right: 6px; margin-bottom: 4px; }
	.com_orders .orders-table .orders-row--course-summary { background: #fffdf3; }
	.com_orders .orders-table .orders-row--course-details { background: #fffdfb; display: none; }
	.com_orders .orders-table .orders-row--course-details.is-open { display: table-row; }
	.com_orders .orders-table .course-meta { font-size: 13px; color: #666; margin-top: 4px; }
	.com_orders .orders-table .course-participants { display: grid; gap: 12px; }
	.com_orders .orders-table .course-participant { border: 1px solid #ece4b6; border-radius: 10px; padding: 12px; background: #fff; }
	.com_orders .orders-table .course-participant-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; flex-wrap: wrap; }
	.com_orders .orders-table .course-participant-name { font-weight: 600; }
	.com_orders .orders-table .course-participant-time { color: #555; }
	.com_orders .orders-table .course-toggle[aria-expanded="true"] .course-toggle-open { display: none; }
	.com_orders .orders-table .course-toggle[aria-expanded="false"] .course-toggle-close { display: none; }
	.com_orders .reschedule-open { margin-right: 6px; margin-bottom: 4px; }
	.com_orders button.repeat-open.review-zlink {
		margin-left: 8px !important;
		margin-right: 0 !important;
		margin-bottom: 4px !important;
		vertical-align: middle;
	}
	.com_orders .order-comment { margin-top: 6px; font-size: 13px; color: #555; }
	.com_orders .order-feedback { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
	.com_orders .order-feedback-form { margin-top: 8px; }
	.com_orders .order-feedback-form textarea {
		display: block;
		width: 100%;
		max-width: 360px;
		margin: 4px 0 8px;
		padding: 6px 8px;
		box-sizing: border-box;
		font-size: 13px;
	}
	.com_orders .order-feedback-label,
	.com_orders .order-feedback-inline { display: block; font-size: 13px; color: #555; margin-bottom: 4px; }
	.com_orders .order-feedback-inline { margin: 0 0 8px; }
	.com_orders .order-review-card { margin-top: 8px; font-size: 13px; color: #555; }
	.com_orders .order-review-card__head { font-weight: 600; color: #333; }
	.appointments-page .appointments-cards {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.appointments-page .appointments-card {
		display: flex;
		flex-direction: column;
		flex-wrap: nowrap;
		width: 100%;
		border: 1px solid #d9d9d9;
		border-radius: 12px;
		padding: 12px;
		background: #fff;
		box-shadow: 0 2px 8px rgba(0,0,0,0.06);
		box-sizing: border-box;
	}
	.appointments-page .appointments-card > * {
		width: 100%;
		max-width: 100%;
		flex: 0 0 auto;
		float: none;
	}
	.appointments-page .appointments-card__people {
		display: grid;
		grid-template-columns: 110px minmax(0, 1fr);
		grid-auto-flow: row;
		align-items: start;
		column-gap: 10px;
		row-gap: 2px;
		padding: 6px 0;
		line-height: 1.25;
	}
	.appointments-page .appointments-card.orders-row--past {
		background-color: #e9ecef;
		color: #6c757d;
	}
	.appointments-page .appointments-card.orders-row--past a { color: #495057; }
	.appointments-page .appointments-card.orders-row--course-summary { background: #fffdf3; }
	.appointments-page .appointments-card__row {
		display: grid;
		grid-template-columns: 110px minmax(0, 1fr);
		align-items: start;
		column-gap: 10px;
		padding: 6px 0;
		line-height: 1.25;
	}
	.appointments-page .appointments-card__label {
		font-weight: 600;
		color: #666;
		white-space: normal;
	}
	.appointments-page .appointments-card__value {
		display: block;
		min-width: 0;
		overflow-wrap: anywhere;
		word-break: break-word;
	}
	.appointments-page .appointments-card__row--people { padding-top: 2px; padding-bottom: 2px; }
	.appointments-page .appointments-card__row--feedback { padding-top: 8px; }
	.appointments-page .appointments-card__row--actions {
		margin-top: 8px;
		padding-top: 10px;
		border-top: 1px solid #ececec;
	}
	.appointments-page .appointments-card__row--actions .orders-actions .btn,
	.appointments-page .appointments-card__row--actions .orders-actions .reschedule-open {
		min-width: 114px;
		margin: 0 8px 8px 0;
		text-align: center;
	}
	.appointments-page .appointments-card__row--actions .form-inline { display: inline-block; }
	.appointments-page .appointments-card .order-feedback,
	.appointments-page .appointments-card .order-feedback-form {
		display: block;
		float: none;
		width: 100%;
		max-width: 100%;
		columns: 1;
		column-count: 1;
	}
	.appointments-page .appointments-card .order-feedback-form textarea {
		max-width: 100%;
	}
	@media (min-width: 769px) {
		.appointments-page .appointments-lead { display: none; }
		.appointments-page .appointments-card {
			display: flex !important;
			flex-direction: column !important;
			flex-wrap: nowrap !important;
		}
		.appointments-page .appointments-card > * {
			width: 100% !important;
			max-width: 100% !important;
			flex: 0 0 auto !important;
			float: none !important;
		}
		.appointments-page .appointments-card__people,
		.appointments-page .appointments-card__row {
			display: grid !important;
			grid-template-columns: 110px minmax(0, 1fr) !important;
			grid-auto-flow: row !important;
			float: none !important;
			width: 100% !important;
			max-width: 100% !important;
		}
		.appointments-page .appointments-card__label,
		.appointments-page .appointments-card__value,
		.appointments-page .appointments-people,
		.appointments-page .appointments-person {
			display: block !important;
			float: none !important;
			width: auto !important;
			max-width: 100% !important;
		}
		.appointments-page .appointments-people {
			display: flex !important;
			flex-direction: column !important;
			flex-wrap: nowrap !important;
			gap: 2px !important;
		}
		.appointments-page .appointments-person { width: 100% !important; }
		.appointments-page .appointments-card .order-feedback,
		.appointments-page .appointments-card .order-feedback-form {
			display: block !important;
			float: none !important;
			width: 100% !important;
			max-width: 100% !important;
			columns: 1 !important;
			column-count: 1 !important;
		}
	}
	.appointments-page .appointments-card__participants { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid #ececec; }
	.appointments-page .appointments-card__participants.is-open { display: block; }
	.appointments-page .course-participants { display: grid; gap: 12px; }
	.appointments-page .course-participant { border: 1px solid #ece4b6; border-radius: 10px; padding: 12px; background: #fff; }
	.appointments-page .course-participant-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; flex-wrap: wrap; }
	.appointments-page .course-participant-name { font-weight: 600; }
	.appointments-page .course-participant-time { color: #555; }
	.appointments-page .course-meta { font-size: 13px; color: #666; margin-top: 4px; }
	.appointments-page .course-toggle[aria-expanded="true"] .course-toggle-open { display: none; }
	.appointments-page .course-toggle[aria-expanded="false"] .course-toggle-close { display: none; }
	#zapis-reschedule .modal-dialog {
		width: 96vw !important;
		max-width: 1180px !important;
		margin: 12px auto !important;
	}
	#zapis-reschedule .modal-content { overflow: hidden; }
	#zapis-reschedule .modal-body { overflow: hidden; padding: 20px 28px 28px; }
	#zapis-reschedule .calendar__master.preload { visibility: visible; }
	#zapis-reschedule #reschedule-calendar {
		width: 100% !important;
		max-width: 800px;
		margin: 0 auto !important;
	}
	#zapis-reschedule #reschedule-calendar .slick-list { margin: 0 -8px; padding: 4px 0 10px; overflow: hidden; }
	#zapis-reschedule #reschedule-calendar .slick-slide,
	#zapis-reschedule #reschedule-calendar .slick-slide > div { height: auto !important; }
	#zapis-reschedule #reschedule-calendar .calendar__master-item { padding: 0 8px; box-sizing: border-box; }
	#zapis-reschedule #reschedule-calendar .btns-m { padding-top: 2px; }
	#zapis-reschedule #reschedule-calendar .calendar__master-item .btns-m {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		gap: 6px;
		padding: 2px 2px 0;
	}
	#zapis-reschedule #reschedule-calendar .btns-m .btn-select {
		width: 100% !important;
		min-width: 0 !important;
		height: auto !important;
		margin: 0 !important;
		padding: 6px 2px !important;
		font-size: 11px !important;
		line-height: 1.4 !important;
		border-radius: 6px !important;
		box-sizing: border-box !important;
		text-align: center !important;
		background-color: #fff !important;
		border: 1px solid #e0e0e0 !important;
		box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
	}
	#zapis-reschedule #reschedule-calendar .btns-m .btn-select.reserved {
		background-color: #f0f0f0 !important;
		color: #555 !important;
		border-color: #e8e8e8 !important;
	}
	#zapis-reschedule #reschedule-calendar .btns-m input:checked + .btn-select {
		background-color: #f7cc53 !important;
		border-color: #f7cc53 !important;
		box-shadow: 0 0 0 2px rgba(247, 204, 83, 0.3) !important;
	}
	#zapis-reschedule #reschedule-calendar .slick-prev,
	#zapis-reschedule #reschedule-calendar .slick-next { z-index: 5; }
	#zapis-reschedule .calc__btn { padding-left: 0; display: flex; justify-content: center; gap: 16px; }
	#zapis-reschedule .calc__btn .btn-next,
	#zapis-reschedule .calc__btn .close__btn { float: none; margin: 0; }
	#zapis-reschedule .calendar-hint { margin: 6px 0 14px; font-size: 13px; color: #777; }
	#zapis-reschedule .calendar-hint i { font-style: normal; color: #111; }
	#zapis-reschedule .line-no { display: inline-block; color: #999; font-size: 13px; margin-top: 8px; }
	#zapis-reschedule .error-msg { color: #a94442; margin-top: 10px; display: none; }
	#zapis-reschedule .btn-next.is-loading { pointer-events: none; opacity: .9; }
	#zapis-reschedule .btn-next .btn-spinner {
		display: none;
		width: 14px;
		height: 14px;
		margin-right: 8px;
		border: 2px solid rgba(0, 0, 0, .25);
		border-top-color: #000;
		border-radius: 50%;
		animation: appointmentsSpin .75s linear infinite;
		vertical-align: middle;
	}
	#zapis-reschedule .btn-next.is-loading .btn-spinner { display: inline-block; }
	@keyframes appointmentsSpin { to { transform: rotate(360deg); } }
	.com_orders.orders-list--clients .orders-table { display: block; border: 0; background: transparent; }
	.com_orders.orders-list--clients .orders-table thead { display: none; }
	.com_orders.orders-list--clients .orders-table tbody { display: block; }
	.com_orders.orders-list--clients .orders-table .orders-row {
		display: block;
		border: 1px solid #d9d9d9;
		border-radius: 12px;
		margin-bottom: 14px;
		padding: 12px;
		background: #fff;
		box-shadow: 0 2px 8px rgba(0,0,0,0.06);
	}
	.com_orders.orders-list--clients .orders-table .orders-row td {
		display: grid;
		grid-template-columns: 96px minmax(0, 1fr);
		align-items: start;
		column-gap: 10px;
		border: 0;
		padding: 6px 0;
		line-height: 1.25;
	}
	.com_orders.orders-list--clients .orders-table .orders-row td::before {
		content: attr(data-label);
		font-weight: 600;
		color: #666;
		display: block;
	}
	.com_orders.orders-list--clients .orders-table .orders-row td a {
		overflow-wrap: anywhere;
		word-break: break-word;
	}
	.com_orders .order-comment { white-space: pre-wrap; }
	.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions {
		display: block;
		margin-top: 8px;
		padding-top: 10px;
		border-top: 1px solid #ececec;
	}
	.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions::before { display: block; margin-bottom: 8px; }
	.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions .btn,
	.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions .reschedule-open {
		min-width: 114px;
		margin: 0 8px 8px 0;
		text-align: center;
	}
	.com_orders.orders-list--clients .orders-table .orders-row td.orders-actions .form-inline { display: inline-block; }
	.com_orders.orders-list--clients .orders-table .orders-row--course-details { display: none; }
	.com_orders.orders-list--clients .orders-table .orders-row--course-details.is-open { display: block; }
	.com_orders.orders-list--clients .orders-table .orders-row--course-details td {
		display: block;
		grid-template-columns: none;
	}
	.com_orders.orders-list--clients .orders-table .orders-row--course-details td::before { display: none; }
	@media (max-width: 768px) {
		.appointments-page .appointments-month-wrap { width: 100%; }
		.appointments-page .appointments-modes { width: 100%; }
		.appointments-page .appointments-modes a { flex: 1 1 0; min-width: 0; }
		.appointments-page .appointments-cal__day { min-height: 64px; padding: 6px; }
		#zapis-reschedule .modal-dialog {
			width: min(96vw, 520px) !important;
			margin: 12px auto !important;
		}
		#zapis-reschedule .modal-content {
			max-height: calc(100vh - 24px);
			display: flex;
			flex-direction: column;
		}
		#zapis-reschedule .modal-body {
			overflow: hidden;
		}
		#zapis-reschedule .calc__body { padding-bottom: 8px; }
		#zapis-reschedule #reschedule-calendar .calendar__master-item { max-height: calc(100vh - 260px); overflow: hidden; }
		#zapis-reschedule #reschedule-calendar .calendar__master-item .btns-m {
			max-height: calc(100vh - 360px);
			overflow-y: auto;
			-webkit-overflow-scrolling: touch;
			padding-right: 4px;
			margin-bottom: 0;
		}
		#zapis-reschedule .calc__btn { display: flex; flex-direction: column; gap: 12px; padding-left: 0; }
		#zapis-reschedule .calc__btn .btn-next,
		#zapis-reschedule .calc__btn .close__btn { width: 100%; margin: 0; float: none; }
	}
	</style>

	<div class="appointments-toolbar">
		<div>
			<?php if ($mode === 'day') : ?>
			<div class="appointments-day-heading">
				<h1 class="page-title">Записи</h1>
				<a class="appointments-archive-btn<?php echo $entriesArchive ? ' is-active' : ''; ?>" href="<?php echo $this->escape($entriesArchive ? $dayUrl : $dayArchiveUrl); ?>">Архив</a>
			</div>
			<?php endif; ?>
		</div>
		<nav class="appointments-modes" aria-label="Режим записей">
			<a href="<?php echo $this->escape($dayUrl); ?>" class="<?php echo ($mode === 'day' && !$entriesArchive) ? 'is-active' : ''; ?>">День</a>
			<a href="<?php echo $this->escape($weekUrl); ?>" class="<?php echo $mode === 'week' ? 'is-active' : ''; ?>">Неделя</a>
			<a href="<?php echo $this->escape($monthUrl); ?>" class="<?php echo $mode === 'month' ? 'is-active' : ''; ?>">Месяц</a>
		</nav>
	</div>

	<?php if ($mode === 'week') : ?>
		<?php include __DIR__ . '/journal.php'; ?>
	<?php elseif ($mode === 'month') : ?>
		<?php
		$monthStart = !empty($src->monthCursor) && $src->monthCursor instanceof \DateTimeImmutable
			? $src->monthCursor
			: $todayLocal->modify('first day of this month')->setTime(0, 0, 0);
		$monthKey = $monthStart->format('Y-m');
		$gridStart = $monthStart->modify('-' . ((int) $monthStart->format('N') - 1) . ' days')->setTime(0, 0, 0);
		$monthLast = $monthStart->modify('+1 month')->modify('-1 day');
		$gridEnd = $monthLast->modify('+' . (7 - (int) $monthLast->format('N')) . ' days');
		$byDay = [];
		foreach ($items as $item) {
			if (empty($item->time)) {
				continue;
			}
			try {
				$local = (new \DateTimeImmutable((string) $item->time, new \DateTimeZone('UTC')))->setTimezone($tz);
			} catch (\Throwable $e) {
				continue;
			}
			$key = $local->format('Y-m-d');
			$byDay[$key] = $byDay[$key] ?? [];
			$byDay[$key][] = $item;
		}
		?>
		<div class="appointments-month-wrap">
			<div class="appointments-month-head">
				<div class="appointments-month-title-block">
					<h2 class="appointments-month-title"><?php echo $this->escape(($monthNames[(int) $monthStart->format('n')] ?? '') . ' ' . $monthStart->format('Y')); ?></h2>
					<a class="btn btn-xs btn-default appointments-jump-btn" href="<?php echo $this->escape($monthCurrentUrl); ?>">
						<i class="jsn-icon jsn-icon-calendar"></i> Текущий месяц
					</a>
				</div>
				<div class="appointments-month-nav">
					<a href="<?php echo $this->escape((string) ($src->monthPrevUrl ?? '')); ?>" aria-label="Назад">‹</a>
					<a href="<?php echo $this->escape((string) ($src->monthNextUrl ?? '')); ?>" aria-label="Вперёд">›</a>
				</div>
			</div>
			<div class="appointments-cal" role="grid">
				<?php foreach ($dowShort as $dowLabel) : ?>
					<div class="appointments-cal__dow"><?php echo $this->escape($dowLabel); ?></div>
				<?php endforeach; ?>
				<?php
				for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')) :
					$key = $day->format('Y-m-d');
					$count = isset($byDay[$key]) ? count($byDay[$key]) : 0;
					$isOther = $day->format('Y-m') !== $monthKey;
					$isPast = $day < $todayLocal;
					$isToday = $key === $todayLocal->format('Y-m-d');
					$classes = 'appointments-cal__day';
					if ($isOther) {
						$classes .= ' is-other';
					}
					if ($isPast) {
						$classes .= ' is-past';
					}
					if ($isToday) {
						$classes .= ' is-today';
					}
				?>
					<button
						type="button"
						class="<?php echo $classes; ?>"
						data-date="<?php echo $this->escape($key); ?>"
						data-date-label="<?php echo $this->escape($day->format('d.m.Y')); ?>"
					>
						<span class="appointments-cal__num"><?php echo (int) $day->format('j'); ?></span>
						<?php if ($count > 0) : ?>
							<?php [$countNum, $countWord] = viglingAppointmentsCountParts($count); ?>
							<span class="appointments-cal__count">
								<span class="appointments-cal__count-num"><?php echo $this->escape($countNum); ?></span>
								<span class="appointments-cal__count-word"><?php echo $this->escape($countWord); ?></span>
							</span>
						<?php endif; ?>
					</button>
				<?php endfor; ?>
			</div>
		</div>

		<div class="appointments-day-backdrop" id="appointments-day-backdrop" hidden></div>
		<div class="appointments-day-overlay" id="appointments-day-overlay" hidden>
			<button type="button" class="appointments-day-overlay__close" id="appointments-day-overlay-close" aria-label="Закрыть">&times;</button>
			<h2 class="appointments-day-overlay__title" id="appointments-day-modal-title">Записи</h2>
			<div class="appointments-day-overlay__body">
				<?php
				foreach ($byDay as $dateKey => $dayItems) :
					$displayRows = viglingAppointmentsBuildDisplayRows($dayItems, $viewerId);
					$rescheduleAction = $rescheduleClientAction;
					$emptyMessage = 'На этот день записей нет.';
				?>
				<div class="appointments-day-panel" data-date="<?php echo $this->escape($dateKey); ?>">
					<?php include __DIR__ . '/_appointments_list.php'; ?>
				</div>
				<?php endforeach; ?>
				<p class="alert alert-info appointments-day-empty" hidden>На этот день записей нет.</p>
			</div>
		</div>
		<?php
		$rescheduleAction = $rescheduleMasterAction;
		include __DIR__ . '/_appointments_modal.php';
		if ($canBookTime) :
			$addAction = Route::_('index.php?option=com_orders&task=orders.journalAdd');
			$payload = viglingOrdersBuildRescheduleSlots($db, $viewerId, 15, 0, 0, 45);
			$days = $payload['days'] ?? [];
			include __DIR__ . '/_journal_book.php';
		endif;
		?>
		<script>
		(function(){
			var page = document.querySelector('.appointments-page');
			var overlay = document.getElementById('appointments-day-overlay');
			var backdrop = document.getElementById('appointments-day-backdrop');
			var closeBtn = document.getElementById('appointments-day-overlay-close');
			var title = document.getElementById('appointments-day-modal-title');
			var empty = overlay ? overlay.querySelector('.appointments-day-empty') : null;
			if (!page || !overlay) return;
			function closeDay() {
				page.classList.remove('is-day-open');
				overlay.hidden = true;
				if (backdrop) backdrop.hidden = true;
				document.body.style.overflow = '';
			}
			function openDay(date, label) {
				if (title) title.textContent = 'Записи на ' + label;
				var found = false;
				overlay.querySelectorAll('.appointments-day-panel').forEach(function(panel){
					var open = panel.getAttribute('data-date') === date;
					panel.classList.toggle('is-open', open);
					if (open) found = true;
				});
				if (empty) empty.hidden = found;
				page.classList.add('is-day-open');
				overlay.hidden = false;
				if (backdrop) backdrop.hidden = false;
				document.body.style.overflow = 'hidden';
			}
			document.querySelectorAll('.appointments-cal__day[data-date]').forEach(function(btn){
				btn.addEventListener('click', function(){
					openDay(btn.getAttribute('data-date') || '', btn.getAttribute('data-date-label') || '');
				});
			});
			if (closeBtn) closeBtn.addEventListener('click', closeDay);
			if (backdrop) backdrop.addEventListener('click', closeDay);
			document.addEventListener('keydown', function(e){
				if (e.key !== 'Escape' || !page.classList.contains('is-day-open')) return;
				var rescheduleModal = document.getElementById('zapis-reschedule');
				if (rescheduleModal && rescheduleModal.classList.contains('in')) return;
				closeDay();
			});
		})();
		</script>
	<?php else : ?>
		<?php
		$nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$futureItems = [];
		$pastItems = [];
		foreach ($items as $item) {
			$stamp = null;
			if (!empty($item->time)) {
				try {
					$stamp = new \DateTimeImmutable((string) $item->time, new \DateTimeZone('UTC'));
				} catch (\Throwable $e) {
					$stamp = null;
				}
			}
			if ($stamp instanceof \DateTimeImmutable && $stamp < $nowUtc) {
				$pastItems[] = $item;
			} else {
				$futureItems[] = $item;
			}
		}
		usort($pastItems, static function ($a, $b): int {
			return strcmp((string) ($b->time ?? ''), (string) ($a->time ?? ''));
		});
		$listItems = $entriesArchive ? $pastItems : $futureItems;
		$displayRows = viglingAppointmentsBuildDisplayRows($listItems, $viewerId);
		$rescheduleAction = $rescheduleClientAction;
		$emptyMessage = $entriesArchive ? 'Архив пуст' : 'У вас пока нет записей.';
		include __DIR__ . '/_appointments_list.php';
		$rescheduleAction = $rescheduleMasterAction;
		include __DIR__ . '/_appointments_modal.php';
		?>
	<?php endif; ?>
</div>

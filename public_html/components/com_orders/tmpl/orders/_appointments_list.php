<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;

/** @var \Viglin\Component\Orders\Site\View\Orders\HtmlView $this */
/** @var array $displayRows */
/** @var string $token */
/** @var string $returnEncoded */
/** @var \Joomla\Database\DatabaseInterface $db */
/** @var string $rescheduleAction */
/** @var string $rescheduleCourseAction */
/** @var string $rescheduleSearchAction */
/** @var string $emptyMessage */

$emptyMessage = $emptyMessage ?? 'Записей пока нет.';
$utc = new \DateTimeZone('UTC');
$nowUtc = new \DateTime('now', $utc);
$onProfile = \Joomla\CMS\Factory::getApplication()->getInput()->getCmd('option') === 'com_users';

require_once JPATH_SITE . '/components/com_orders/src/Helper/GroupRescheduleHelper.php';
$grViewerId = (int) (\Joomla\CMS\Factory::getApplication()->getIdentity()->id ?? 0);
$grPending = \Viglin\Component\Orders\Site\Helper\GroupRescheduleHelper::pendingRepliesForUser($db, $grViewerId);
$grMasterOpen = \Viglin\Component\Orders\Site\Helper\GroupRescheduleHelper::openProposalsForMaster($db, $grViewerId);
$grIso = static function (string $utc): string {
	try {
		return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->format('c');
	} catch (\Throwable $e) {
		return '';
	}
};
?>
<?php if (empty($displayRows)) : ?>
	<p class="alert alert-info<?php echo $emptyMessage === 'На этот день записей нет.' ? ' appointments-empty--day' : ''; ?>"><?php echo $this->escape($emptyMessage); ?><?php if (!$onProfile) : ?> <a href="<?php echo Route::_('index.php?option=com_users&view=profile'); ?>">Перейти в профиль</a><?php endif; ?></p>
<?php else : ?>
	<div class="appointments-cards">
			<?php foreach ($displayRows as $row) :
				if (in_array(($row['type'] ?? ''), ['course-group', 'search-group'], true)) :
					$item = $row['item'];
					$isSearchGroup = ($row['kind'] ?? '') === 'search';
					$entityLabel = $isSearchGroup ? 'Поиск моделей' : 'Курс';
					$participants = $row['participants'] ?? [];
					$participantCount = count($participants);
					$capacityTotal = $isSearchGroup
						? (int) ($item->search_slot_capacity_total ?? $item->search_capacity ?? 0)
						: (int) ($item->course_slot_capacity_total ?? $item->course_capacity ?? 0);
					$timeUtc = $item->time ? new \DateTime($item->time, $utc) : null;
					$timeIso = $timeUtc ? $timeUtc->format('c') : '';
					$isPast = $timeUtc ? ($timeUtc < $nowUtc) : false;
					$rowClass = 'appointments-card orders-row orders-row--course-summary appointments-card--' . ($isSearchGroup ? 'search' : 'course') . ($isPast ? ' orders-row--past' : '');
					$toggleId = ($isSearchGroup ? 'search' : 'course') . '-participants-' . (int) $row['slot_id'];
					$entityTitle = trim((string) ($item->service_display_name ?? $item->service_name));
					$entityCategoryTitle = trim((string) ($isSearchGroup ? ($item->search_category_title ?? '') : ($item->course_category_title ?? '')));
				?>
				<div class="<?php echo $rowClass; ?>" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">
					<div class="appointments-card__row appointments-card__row--who">
						<div class="appointments-card__label">Клиент / Мастер</div>
						<div class="appointments-card__value">
							<strong><?php echo $this->escape($entityLabel); ?></strong>
							<div class="course-meta"><?php echo (int) $participantCount; ?> из <?php echo $capacityTotal > 0 ? (int) $capacityTotal : '—'; ?> мест занято</div>
						</div>
					</div>
					<div class="appointments-card__row appointments-card__row--service">
						<div class="appointments-card__label">Услуга</div>
						<div class="appointments-card__value">
							<?php echo htmlspecialchars($entityTitle !== '' ? $entityTitle : $entityLabel); ?>
							<?php if ($entityCategoryTitle !== '') : ?>
								<div class="course-meta"><?php echo htmlspecialchars($entityCategoryTitle); ?></div>
							<?php endif; ?>
						</div>
					</div>
					<div class="appointments-card__row appointments-card__row--when">
						<div class="appointments-card__label">Дата и время</div>
						<div class="appointments-card__value"><span class="lk-time-utc" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">—</span></div>
					</div>
					<div class="appointments-card__row appointments-card__row--contacts">
						<div class="appointments-card__label">Контакты</div>
						<div class="appointments-card__value">Участники внутри слота</div>
					</div>
					<?php
					$grOpen = $grMasterOpen[($isSearchGroup ? 'search' : 'course') . ':' . (int) $row['slot_id']] ?? null;
					if ($grOpen !== null) :
						$grProposal = $grOpen['proposal'];
						$grCounts = $grOpen['counts'];
						$grNewIso = $grIso((string) $grProposal['new_start_utc']);
					?>
					<div class="gr-panel">
						<div class="gr-panel__title">Перенос ждёт вашего подтверждения</div>
						<div class="gr-panel__stats">
							Новое время: <span class="lk-time-utc" data-time-utc="<?php echo $this->escape($grNewIso); ?>">—</span><br>
							Согласились: <?php echo (int) $grCounts['agreed']; ?>,
							ждут ответа: <?php echo (int) $grCounts['pending']; ?>,
							отказались и сняты с записи: <?php echo (int) $grCounts['declined']; ?>.
							Согласившиеся будут перенесены, не ответившие — сняты с записи. Подтвердить можно, не дожидаясь всех.
						</div>
						<div class="gr-panel__actions">
							<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.applyGroupReschedule'); ?>" class="form-inline" style="display:inline;">
								<input type="hidden" name="<?php echo $token; ?>" value="1">
								<input type="hidden" name="proposal_id" value="<?php echo (int) $grProposal['id']; ?>">
								<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
								<button type="submit" class="btn btn-sm btn-success"<?php echo (int) $grCounts['pending'] > 0 ? ' onclick="return confirm(\'Не ответили участников: ' . (int) $grCounts['pending'] . '. Они будут сняты с записи. Подтвердить перенос?\');"' : ''; ?>>Подтвердить перенос</button>
							</form>
							<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.cancelGroupReschedule'); ?>" class="form-inline" style="display:inline;">
								<input type="hidden" name="<?php echo $token; ?>" value="1">
								<input type="hidden" name="proposal_id" value="<?php echo (int) $grProposal['id']; ?>">
								<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
								<button type="submit" class="btn btn-sm btn-default" onclick="return confirm('Отменить предложение о переносе? Запись останется на прежнее время.');">Отменить перенос</button>
							</form>
						</div>
					</div>
					<?php endif; ?>
					<div class="appointments-card__row appointments-card__row--actions">
						<div class="appointments-card__label">Действия</div>
						<div class="appointments-card__value orders-actions">
							<?php echo $isSearchGroup
								? viglingAppointmentsRenderSearchSlotActions($item, $isPast, $token, $returnEncoded, $rescheduleSearchAction, $db)
								: viglingAppointmentsRenderCourseSlotActions($item, $isPast, $token, $returnEncoded, $rescheduleCourseAction, $db); ?>
							<button type="button" class="btn btn-xs btn-default course-toggle" data-target="<?php echo $this->escape($toggleId); ?>" aria-expanded="false">
								<span class="course-toggle-open">Показать участников</span>
								<span class="course-toggle-close">Свернуть участников</span>
							</button>
						</div>
					</div>
					<div class="appointments-card__participants" id="<?php echo $this->escape($toggleId); ?>">
						<div class="course-participants">
							<?php foreach ($participants as $participant) :
								$participantTimeUtc = $participant->time ? new \DateTime($participant->time, $utc) : null;
								$participantTimeIso = $participantTimeUtc ? $participantTimeUtc->format('c') : '';
								$participantIsPast = $participantTimeUtc ? ($participantTimeUtc < $nowUtc) : false;
								$participantContacts = viglingAppointmentsContactBits($participant);
							?>
							<div class="course-participant">
								<div class="course-participant-head">
									<div>
										<div class="course-participant-name"><?php echo viglingAppointmentsRenderPeople($participant); ?><?php
											$grState = $grOpen['by_booking'][(int) $participant->id] ?? '';
											if ($grState === 'agreed') : ?><span class="gr-chip gr-chip--agreed">согласен на перенос</span><?php elseif ($grState === 'pending') : ?><span class="gr-chip gr-chip--pending">ждём ответ</span><?php endif; ?></div>
										<div class="course-meta"><?php echo $participantContacts !== [] ? htmlspecialchars(implode(', ', $participantContacts)) : '—'; ?></div>
										<?php if (trim((string) ($participant->comment ?? '')) !== '') : ?>
											<div class="order-comment"><?php echo htmlspecialchars((string) $participant->comment); ?></div>
										<?php endif; ?>
										<?php
										$item = $participant;
										$role = !empty($participant->_viewer_is_master) ? 'master' : 'client';
										$isPast = $participantIsPast;
										$reviewsByDirection = is_array($participant->_reviews ?? null) ? $participant->_reviews : [];
										include __DIR__ . '/_feedback.php';
										?>
									</div>
									<div class="course-participant-time">
										<span class="lk-time-utc" data-time-utc="<?php echo $participantTimeIso ? $this->escape($participantTimeIso) : ''; ?>">—</span>
									</div>
								</div>
								<div class="orders-actions">
									<?php echo viglingAppointmentsRenderItemActions($participant, $participantIsPast, $token, $returnEncoded, $participantTimeIso, $db, $rescheduleAction); ?>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<?php
					continue;
				endif;
				$item = $row['item'];
				$timeUtc = $item->time ? new \DateTime((string) ($item->time ?? ''), $utc) : null;
				$timeIso = $timeUtc ? $timeUtc->format('c') : '';
				$isPast = $timeUtc ? ($timeUtc < $nowUtc) : false;
				$completed = !empty($item->completed);
				$isBlock = (($row['type'] ?? '') === 'block') || (int) ($item->user_id ?? 0) <= 0;
				$rowClass = 'appointments-card orders-row' . ($isBlock ? '' : ' appointments-card--service') . ($isPast ? ' orders-row--past' : '');
				$contacts = !empty($item->_viewer_is_master) ? viglingAppointmentsContactBits($item) : [];
				$serviceLabel = trim((string) ($item->service_display_name ?? $item->service_name ?? ''));
				if ($isBlock) {
					[$blockLabel, $blockComment] = viglingAppointmentsParseJournalLabel(trim((string) ($item->service_name ?? '')));
					$serviceLabel = $blockLabel;
					if (trim((string) ($item->comment ?? '')) !== '') {
						$blockComment = trim((string) $item->comment);
					}
				} else {
					$blockComment = '';
				}
			?>
			<div class="<?php echo $rowClass; ?>" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">
				<?php if ($isBlock) : ?>
				<div class="appointments-card__row appointments-card__row--who">
					<div class="appointments-card__label">Клиент /<br>Мастер</div>
					<div class="appointments-card__value"><?php echo viglingAppointmentsRenderPeople($item); ?></div>
				</div>
				<?php else : ?>
				<div class="appointments-card__people">
					<div class="appointments-card__label">Клиент /</div>
					<div class="appointments-card__value">
						<div class="appointments-person">• <?php echo viglingAppointmentsPersonLink((int) ($item->user_id ?? 0), trim((string) ($item->client_name ?? '—')) ?: '—'); ?></div>
					</div>
					<div class="appointments-card__label">Мастер</div>
					<div class="appointments-card__value">
						<div class="appointments-person">• <?php echo viglingAppointmentsPersonLink((int) ($item->master_id ?? 0), trim((string) ($item->master_name ?? '—')) ?: '—'); ?></div>
					</div>
				</div>
				<?php endif; ?>
				<div class="appointments-card__row appointments-card__row--service">
					<div class="appointments-card__label">Услуга</div>
					<div class="appointments-card__value">
						<?php echo htmlspecialchars($serviceLabel); ?>
						<?php if ($completed) : ?>
							<div class="course-meta">Выполнено</div>
						<?php endif; ?>
						<?php if (trim((string) ($item->comment ?? '')) !== '' && !$isBlock) : ?>
							<div class="order-comment"><?php echo htmlspecialchars((string) $item->comment); ?></div>
						<?php endif; ?>
						<?php if ($isBlock && $blockComment !== '') : ?>
							<div class="order-comment"><?php echo htmlspecialchars($blockComment); ?></div>
						<?php endif; ?>
					</div>
				</div>
				<?php if (!$isBlock && $isPast) : ?>
				<div class="appointments-card__row appointments-card__row--feedback">
					<div class="appointments-card__label"></div>
					<div class="appointments-card__value">
						<?php
						$role = !empty($item->_viewer_is_master) ? 'master' : 'client';
						$reviewsByDirection = is_array($item->_reviews ?? null) ? $item->_reviews : [];
						include __DIR__ . '/_feedback.php';
						?>
					</div>
				</div>
				<?php endif; ?>
				<div class="appointments-card__row appointments-card__row--when">
					<div class="appointments-card__label">Дата и время</div>
					<div class="appointments-card__value"><span class="lk-time-utc" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">—</span></div>
				</div>
				<div class="appointments-card__row appointments-card__row--contacts">
					<div class="appointments-card__label">Контакты</div>
					<div class="appointments-card__value"><?php echo $contacts !== [] ? htmlspecialchars(implode(', ', $contacts)) : '—'; ?></div>
				</div>
				<?php
				$grRequest = !$isBlock ? ($grPending[(int) ($item->id ?? 0)] ?? null) : null;
				if ($grRequest !== null) :
					$grLabel = ($grRequest['kind'] ?? '') === 'search' ? 'поиск моделей' : 'курс';
				?>
				<div class="gr-request">
					<div class="gr-request__title">Мастер предлагает перенести <?php echo $this->escape($grLabel); ?> «<?php echo $this->escape((string) $grRequest['title']); ?>»</div>
					<div class="gr-request__times">
						Было: <span class="lk-time-utc" data-time-utc="<?php echo $this->escape($grIso((string) $grRequest['old_start_utc'])); ?>">—</span><br>
						Станет: <strong><span class="lk-time-utc" data-time-utc="<?php echo $this->escape($grIso((string) $grRequest['new_start_utc'])); ?>">—</span></strong>
					</div>
					<form method="post" action="<?php echo Route::_('index.php?option=com_orders&task=orders.respondGroupReschedule'); ?>" class="gr-request__actions">
						<input type="hidden" name="<?php echo $token; ?>" value="1">
						<input type="hidden" name="reply_id" value="<?php echo (int) $grRequest['reply_id']; ?>">
						<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
						<button type="submit" name="decision" value="agree" class="btn btn-success">Согласен(на) на перенос</button>
						<button type="submit" name="decision" value="decline" class="btn btn-default" onclick="return confirm('Отказаться от записи? Она будет снята.');">Отказаться от записи</button>
					</form>
					<div class="gr-request__hint">Новое время вступит в силу после подтверждения мастера. Выберите один из двух вариантов.</div>
				</div>
				<?php endif; ?>
				<div class="appointments-card__row appointments-card__row--actions">
					<div class="appointments-card__label">Действия</div>
					<div class="appointments-card__value orders-actions">
						<?php echo viglingAppointmentsRenderItemActions($item, $isPast, $token, $returnEncoded, $timeIso, $db, $rescheduleAction); ?>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
	</div>
<?php endif; ?>
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
?>
<?php if (empty($displayRows)) : ?>
	<p class="alert alert-info"><?php echo $this->escape($emptyMessage); ?><?php if (!$onProfile) : ?> <a href="<?php echo Route::_('index.php?option=com_users&view=profile'); ?>">Перейти в профиль</a><?php endif; ?></p>
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
					$rowClass = 'appointments-card orders-row orders-row--course-summary' . ($isPast ? ' orders-row--past' : '');
					$toggleId = ($isSearchGroup ? 'search' : 'course') . '-participants-' . (int) $row['slot_id'];
					$entityTitle = trim((string) ($item->service_display_name ?? $item->service_name));
					$entityCategoryTitle = trim((string) ($isSearchGroup ? ($item->search_category_title ?? '') : ($item->course_category_title ?? '')));
				?>
				<div class="<?php echo $rowClass; ?>" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">
					<div class="appointments-card__row">
						<div class="appointments-card__label">Клиент / Мастер</div>
						<div class="appointments-card__value">
							<strong><?php echo $this->escape($entityLabel); ?></strong>
							<div class="course-meta"><?php echo (int) $participantCount; ?> из <?php echo $capacityTotal > 0 ? (int) $capacityTotal : '—'; ?> мест занято</div>
						</div>
					</div>
					<div class="appointments-card__row">
						<div class="appointments-card__label">Услуга</div>
						<div class="appointments-card__value">
							<?php echo htmlspecialchars($entityTitle !== '' ? $entityTitle : $entityLabel); ?>
							<?php if ($entityCategoryTitle !== '') : ?>
								<div class="course-meta"><?php echo htmlspecialchars($entityCategoryTitle); ?></div>
							<?php endif; ?>
						</div>
					</div>
					<div class="appointments-card__row">
						<div class="appointments-card__label">Дата и время</div>
						<div class="appointments-card__value"><span class="lk-time-utc" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">—</span></div>
					</div>
					<div class="appointments-card__row">
						<div class="appointments-card__label">Контакты</div>
						<div class="appointments-card__value">Участники внутри слота</div>
					</div>
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
										<div class="course-participant-name"><?php echo viglingAppointmentsRenderPeople($participant); ?></div>
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
				$rowClass = 'appointments-card orders-row' . ($isPast ? ' orders-row--past' : '');
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
				<div class="appointments-card__row">
					<div class="appointments-card__label">Клиент / Мастер</div>
					<div class="appointments-card__value"><?php echo viglingAppointmentsRenderPeople($item); ?></div>
				</div>
				<div class="appointments-card__row">
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
						<?php if (!$isBlock) :
							$role = !empty($item->_viewer_is_master) ? 'master' : 'client';
							$reviewsByDirection = is_array($item->_reviews ?? null) ? $item->_reviews : [];
							include __DIR__ . '/_feedback.php';
						endif; ?>
					</div>
				</div>
				<div class="appointments-card__row">
					<div class="appointments-card__label">Дата и время</div>
					<div class="appointments-card__value"><span class="lk-time-utc" data-time-utc="<?php echo $timeIso ? $this->escape($timeIso) : ''; ?>">—</span></div>
				</div>
				<div class="appointments-card__row">
					<div class="appointments-card__label">Контакты</div>
					<div class="appointments-card__value"><?php echo $contacts !== [] ? htmlspecialchars(implode(', ', $contacts)) : '—'; ?></div>
				</div>
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

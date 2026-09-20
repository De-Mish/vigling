<?php
\defined('_JEXEC') or die;

/** @var string $journalCellPart */
/** @var array $boardDays */

$journalCellPart = (string) ($journalCellPart ?? '');
if ($journalCellPart === 'heads') :
	foreach ($boardDays as $day) : ?>
		<div class="journal-day-head<?php echo !empty($day['is_today']) ? ' is-today' : ''; ?>" data-date="<?php echo $this->escape((string) $day['date']); ?>">
			<span class="dow"><?php echo $this->escape((string) $day['dow_label']); ?></span>
			<span class="date"><?php echo (int) $day['day_num']; ?></span>
			<span class="month"><?php echo $this->escape((string) $day['month_label']); ?></span>
		</div>
	<?php endforeach;
elseif ($journalCellPart === 'cols') :
	foreach ($boardDays as $day) : ?>
		<div class="journal-day-col<?php echo !empty($day['is_today']) ? ' is-today' : ''; ?>" data-date="<?php echo $this->escape((string) $day['date']); ?>" style="height: <?php echo (int) round($gridHeight * $pxPerMin); ?>px;">
			<?php
			if (!empty($day['is_today'])) :
				$nowLocal = $nowUtc->setTimezone($journalTz);
				$nowMin = ((int) $nowLocal->format('H')) * 60 + (int) $nowLocal->format('i');
				if ($nowMin >= $gridStart && $nowMin <= $gridEnd) :
			?>
				<div class="journal-now" style="top: <?php echo (int) round(($nowMin - $gridStart) * $pxPerMin); ?>px;"></div>
			<?php
				endif;
			endif;
			foreach ($day['events'] as $event) :
				$cols = max(1, (int) ($event['cols'] ?? 1));
				$col = (int) ($event['col'] ?? 0);
				$top = (int) round(($event['startMin'] - $gridStart) * $pxPerMin);
				$height = max(46, (int) round(($event['endMin'] - $event['startMin']) * $pxPerMin));
				$width = 'calc(' . (100 / $cols) . '% - 6px)';
				$left = 'calc(' . (($col / $cols) * 100) . '% + 3px)';
				$timeLabel = $formatMinutes((int) $event['startMin']) . '–' . $formatMinutes((int) $event['endMin']);
			?>
			<button
				type="button"
				class="journal-event <?php echo $kindClass((string) $event['kind']); ?><?php echo (!empty($event['startLocal']) && $event['startLocal'] < $nowUtc->setTimezone($journalTz)) ? ' is-past' : ''; ?>"
				style="top: <?php echo $top; ?>px; height: <?php echo $height; ?>px; left: <?php echo $left; ?>; width: <?php echo $width; ?>;"
				data-event-id="<?php echo $this->escape((string) $event['id']); ?>"
			>
				<span class="journal-event__time"><?php echo $this->escape($timeLabel); ?></span>
				<span class="journal-event__title"><?php echo $this->escape((string) $event['title']); ?></span>
				<span class="journal-event__service"><?php echo $this->escape((string) $event['service']); ?></span>
			</button>
			<?php endforeach; ?>
		</div>
	<?php endforeach;
elseif ($journalCellPart === 'details') :
	foreach ($boardDays as $day) :
		foreach ($day['events'] as $event) :
			$row = $event['row'];
			$item = $event['item'];
			$isPast = $event['startLocal'] < $nowUtc->setTimezone($journalTz);
			$timeText = $event['startLocal']->format('d.m.Y H:i') . ' – ' . $event['endLocal']->format('H:i');
			?>
			<div class="journal-detail" data-event-id="<?php echo $this->escape((string) $event['id']); ?>">
				<?php if (($row['type'] ?? '') === 'block') : ?>
					<h2><?php echo $this->escape((string) $event['title']); ?></h2>
					<div class="journal-detail__grid">
						<div class="journal-detail__label">Услуга</div>
						<div>Забронировать время</div>
						<div class="journal-detail__label">Дата и время</div>
						<div><?php echo $this->escape($timeText); ?></div>
						<div class="journal-detail__label">Комментарий</div>
						<div><?php echo $this->escape((string) ($item->_journal_comment ?? '—')); ?></div>
					</div>
					<div class="journal-detail__actions">
						<form method="post" action="<?php echo $deleteAction; ?>" class="form-inline" style="display:inline;">
							<input type="hidden" name="<?php echo $token; ?>" value="1">
							<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
							<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
							<button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Удалить блок времени?');">Удалить</button>
						</form>
					</div>
				<?php elseif (in_array(($row['type'] ?? ''), ['course-group', 'search-group'], true)) :
					$isSearchGroup = ($row['kind'] ?? '') === 'search';
					$entityLabel = $isSearchGroup ? 'Поиск моделей' : 'Курс';
					$participants = $row['participants'] ?? [];
					$participantCount = count($participants);
					$capacityTotal = $isSearchGroup
						? (int) ($item->search_slot_capacity_total ?? $item->search_capacity ?? 0)
						: (int) ($item->course_slot_capacity_total ?? $item->course_capacity ?? 0);
					$entityTitle = trim((string) ($item->service_display_name ?? $item->service_name ?? $entityLabel));
				?>
					<h2><?php echo $this->escape($entityTitle); ?></h2>
					<div class="journal-detail__grid">
						<div class="journal-detail__label">Клиент</div>
						<div><?php echo $this->escape($entityLabel); ?> · <?php echo (int) $participantCount; ?> из <?php echo $capacityTotal > 0 ? (int) $capacityTotal : '—'; ?></div>
						<div class="journal-detail__label">Услуга</div>
						<div><?php echo $this->escape($entityTitle); ?></div>
						<div class="journal-detail__label">Дата и время</div>
						<div><?php echo $this->escape($timeText); ?></div>
						<div class="journal-detail__label">Контакты</div>
						<div>Участники внутри слота</div>
					</div>
					<div class="journal-detail__actions">
						<?php echo $isSearchGroup
							? $renderSearchSlotActions($item, $isPast, $token, $returnEncoded, $rescheduleSearchAction)
							: $renderCourseSlotActions($item, $isPast, $token, $returnEncoded, $rescheduleCourseAction); ?>
					</div>
					<div class="course-participants">
						<?php foreach ($participants as $participant) :
							$participantTimeUtc = !empty($participant->time) ? new \DateTimeImmutable((string) $participant->time, $utc) : null;
							$participantTimeIso = $participantTimeUtc ? $participantTimeUtc->format('c') : '';
							$participantIsPast = $participantTimeUtc ? ($participantTimeUtc < $nowUtc) : false;
							$participantContacts = $contactBits($participant);
							$clientProfileUrl = rtrim(Uri::root(true), '/') . '/' . (int) $participant->user_id;
						?>
						<div class="course-participant">
							<div class="course-participant-name">
								<?php if (($participant->client_name ?? '—') !== '—' && (int) $participant->user_id > 0) : ?>
									<a href="<?php echo htmlspecialchars($clientProfileUrl); ?>"><?php echo htmlspecialchars((string) $participant->client_name); ?></a>
								<?php else : ?>
									<?php echo htmlspecialchars((string) ($participant->client_name ?? '—')); ?>
								<?php endif; ?>
							</div>
							<div><?php echo $participantContacts !== [] ? htmlspecialchars(implode(', ', $participantContacts)) : '—'; ?></div>
							<?php if (trim((string) ($participant->comment ?? '')) !== '') : ?>
								<div class="order-comment"><?php echo htmlspecialchars((string) $participant->comment); ?></div>
							<?php endif; ?>
							<div class="journal-detail__actions">
								<?php echo $renderOrderActions($participant, $participantIsPast, !empty($participant->completed), $token, $returnEncoded, $participantTimeIso); ?>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				<?php else :
					$clientProfileUrl = rtrim(Uri::root(true), '/') . '/' . (int) $item->user_id;
					$contacts = $contactBits($item);
					$completed = !empty($item->completed);
					$viewerIsMaster = !empty($item->_viewer_is_master)
						|| ((int) ($item->master_id ?? 0) > 0 && (int) ($item->master_id ?? 0) === (int) ($user->id ?? 0));
				?>
					<?php if (!$viewerIsMaster) : ?>
					<h2><?php echo viglingAppointmentsRenderPeople($item); ?></h2>
					<div class="journal-detail__grid">
						<div class="journal-detail__label">Клиент</div>
						<div><?php echo viglingAppointmentsPersonLink((int) ($item->user_id ?? 0), trim((string) ($item->client_name ?? '—'))); ?></div>
						<div class="journal-detail__label">Мастер</div>
						<div><?php echo viglingAppointmentsPersonLink((int) ($item->master_id ?? 0), trim((string) ($item->master_name ?? '—'))); ?></div>
						<div class="journal-detail__label">Услуга</div>
						<div>
							<?php echo htmlspecialchars((string) ($item->service_display_name ?? $item->service_name ?? '—')); ?>
							<?php if (trim((string) ($item->comment ?? '')) !== '') : ?>
								<div class="order-comment"><?php echo htmlspecialchars((string) $item->comment); ?></div>
							<?php endif; ?>
						</div>
						<div class="journal-detail__label">Дата и время</div>
						<div><?php echo $this->escape($timeText); ?></div>
					</div>
					<div class="journal-detail__actions">
						<?php echo viglingAppointmentsRenderClientActions($item, $isPast, $token, $returnEncoded, (string) $event['timeIso'], $db, $rescheduleClientAction, false); ?>
					</div>
					<?php else : ?>
					<h2>
						<?php if (($item->client_name ?? '—') !== '—' && (int) $item->user_id > 0) : ?>
							<a href="<?php echo htmlspecialchars($clientProfileUrl); ?>"><?php echo htmlspecialchars((string) $item->client_name); ?></a>
						<?php else : ?>
							<?php echo htmlspecialchars((string) ($item->client_name ?? 'Клиент')); ?>
						<?php endif; ?>
					</h2>
					<div class="journal-detail__grid">
						<div class="journal-detail__label">Клиент</div>
						<div><?php echo htmlspecialchars((string) ($item->client_name ?? '—')); ?></div>
						<div class="journal-detail__label">Услуга</div>
						<div>
							<?php echo htmlspecialchars((string) ($item->service_display_name ?? $item->service_name ?? '—')); ?>
							<?php if (trim((string) ($item->comment ?? '')) !== '') : ?>
								<div class="order-comment"><?php echo htmlspecialchars((string) $item->comment); ?></div>
							<?php endif; ?>
						</div>
						<div class="journal-detail__label">Дата и время</div>
						<div><?php echo $this->escape($timeText); ?></div>
						<div class="journal-detail__label">Контакты</div>
						<div><?php echo $contacts !== [] ? htmlspecialchars(implode(', ', $contacts)) : '—'; ?></div>
					</div>
					<div class="journal-detail__actions">
						<?php echo $renderOrderActions($item, $isPast, $completed, $token, $returnEncoded, (string) $event['timeIso']); ?>
					</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endforeach;
	endforeach;
endif;

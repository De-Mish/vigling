<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Viglin\Component\Orders\Site\Helper\ReviewHelper;

if (!class_exists(ReviewHelper::class)) {
	require_once JPATH_SITE . '/components/com_orders/src/Helper/ReviewHelper.php';
}

/**
 * @var object $item
 * @var string $role client|master
 * @var string $token
 * @var string $returnEncoded
 * @var bool $isPast
 * @var array<string, object> $reviewsByDirection
 */
$role = $role ?? 'client';
$isPast = !empty($isPast);
$reviewsByDirection = is_array($reviewsByDirection ?? null) ? $reviewsByDirection : [];
$ownDirection = $role === 'master'
	? ReviewHelper::DIRECTION_MASTER_TO_CLIENT
	: ReviewHelper::DIRECTION_CLIENT_TO_MASTER;
$otherDirection = $role === 'master'
	? ReviewHelper::DIRECTION_CLIENT_TO_MASTER
	: ReviewHelper::DIRECTION_MASTER_TO_CLIENT;
$ownReview = $reviewsByDirection[$ownDirection] ?? null;
$otherReview = $reviewsByDirection[$otherDirection] ?? null;
$ownComment = $role === 'master'
	? trim((string) ($item->master_after_comment ?? ''))
	: trim((string) ($item->client_after_comment ?? ''));
$otherComment = $role === 'master'
	? trim((string) ($item->client_after_comment ?? ''))
	: trim((string) ($item->master_after_comment ?? ''));
$ownCommentLabel = $role === 'master' ? 'Ваш комментарий' : 'Ваш комментарий';
$otherCommentLabel = $role === 'master' ? 'Комментарий клиента' : 'Комментарий мастера';
$ownReviewLabel = $role === 'master' ? 'Ваш отзыв о клиенте' : 'Ваш отзыв о мастере';
$otherReviewLabel = $role === 'master' ? 'Отзыв клиента' : 'Отзыв мастера';
$reviewAction = Route::_('index.php?option=com_orders&task=orders.review');
$commentAction = Route::_('index.php?option=com_orders&task=orders.commentAfter');
$ownRating = $ownReview ? (int) $ownReview->rating : 5;
$ownText = $ownReview ? trim((string) ($ownReview->review_text ?? '')) : '';
$ownAnonymous = $ownReview ? (int) ($ownReview->is_anonymous ?? 0) === 1 : false;
?>
<?php if ($isPast) : ?>
<div class="order-feedback">
	<?php if ($otherComment !== '') : ?>
		<div class="order-comment order-after-comment"><?php echo htmlspecialchars($otherCommentLabel . ': ' . $otherComment); ?></div>
	<?php endif; ?>
	<form method="post" action="<?php echo $commentAction; ?>" class="order-feedback-form">
		<input type="hidden" name="<?php echo $token; ?>" value="1">
		<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
		<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
		<label class="order-feedback-label" for="after-comment-<?php echo (int) $item->id; ?>-<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($ownCommentLabel); ?></label>
		<textarea id="after-comment-<?php echo (int) $item->id; ?>-<?php echo htmlspecialchars($role); ?>" name="after_comment" rows="2" maxlength="500" placeholder="Комментарий к прошедшей записи"><?php echo htmlspecialchars($ownComment); ?></textarea>
		<button type="submit" class="btn btn-xs btn-default">Сохранить комментарий</button>
	</form>

	<?php if ($otherReview) : ?>
		<div class="order-review-card">
			<div class="order-review-card__head"><?php echo htmlspecialchars($otherReviewLabel); ?>: <?php echo htmlspecialchars(ReviewHelper::displayName($otherReview)); ?></div>
			<div class="order-review-card__rating">Оценка: <?php echo number_format((int) $otherReview->rating, 1, '.', ''); ?></div>
			<?php if (trim((string) ($otherReview->review_text ?? '')) !== '') : ?>
				<div class="order-review-card__text"><?php echo htmlspecialchars((string) $otherReview->review_text); ?></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo $reviewAction; ?>" class="order-feedback-form">
		<input type="hidden" name="<?php echo $token; ?>" value="1">
		<input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
		<input type="hidden" name="return" value="<?php echo $returnEncoded; ?>">
		<div class="order-feedback-label"><?php echo htmlspecialchars($ownReviewLabel); ?></div>
		<label class="order-feedback-inline">Оценка
			<select name="rating">
				<?php for ($r = 5; $r >= 1; $r--) : ?>
					<option value="<?php echo $r; ?>"<?php echo $ownRating === $r ? ' selected' : ''; ?>><?php echo $r; ?></option>
				<?php endfor; ?>
			</select>
		</label>
		<textarea name="review_text" rows="2" maxlength="1000" placeholder="Текст отзыва"><?php echo htmlspecialchars($ownText); ?></textarea>
		<label class="order-feedback-inline">
			<input type="checkbox" name="anonymous" value="1"<?php echo $ownAnonymous ? ' checked' : ''; ?>>
			Анонимный отзыв
		</label>
		<button type="submit" class="btn btn-xs btn-default"><?php echo $ownReview ? 'Обновить отзыв' : 'Оставить отзыв'; ?></button>
	</form>
</div>
<?php endif; ?>

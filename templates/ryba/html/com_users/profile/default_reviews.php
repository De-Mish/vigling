<?php
defined('_JEXEC') or die;

use Viglin\Component\Orders\Site\Helper\ReviewHelper;

if (!class_exists(ReviewHelper::class)) {
	require_once JPATH_SITE . '/components/com_orders/src/Helper/ReviewHelper.php';
}

$profileReviews = is_array($profileReviews ?? null) ? $profileReviews : [];
?>
<div class="review__master lk-client-reviews">
	<h2>Отзывы</h2>
	<div id="review__master" class="review__master-head">
		<?php if ($profileReviews === []) : ?>
			<span class="easylast_noentry">Нет отзывов</span>
		<?php else : ?>
			<span><?php echo count($profileReviews); ?></span>
		<?php endif; ?>
	</div>
	<?php if ($profileReviews !== []) : ?>
		<div class="review__master-body">
			<?php foreach ($profileReviews as $review) : ?>
				<div class="review__master-body-item">
					<div class="review__item-data">
						<span><?php echo $this->escape(ReviewHelper::displayName($review)); ?></span>
						<i><?php echo $this->escape(!empty($review->created) ? date('d.m.Y', strtotime((string) $review->created)) : ''); ?></i>
					</div>
					<div class="review__item-rate">
						<?php echo number_format((int) $review->rating, 1, '.', ''); ?>
						<ul class="category_cinfo-ratings">
							<?php for ($r = 1; $r <= 5; $r++) : ?>
								<?php if ($r <= (int) $review->rating) : ?>
									<li><i class="fa fa-star" aria-hidden="true"></i></li>
								<?php endif; ?>
							<?php endfor; ?>
						</ul>
					</div>
					<?php if (trim((string) ($review->review_text ?? '')) !== '') : ?>
						<div class="review__text"><?php echo $this->escape((string) $review->review_text); ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<div class="clearFloat"></div>
		</div>
	<?php endif; ?>
</div>
<style>
.lk-client-reviews { margin-top: 24px; padding-top: 8px; }
.lk-client-reviews .review__master-body-item { margin-bottom: 20px; }
</style>

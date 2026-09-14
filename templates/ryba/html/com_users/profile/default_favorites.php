<?php
defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Viglin\Component\Poisk\Site\Helper\PoiskHelper;
use Joomla\Plugin\User\Vigling\Helper\ImageUploadHelper;

$favorites = is_array($this->lkFavorites ?? null) ? $this->lkFavorites : [];
$favAjax = (string) ($this->lkFavoritesAjax ?? '');
$favTokenName = (string) ($this->lkFavoritesTokenName ?? '');
$favTokenValue = (string) ($this->lkFavoritesTokenValue ?? '1');
$favCommentMax = 90;
if (class_exists(\Viglin\Plugin\Ajax\Quickauth\Helper\FavoritesHelper::class)) {
	$favCommentMax = (int) \Viglin\Plugin\Ajax\Quickauth\Helper\FavoritesHelper::COMMENT_MAX;
}

$fieldsByUser = [];
$userIds = array_map(static function ($row) {
	return (int) ($row['id'] ?? 0);
}, $favorites);
$userIds = array_values(array_filter($userIds));
if ($userIds !== [] && class_exists(PoiskHelper::class)) {
	try {
		$fieldsByUser = PoiskHelper::getFieldsForUserIds($userIds, [
			'sity', 'area', 'street', 'house_number', 'about', 'o_sebe', 'avatar', 'portfolio_field', 'home',
			'payment_method', 'suitable_for_children',
		]);
	} catch (\Throwable $e) {
		$fieldsByUser = [];
	}
}

if (!class_exists(ImageUploadHelper::class, false)) {
	$imgHelper = JPATH_PLUGINS . '/user/vigling/src/Helper/ImageUploadHelper.php';
	if (is_file($imgHelper)) {
		require_once $imgHelper;
	}
}

$resolveImageUrl = static function (string $rawValue, bool $preferPortfolio = false): string {
	$rawValue = trim($rawValue);
	if ($rawValue === '') {
		return '';
	}
	$decoded = json_decode($rawValue, true);
	if (is_string($decoded)) {
		$rawValue = trim($decoded);
	} elseif (is_array($decoded)) {
		$rawValue = trim((string) reset($decoded));
	}
	if ($rawValue === '') {
		return '';
	}
	if (stripos($rawValue, 'http://') === 0 || stripos($rawValue, 'https://') === 0) {
		return $rawValue;
	}
	$clean = str_replace('\\', '/', ltrim($rawValue, '/'));
	if (preg_match('#^(portfolio|portfolio/|images/portfolio|images/portfolio/)$#i', $clean)) {
		return '';
	}
	if (stripos($clean, 'images/portfolio/') === 0 || stripos($clean, 'images/profiler/') === 0 || stripos($clean, 'images/') === 0) {
		return '/' . $clean;
	}
	if ($preferPortfolio || stripos($clean, 'portfolio/') === 0 || preg_match('/^portfolio_field/i', $clean)) {
		if (stripos($clean, 'portfolio/') === 0) {
			return '/images/' . $clean;
		}
		return '/images/portfolio/' . $clean;
	}
	return '/images/profiler/' . $clean;
};

$extractFirstPortfolioImage = static function (string $rawValue, callable $resolver): string {
	$rawValue = trim($rawValue);
	if ($rawValue === '') {
		return '';
	}
	$decoded = json_decode($rawValue, true);
	$candidates = [];
	if (is_array($decoded)) {
		$iter = new RecursiveIteratorIterator(new RecursiveArrayIterator($decoded));
		foreach ($iter as $v) {
			if (is_scalar($v)) {
				$candidates[] = (string) $v;
			}
		}
	} else {
		$candidates[] = $rawValue;
	}
	foreach ($candidates as $candidate) {
		$url = $resolver($candidate, true);
		if ($url !== '' && !str_ends_with(strtolower($url), '/true')) {
			return $url;
		}
	}
	return '';
};

$homeLabels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];
$defaultImg = '/templates/ryba/images/master.png';
?>
<div class="z-content" data-index="10" data-name="profile-tab10" style="display: none;">
	<div class="z-content-inner">
		<fieldset id="jsn_favorites" class="jsn-form-fieldset" data-index="10" data-name="profile-tab10">
			<legend style="display: none;">Избранное</legend>
			<div class="category jsn_list lk-favorites-list">
				<div class="category__masters">
					<?php if ($favorites === []) : ?>
						<div class="lk-favorites-empty">Список пуст</div>
					<?php else : ?>
						<?php foreach ($favorites as $fav) :
							$itemId = (int) ($fav['id'] ?? 0);
							if ($itemId <= 0) {
								continue;
							}
							$itemName = trim((string) ($fav['name'] ?? ''));
							if ($itemName === '') {
								$itemName = 'Профиль #' . $itemId;
							}
							$favComment = (string) ($fav['comment'] ?? '');
							$fields = $fieldsByUser[$itemId] ?? $fieldsByUser[(string) $itemId] ?? [];
							$sity = trim((string) ($fields['sity'] ?? ''));
							$area = trim((string) ($fields['area'] ?? ''));
							$street = trim((string) ($fields['street'] ?? ''));
							$house = trim((string) ($fields['house_number'] ?? ''));
							$addr = implode(', ', array_filter([$sity, $area, $street, $house]));
							$about = trim((string) ($fields['about'] ?? ''));
							if ($about === '') {
								$about = trim((string) ($fields['o_sebe'] ?? ''));
							}
							$aboutDecoded = json_decode($about, true);
							if (is_string($aboutDecoded)) {
								$about = trim($aboutDecoded);
							}
							$avatar = trim((string) ($fields['avatar'] ?? ''));
							$portfolioRaw = trim((string) ($fields['portfolio_field'] ?? ''));
							$homeVal = $fields['home'] ?? '';
							$homeParts = [];
							if ($homeVal !== '' && $homeVal !== '[]') {
								$decodedHome = json_decode((string) $homeVal, true);
								if (is_array($decodedHome)) {
									foreach ($decodedHome as $v) {
										if (isset($homeLabels[(int) $v])) {
											$homeParts[] = $homeLabels[(int) $v];
										}
									}
								}
							}
							$portfolioImage = $extractFirstPortfolioImage($portfolioRaw, $resolveImageUrl);
							$avatarImage = $resolveImageUrl($avatar, false);
							if (class_exists(ImageUploadHelper::class)) {
								$portfolioImage = ImageUploadHelper::webUrl($portfolioImage, true);
								$avatarImage = ImageUploadHelper::avatarWebUrl($avatarImage, $itemId, true);
							}
							$cardImage = $portfolioImage !== '' ? $portfolioImage : $avatarImage;
							$imgStyle = $cardImage !== '' ? 'background-image: url(' . htmlspecialchars($cardImage, ENT_QUOTES, 'UTF-8') . ');' : '';
							$masterAvatarStyle = $avatarImage !== ''
								? 'background-image: url(' . htmlspecialchars($avatarImage, ENT_QUOTES, 'UTF-8') . '); background-size: cover;'
								: 'background-image: url(' . htmlspecialchars($defaultImg, ENT_QUOTES, 'UTF-8') . '); background-size: cover;';
							$profileUrl = rtrim(Uri::root(true), '/') . '/' . $itemId;
							?>
						<div class="lk-fav-item">
							<div class="category__item" data-address="<?php echo htmlspecialchars($addr, ENT_QUOTES, 'UTF-8'); ?>">
								<div class="category__item-img" style="<?php echo $imgStyle ?: "background-image: url('/images/service4.png');"; ?>">
									<div class="category__item-master" style="<?php echo $masterAvatarStyle; ?>"></div>
								</div>
								<div class="category__item-content">
									<div class="category__item-content-left">
										<div class="category__content-info">
											<a class="category_cinfo-name" href="<?php echo htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?></a>
											<?php if ($about !== '') : ?>
												<span class="category_cinfo-spec"><?php echo htmlspecialchars(mb_substr($about, 0, 120) . (mb_strlen($about) > 120 ? '…' : ''), ENT_QUOTES, 'UTF-8'); ?></span>
											<?php endif; ?>
											<?php if ($addr !== '') : ?>
												<span class="category_cinfo-address"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo htmlspecialchars($addr, ENT_QUOTES, 'UTF-8'); ?></span>
											<?php endif; ?>
											<?php if ($homeParts !== []) : ?>
												<span class="attr_left3">Форма работы: <b><?php echo htmlspecialchars(implode(', ', $homeParts), ENT_QUOTES, 'UTF-8'); ?></b></span>
											<?php endif; ?>
											<?php
											$listExtraFields = $fields;
											include JPATH_ROOT . '/templates/ryba/html/list-item-extra-attrs.php';
											?>
										</div>
									</div>
									<div class="category__item-content-right">
										<a class="btn__time-zapis" href="<?php echo htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8'); ?>">Записаться</a>
									</div>
								</div>
							</div>
							<div class="lk-fav-comment-wrap">
								<label class="lk-fav-comment-label" for="lk-fav-comment-<?php echo $itemId; ?>">Комментарий</label>
								<textarea
									id="lk-fav-comment-<?php echo $itemId; ?>"
									class="lk-fav-comment"
									maxlength="<?php echo (int) $favCommentMax; ?>"
									rows="1"
									data-id="<?php echo $itemId; ?>"
									placeholder="Кратко, зачем этот профиль в избранном"
								><?php echo htmlspecialchars($favComment, ENT_QUOTES, 'UTF-8'); ?></textarea>
							</div>
						</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<div class="clearFloat"></div>
			</div>
		</fieldset>
	</div>
</div>
<?php if ($favorites !== []) : ?>
<script>
(function () {
	var endpoint = <?php echo json_encode($favAjax); ?>;
	var tokenName = <?php echo json_encode($favTokenName); ?>;
	var tokenValue = <?php echo json_encode($favTokenValue); ?>;
	var maxLen = <?php echo (int) $favCommentMax; ?>;
	var timers = {};

	function autosize(el) {
		el.style.height = 'auto';
		el.style.height = Math.max(el.scrollHeight, el.offsetHeight) + 'px';
	}

	function saveComment(el) {
		var id = parseInt(el.getAttribute('data-id') || '0', 10);
		if (!id || !endpoint) {
			return;
		}
		var value = String(el.value || '');
		if (value.length > maxLen) {
			value = value.slice(0, maxLen);
			el.value = value;
		}
		var fd = new FormData();
		if (tokenName) {
			fd.append(tokenName, tokenValue || '1');
		}
		fd.append('action', 'save_favorite_comment');
		fd.append('profile_id', String(id));
		fd.append('comment', value);
		fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
	}

	document.querySelectorAll('#jsn_favorites .lk-fav-comment').forEach(function (el) {
		autosize(el);
		el.addEventListener('input', function () {
			autosize(el);
			var id = el.getAttribute('data-id') || '';
			if (timers[id]) {
				clearTimeout(timers[id]);
			}
			timers[id] = setTimeout(function () { saveComment(el); }, 400);
		});
		el.addEventListener('blur', function () {
			var id = el.getAttribute('data-id') || '';
			if (timers[id]) {
				clearTimeout(timers[id]);
			}
			saveComment(el);
		});
	});

	function resizeAll() {
		document.querySelectorAll('#jsn_favorites .lk-fav-comment').forEach(autosize);
	}
	var favTab = document.querySelector('#jsn-profile-tabs [data-link="profile-tab10"]');
	if (favTab) {
		favTab.addEventListener('click', function () {
			window.setTimeout(resizeAll, 60);
		});
	}
	window.addEventListener('resize', resizeAll);
})();
</script>
<?php endif; ?>

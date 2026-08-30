<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

$app = Factory::getApplication();
$currentUser = $app->getIdentity();
$isGuest = $currentUser->guest;
$input = $app->getInput();
$filterCatId = (int) ($currentCatId ?? $input->getUint('cat_id', 0));
$filterServiceId = (int) ($currentService ?? $input->getUint('service', 0));
$filterTagId = (int) ($currentTag ?? $input->getUint('tag', 0));
$profileUrl = rtrim(Uri::root(true), '/') . '/' . (int) $item->id;
if ($filterCatId > 0 && $filterServiceId > 0 && $filterTagId > 0) {
	$profileUrl .= '?' . http_build_query([
		'cat_id' => $filterCatId,
		'service' => $filterServiceId,
		'tag' => $filterTagId,
	]);
}

$sity = trim($fields['sity'] ?? '');
$area = trim($fields['area'] ?? '');
$street = trim($fields['street'] ?? '');
$house = trim($fields['house_number'] ?? '');
$addr = implode(', ', array_filter([$sity, $area, $street, $house]));
$about = trim($fields['about'] ?? '');
$avatar = trim($fields['avatar'] ?? '');
$portfolioRaw = trim((string) ($fields['portfolio_field'] ?? ''));
$homeVal = $fields['home'] ?? '';
$homeLabels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];
$homeParts = [];
if ($homeVal !== '' && $homeVal !== '[]') {
	$decoded = json_decode($homeVal, true);
	if (is_array($decoded)) {
		foreach ($decoded as $v) {
			if (isset($homeLabels[(int) $v])) {
				$homeParts[] = $homeLabels[(int) $v];
			}
		}
	}
}

$stocks = $this->stocksByUser[$item->id] ?? [];
$allCategories = $this->allCategories ?? [];
$allServices = $this->allServices ?? [];
$allTags = $this->allTags ?? [];
$categoryByUser = $this->categoryByUser ?? [];

$getFullServiceName = function($itemId, $catId, $tagId) use ($allCategories, $allServices, $allTags, $categoryByUser) {
	$parts = [];
	$masterCategoryId = $categoryByUser[$itemId] ?? 0;
	if ($masterCategoryId > 0 && isset($allCategories[$masterCategoryId])) {
		$parts[] = $allCategories[$masterCategoryId]['title'];
	}
	if ($catId > 0 && isset($allServices[$catId])) {
		$parts[] = $allServices[$catId]['title'];
	}
	if ($tagId > 0 && isset($allTags[$tagId])) {
		$parts[] = $allTags[$tagId]['title'];
	}
	return implode(' / ', $parts);
};

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
	foreach ($candidates as $item) {
		$url = $resolver($item, true);
		if ($url !== '' && !str_ends_with(strtolower($url), '/true')) {
			return $url;
		}
	}
	return '';
};

$portfolioImage = $extractFirstPortfolioImage($portfolioRaw, $resolveImageUrl);
$avatarImage = $resolveImageUrl($avatar, false);
$cardImage = $portfolioImage !== '' ? $portfolioImage : $avatarImage;
$imgStyle = $cardImage !== '' ? 'background-image: url(' . htmlspecialchars($cardImage, ENT_QUOTES, 'UTF-8') . ');' : '';
$masterAvatarStyle = $avatarImage !== '' ? 'background-image: url(' . htmlspecialchars($avatarImage, ENT_QUOTES, 'UTF-8') . '); background-size: cover;' : 'background-image: url(/templates/ryba/images/master.png); background-size: cover;';
$servicePrice = isset($this->pricesByUser[(int) $item->id]) ? (int) $this->pricesByUser[(int) $item->id] : 0;
$servicePriceLabel = $servicePrice > 0 ? number_format($servicePrice, 0, '.', ' ') . ' ₽' : '';
?>
<div class="category__item" data-address="<?php echo htmlspecialchars($addr); ?>">
	<div class="category__item-img" style="<?php echo $imgStyle ?: "background-image: url('/images/service4.png');"; ?>">
		<div class="category__item-master" style="<?php echo $masterAvatarStyle; ?>"></div>
	</div>
	<div class="category__item-content">
		<div class="category__item-content-left">
			<div class="category__content-info">
				<a class="category_cinfo-name" href="<?php echo $profileUrl; ?>"><?php echo htmlspecialchars($item->name); ?></a>
				<?php if ($about !== '') : ?>
					<span class="category_cinfo-spec"><?php echo htmlspecialchars(mb_substr($about, 0, 120)) . (mb_strlen($about) > 120 ? '…' : ''); ?></span>
				<?php endif; ?>
				<?php if ($addr !== '') : ?>
					<span class="category_cinfo-address"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo htmlspecialchars($addr); ?></span>
				<?php endif; ?>
				<?php if ($homeParts !== []) : ?>
					<span class="attr_left3">Форма работы: <b><?php echo htmlspecialchars(implode(', ', $homeParts)); ?></b></span>
				<?php endif; ?>
				<?php if ($servicePriceLabel !== '') : ?>
					<div class="service-price"><?php echo htmlspecialchars($servicePriceLabel, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>
			</div>
			
			<?php if (!empty($stocks)) : ?>
			<div class="category__content-info-list category__content-info-list--stocks" style="margin-left: -95px; padding-left: 0; clear: both; padding-top: 12px;">
				<button style="color:green; font-weight:bold; background-color:#fff; border-radius:5px; margin-bottom:10px; border:1px solid green; padding:4px 12px;">Акции</button>
				<ul style="line-height: 1.6; padding-left: 0; margin-left: 0;">
					<?php foreach ($stocks as $stock) : 
						$serviceName = $getFullServiceName($item->id, $stock['cat_id'], $stock['tag_id']);
					?>
					<li style="padding-left: 0; margin-left: 0;">
						<span><?php echo htmlspecialchars($serviceName); ?></span>
						<span> / <?php echo number_format($stock['price'], 0, '.', ' '); ?> руб.</span>
						<?php if ($stock['stock_count'] > 0) : ?>
						<div style="font-size:12px; color:#666; margin-top:2px;">
							Осталось предложений: <?php echo (int)$stock['stock_count']; ?>
						</div>
						<?php endif; ?>
						<?php if (!empty($stock['comment'])) : ?>
						<div style="font-size:12px; color:#888; margin-top:2px;">
							<?php echo htmlspecialchars($stock['comment']); ?>
						</div>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
		<div class="category__item-content-right">
			<a class="btn__time-zapis" href="<?php echo $profileUrl; ?>">Записаться</a>
		</div>
	</div>
</div>
<style>
.category.jsn_list .service-price {
    background-color: transparent;
}
/* Mobile resets only. Desktop card layout is restored in style-ext.css
   (@media min-width: 769px) so these stacking/offset rules cannot leak. */
@media (max-width: 768px) {
    .category__item-content-left {
        padding-left: 0px;
        padding-top: 0px;
    }
    .category__item-content-right {
        clear: none;
        padding: 0;
        margin-top: 0;
        border-top: none;
    }
    .category__item-master {
        top: 0;
        transform: none;
    }
}
</style>

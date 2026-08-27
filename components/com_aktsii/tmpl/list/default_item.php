<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

$app = Factory::getApplication();
$currentUser = $app->getIdentity();
$isGuest = $currentUser->guest;
$profileUrl = Uri::root() . 'index.php?option=com_users&view=profile&user_id=' . (int) $item->id;

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
?>
<div class="category__item" data-address="<?php echo htmlspecialchars($addr); ?>">
	<div class="category__item-content">
		<div class="category__item-content-left">
			<div class="category__content-info">
				<?php if ($about !== '') : ?>
					<span class="category_cinfo-spec"><?php echo htmlspecialchars(mb_substr($about, 0, 120)) . (mb_strlen($about) > 120 ? '…' : ''); ?></span>
				<?php endif; ?>
				<?php if ($addr !== '') : ?>
					<span class="category_cinfo-address"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo htmlspecialchars($addr); ?></span>
				<?php endif; ?>
				<?php if ($homeParts !== []) : ?>
					<span class="attr_left3">Форма работы: <b><?php echo htmlspecialchars(implode(', ', $homeParts)); ?></b></span>
				<?php endif; ?>
				<a class="category_cinfo-name" href="<?php echo $profileUrl; ?>"><?php echo htmlspecialchars($item->name); ?></a>
			</div>
			
			<?php if (!empty($stocks)) : ?>
			<div class="category__content-info-list category__content-info-list--stocks">
				<ul style="line-height: 1.6; padding-left: 0; margin: 0;">
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
			<a class="btn__time-zapis" href="<?php echo $profileUrl; ?>">Записаться</a>
		</div>
		<div class="category__item-content-right"></div>
	</div>
	<div class="category__item-img" style="<?php echo $imgStyle ?: "background-image: url('/images/service4.png');"; ?>">
		<div class="category__item-master" style="<?php echo $masterAvatarStyle; ?>"></div>
	</div>
</div>
<style>
.category.jsn_stockList .category__item-content-right {
    display: none;
}
.category.jsn_stockList .category__item {
    position: relative;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow: visible;
    border-radius: 20px;
}
.category__item-content-left {
    padding-left: 80px;
    padding-top: 35px;
}
.category__item-master {
    top: 60%;
    transform: translateY(-50%);
}
.category.jsn_stockList .category__item-content,
.category.jsn_stockList .category__item-content-left {
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    align-items: flex-start;
    width: 100% !important;
    flex: 1 1 auto;
    box-sizing: border-box;
    margin-top: 0 !important;
    padding-top: 0 !important;
}
.category.jsn_stockList .category__item-content-left {
    align-self: flex-start;
    text-align: left;
}
.category.jsn_stockList .category__content-info,
.category.jsn_stockList .category__content-info-list,
.category.jsn_stockList .category__content-info-list--stocks {
    align-self: flex-start;
    margin-top: 0 !important;
    padding-top: 0 !important;
}
.category.jsn_stockList .category__item .btn__time-zapis {
    display: inline-block !important;
    align-self: flex-start;
    float: none !important;
    margin-left: 0 !important;
    text-align: left;
    font-size: 13px !important;
}
@media (max-width: 768px) {
    .category.jsn_stockList .category__item {
        position: relative;
        min-height: 67px;
    }
    .category.jsn_stockList .category__item-img {
        position: absolute !important;
        top: 0;
        left: 0;
        float: none !important;
        width: 67px !important;
        height: 67px !important;
        margin-right: 0 !important;
        z-index: 1;
    }
    .category.jsn_stockList .category__item-content,
    .category.jsn_stockList .category__item-content-left {
        display: flex !important;
        flex-direction: column;
        justify-content: flex-start;
        align-items: flex-start;
        float: none !important;
        width: 100% !important;
        margin-top: 0 !important;
        padding-top: 0 !important;
    }
    .category.jsn_stockList .category__item-content-left {
        padding-left: 0 !important;
        padding-top: 0 !important;
        text-align: left;
    }
    .category.jsn_stockList .category__content-info,
    .category.jsn_stockList .category__content-info-list,
    .category.jsn_stockList .category__content-info-list--stocks {
        padding-left: calc(67px + 20px + 15px) !important;
        padding-top: 0 !important;
        margin-top: 0 !important;
        margin-left: 0 !important;
        width: 100%;
        box-sizing: border-box;
    }
    .category.jsn_stockList .category__item .btn__time-zapis {
        position: static;
        align-self: flex-start !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        float: none !important;
        transform: translate(4px, -4px);
    }
}
.category__content-info-list {
    clear: both;
    padding-top: 12px;
    margin-top: 0;
    padding-left: 0;
}
.category__content-info-list ul {
    line-height: 1.6;
    padding-left: 0 !important;
    margin-top: 0 !important;
    margin-left: 0 !important;
}
.category__content-info-list ul li {
    padding-left: 0 !important;
    margin-left: 0 !important;
}
@media (min-width: 992px) {
    .category__item-content-left {
        padding-left: 90px;
        padding-top: 40px;
    }
}
@media (max-width: 767px) {
    .category__item-content-left {
        padding-left: 15px;
        padding-top: 20px;
    }
    .category__item-content-left .btn__time-zapis {
        margin-top: 15px;
    }
}
/* Desktop: photo is position:absolute so the left text column can sit
   at the top of the card instead of wrapping below the 295px image. */
@media (min-width: 769px) {
    .category.jsn_stockList .category__masters {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .category.jsn_stockList .category__item,
    div.category.jsn_stockList div.category__item {
        position: relative !important;
        display: block !important;
        box-sizing: border-box !important;
        padding: 0 16px 58px 16px !important;
        margin-bottom: 0 !important;
        border: 1px solid #ccc !important;
        border-radius: 20px !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
        background: #fff !important;
        overflow: visible !important;
        min-height: calc(16px + 295px + 58px);
    }
    .category.jsn_stockList .category__item-master {
        position: absolute !important;
        right: -38px !important;
        left: auto !important;
        top: 0 !important;
        transform: none !important;
        width: 85px;
        height: 80px;
        box-sizing: border-box;
        background-size: cover !important;
        background-position: center;
        background-repeat: no-repeat;
        border-radius: 50%;
    }
    .category.jsn_stockList .category__item-img {
        position: absolute !important;
        top: 16px;
        left: 16px;
        float: none !important;
        width: 231px;
        height: 295px;
        border-radius: 12px;
        z-index: 1;
    }
    .category.jsn_stockList .category__item-content {
        display: flex !important;
        flex-direction: column;
        justify-content: flex-start;
        align-items: flex-start;
        flex: 1 1 auto;
        float: none !important;
        box-sizing: border-box;
        width: 100% !important;
        max-width: none !important;
        margin-top: 0 !important;
        padding-top: 0 !important;
        /* Static so the «Записаться» button's containing block is the card. */
        position: static;
        z-index: auto;
    }
    .category.jsn_stockList .category__item-content-left {
        display: flex !important;
        flex-direction: column;
        justify-content: flex-start;
        align-items: flex-start;
        align-self: flex-start;
        flex: 1 1 auto;
        float: none !important;
        width: 100% !important;
        padding-left: 0 !important;
        padding-top: 0 !important;
        margin-top: 0 !important;
        clear: none;
        text-align: left;
    }
    .category.jsn_stockList .category__content-info {
        padding-left: calc(231px + 55px);
        padding-top: 0 !important;
        margin-top: 0 !important;
        width: 100%;
        box-sizing: border-box;
    }
    .category.jsn_stockList .category__item-content-right {
        display: none !important;
    }
    .category.jsn_stockList .category__item-content-left .btn__time-zapis {
        position: absolute;
        bottom: 8px;
        right: 8px;
        z-index: 3;
        display: inline-block !important;
        float: none !important;
        width: auto;
        margin: 0 !important;
        transform: none !important;
        font-size: 14px !important;
        text-align: left;
    }
    .category.jsn_stockList .category__content-info-list,
    .category.jsn_stockList .category__content-info-list--stocks {
        clear: none !important;
        float: none !important;
        margin-left: 0 !important;
        padding-left: calc(231px + 55px) !important;
        padding-top: 0 !important;
        margin-top: 0 !important;
        box-sizing: border-box;
        width: 100%;
        max-width: 100%;
    }
    .category.jsn_stockList .category__content-info-list ul,
    .category.jsn_stockList .category__content-info-list ul li {
        padding-left: 0 !important;
        margin-left: 0 !important;
        white-space: normal;
    }
}
</style>

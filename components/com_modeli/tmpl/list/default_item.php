<?php

defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;

$profileUrl = Uri::root() . 'index.php?option=com_users&view=profile&user_id=' . (int) ($item->master_id ?? 0);

$masterFields = $fieldsByUser[(int) ($item->master_id ?? 0)] ?? [];
$sity = trim((string) ($masterFields['sity'] ?? ''));
$area = trim((string) ($masterFields['area'] ?? ''));
$street = trim((string) ($masterFields['street'] ?? ''));
$house = trim((string) ($masterFields['house_number'] ?? ''));
$addr = implode(', ', array_filter([$sity, $area, $street, $house]));
$about = trim((string) ($masterFields['about'] ?? ''));
$avatar = trim((string) ($masterFields['avatar'] ?? ''));
$portfolioRaw = trim((string) ($masterFields['portfolio_field'] ?? ''));
$homeVal = $masterFields['home'] ?? '';
$homeLabels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];
$homeParts = [];
if ($homeVal !== '' && $homeVal !== '[]') {
	$decoded = json_decode((string) $homeVal, true);
	if (is_array($decoded)) {
		foreach ($decoded as $value) {
			if (isset($homeLabels[(int) $value])) {
				$homeParts[] = $homeLabels[(int) $value];
			}
		}
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
	if (stripos($clean, 'images/portfolio/') === 0 || stripos($clean, 'images/profiler/') === 0 || stripos($clean, 'images/') === 0) {
		return '/' . $clean;
	}
	if ($preferPortfolio || stripos($clean, 'portfolio/') === 0) {
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
		$iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($decoded));
		foreach ($iterator as $value) {
			if (is_scalar($value)) {
				$candidates[] = (string) $value;
			}
		}
	} else {
		$candidates[] = $rawValue;
	}
	foreach ($candidates as $candidate) {
		$url = $resolver($candidate, true);
		if ($url !== '') {
			return $url;
		}
	}
	return '';
};

$resolveSearchMediaUrl = static function (string $rawValue): string {
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
	if ($clean === '' || preg_match('#^(images/?|images/search/?|search/?)$#i', $clean)) {
		return '';
	}
	if (!preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $clean)) {
		return '';
	}
	if (preg_match('#^images/(search_[^/]+)$#i', $clean, $matches)) {
		return '/images/search/' . $matches[1];
	}
	if (stripos($clean, 'images/') === 0) {
		return '/' . $clean;
	}
	if (stripos($clean, 'search/') === 0) {
		return '/images/' . $clean;
	}

	return '/images/search/' . $clean;
};

$searchImage = trim((string) ($item->media_path ?? ''));
$searchImageUrl = $resolveSearchMediaUrl($searchImage);

if ($searchImageUrl !== '') {
	$cardImage = $searchImageUrl;
} else {
	$cardImage = '/images/service4.png';
}

$imgStyle = 'background-image: url(' . htmlspecialchars($cardImage, ENT_QUOTES, 'UTF-8') . ');';

$searchTitle = trim((string) ($item->title ?? $item->description ?? ''));
$searchDescription = trim((string) ($item->description ?? ''));
$categoryTitle = trim((string) ($item->category_title ?? ''));
$price = (int) ($item->price ?? 0);
$durationMin = (int) ($item->duration_min ?? 0);
$capacity = (int) ($item->capacity ?? 1);
$bookingMode = trim((string) ($item->booking_mode ?? 'free'));
$slotStartUtc = trim((string) ($item->starts_at_utc ?? ''));
$slotLabel = '';
if ($slotStartUtc !== '') {
	try {
		$slotDate = new DateTimeImmutable($slotStartUtc, new DateTimeZone('UTC'));
		$slotLabel = $slotDate->format('d.m.Y H:i');
	} catch (\Throwable $e) {
		$slotLabel = $slotStartUtc;
	}
}
?>
<div class="category__item search-catalog__item" data-address="<?php echo htmlspecialchars($addr, ENT_QUOTES, 'UTF-8'); ?>">
<div class="category__item-img" style="<?php echo $imgStyle; ?>">
    <img src="<?php echo htmlspecialchars($cardImage, ENT_QUOTES, 'UTF-8'); ?>" 
         alt="<?php echo htmlspecialchars($searchTitle, ENT_QUOTES, 'UTF-8'); ?>" 
         style="display:none; width:100%; height:100%; object-fit:cover;">
</div>
	<div class="category__item-content">
		<div class="category__item-content-left">
			<div class="category__content-info">
				<a class="category_cinfo-name" href="<?php echo $profileUrl; ?>"><?php echo htmlspecialchars($searchTitle !== '' ? $searchTitle : 'Поиск моделей', ENT_QUOTES, 'UTF-8'); ?></a>
				<span class="category_cinfo-spec">Мастер: <?php echo htmlspecialchars((string) ($item->master_name ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
				<?php if ($categoryTitle !== '') : ?>
					<span class="category_cinfo-spec">Категория: <?php echo htmlspecialchars($categoryTitle, ENT_QUOTES, 'UTF-8'); ?></span>
				<?php endif; ?>
				<?php if ($searchDescription !== '') : ?>
					<span class="category_cinfo-spec"><?php echo htmlspecialchars(mb_substr($searchDescription, 0, 150), ENT_QUOTES, 'UTF-8') . (mb_strlen($searchDescription) > 150 ? '…' : ''); ?></span>
				<?php endif; ?>
				<?php if ($addr !== '') : ?>
					<span class="category_cinfo-address"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo htmlspecialchars($addr, ENT_QUOTES, 'UTF-8'); ?></span>
				<?php endif; ?>
				<div class="category__content-info-list">
					<strong style="color:green;">Параметры поиска</strong>
					<ul>
						<li><span><?php echo $price; ?> руб.</span></li>
						<li><div>Длительность: <?php echo $durationMin; ?> мин.</div></li>
						<li><div>Лимит мест: <?php echo $capacity > 0 ? $capacity : 1; ?></div></li>
						<li><div>Режим: <?php echo $bookingMode === 'fixed' ? 'Фиксированная дата' : 'Любое время'; ?></div></li>
						<?php if ($slotLabel !== '') : ?>
							<li><div>Дата и время: <span class="modeli-time-utc" data-time-utc="<?php echo htmlspecialchars($slotStartUtc, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($slotLabel, ENT_QUOTES, 'UTF-8'); ?></span></div></li>
						<?php endif; ?>
					</ul>
				</div>
				<?php if ($homeParts !== []) : ?>
					<span class="attr_left3">Форма работы: <b><?php echo htmlspecialchars(implode(', ', $homeParts), ENT_QUOTES, 'UTF-8'); ?></b></span>
				<?php endif; ?>
			</div>
		</div>
		<div class="category__item-content-right">
			<a class="btn__time-zapis" href="<?php echo $profileUrl; ?>">Записаться</a>
		</div>
	</div>
</div>
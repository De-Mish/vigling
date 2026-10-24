<?php
/**
 * Список специалистов (замена JSN, без Joomla)
 * URL: /specialists-list.php
 * Параметры: cat_id, city, area, home[] (1=Салон, 2=Вызов, 3=Мастер на дому), limit, limitstart
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('memory_limit', '128M');

define('_JEXEC', 1);

$baseDir = __DIR__;
require_once $baseDir . '/configuration.php';
$config = new JConfig();

$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_error) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Ошибка подключения к БД');
}
$mysqli->set_charset('utf8mb4');

$prefix = $config->dbprefix;
$liveSite = rtrim($config->live_site ?? '', '/');

$query = "SELECT id, name FROM {$prefix}fields 
          WHERE context = 'com_users.user' 
          AND name IN ('is_master', 'vyberite_spetsialnos', 'sity', 'telefon', 'area', 'home')";
$result = $mysqli->query($query);
$fieldIds = [];
while ($row = $result->fetch_assoc()) {
    $fieldIds[$row['name']] = (int) $row['id'];
}

$specId = $fieldIds['vyberite_spetsialnos'] ?? 0;
$sityId = $fieldIds['sity'] ?? 0;
$telefonId = $fieldIds['telefon'] ?? 0;
$areaId = $fieldIds['area'] ?? 0;
$homeId = $fieldIds['home'] ?? 0;

$catId = (int) ($_GET['cat_id'] ?? $_GET['vyberite_spetsialnos'] ?? 0);
$city = trim((string) ($_GET['city'] ?? ''));
$area = trim((string) ($_GET['area'] ?? ''));
$homeRaw = $_GET['home'] ?? [];
$homeArr = is_array($homeRaw) ? array_filter(array_map('intval', $homeRaw)) : [];
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = max(0, (int) ($_GET['limitstart'] ?? 0));
$orderDir = strtoupper((string) ($_GET['dir'] ?? 'ASC'));
if ($orderDir !== 'DESC') {
    $orderDir = 'ASC';
}

$categories = [];
$catQuery = "SELECT id, title FROM {$prefix}categories 
             WHERE extension = 'com_content' AND published = 1 
             AND (parent_id = 39 OR path LIKE 'uslugi/%' OR id IN (9,10,11,12,13,14,16,17,18,19,20,21)) 
             ORDER BY title ASC";
$res = $mysqli->query($catQuery);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[(int)$row['id']] = $row['title'];
    }
}
$pageTitle = 'Все специалисты';
if ($catId > 0 && isset($categories[$catId])) {
    $pageTitle = $categories[$catId];
}

$cities = [];
$cityQuery = "SELECT DISTINCT TRIM(fv.value) AS city FROM {$prefix}fields_values fv 
              WHERE fv.field_id = $sityId AND TRIM(fv.value) <> '' ORDER BY city ASC";
$res = $mysqli->query($cityQuery);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cities[] = $row['city'];
    }
}

$areas = [];
$areaQuery = "SELECT DISTINCT TRIM(fv.value) AS area FROM {$prefix}fields_values fv 
              WHERE fv.field_id = $areaId AND TRIM(fv.value) <> '' ORDER BY area ASC";
$res = $mysqli->query($areaQuery);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $areas[] = $row['area'];
    }
}

$baseConditions = [];
if ($specId) {
    $baseConditions[] = "(fv.field_id = $specId AND TRIM(fv.value) <> '' AND fv.value <> '{}')";
}
if ($sityId) {
    $baseConditions[] = "(fv.field_id = $sityId AND TRIM(fv.value) <> '')";
}
if ($telefonId) {
    $baseConditions[] = "(fv.field_id = $telefonId AND TRIM(fv.value) <> '')";
}
$baseJoin = implode(' OR ', $baseConditions);
if ($baseJoin === '') {
    $baseJoin = '0';
}

$countQuery = "SELECT COUNT(DISTINCT u.id) FROM {$prefix}users u
INNER JOIN {$prefix}fields_values fv ON fv.item_id = u.id AND ($baseJoin)
WHERE u.block = 0";
$listQuery = "SELECT DISTINCT u.id, u.name FROM {$prefix}users u
INNER JOIN {$prefix}fields_values fv ON fv.item_id = u.id AND ($baseJoin)
WHERE u.block = 0";

if ($catId > 0 && $specId) {
    $countQuery .= " AND u.id IN (SELECT item_id FROM {$prefix}fields_values WHERE field_id=$specId AND value LIKE '%\"$catId\"%')";
    $listQuery .= " AND u.id IN (SELECT item_id FROM {$prefix}fields_values WHERE field_id=$specId AND value LIKE '%\"$catId\"%')";
}
if ($city !== '') {
    $cityEsc = $mysqli->real_escape_string($city);
    $countQuery .= " AND u.id IN (SELECT item_id FROM {$prefix}fields_values WHERE field_id=$sityId AND value LIKE '%$cityEsc%')";
    $listQuery .= " AND u.id IN (SELECT item_id FROM {$prefix}fields_values WHERE field_id=$sityId AND value LIKE '%$cityEsc%')";
}
if ($area !== '' && $areaId) {
    $areaEsc = $mysqli->real_escape_string($area);
    $countQuery .= " AND u.id IN (SELECT item_id FROM {$prefix}fields_values WHERE field_id=$areaId AND value LIKE '%$areaEsc%')";
    $listQuery .= " AND u.id IN (SELECT item_id FROM {$prefix}fields_values WHERE field_id=$areaId AND value LIKE '%$areaEsc%')";
}
if (!empty($homeArr) && $homeId) {
    $homeConds = [];
    foreach ($homeArr as $h) {
        if ($h >= 1 && $h <= 3) {
            $homeConds[] = "value LIKE '%\"$h\"%'";
        }
    }
    if (!empty($homeConds)) {
        $hc = "SELECT item_id FROM {$prefix}fields_values WHERE field_id=$homeId AND (" . implode(' OR ', $homeConds) . ")";
        $countQuery .= " AND u.id IN ($hc)";
        $listQuery .= " AND u.id IN ($hc)";
    }
}

$result = $mysqli->query($countQuery);
$total = $result ? (int) $result->fetch_row()[0] : 0;

$listQuery .= " ORDER BY u.name $orderDir LIMIT $limit OFFSET $offset";
$result = $mysqli->query($listQuery);
$items = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
}

$mysqli->close();

$pagesTotal = $limit > 0 ? (int) ceil($total / $limit) : 0;
$currentPage = $limit > 0 ? (int) floor($offset / $limit) + 1 : 1;

$homeLabels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo htmlspecialchars($pageTitle); ?> — Поиск специалистов | VIGLING</title>
	<link rel="stylesheet" href="<?php echo $liveSite; ?>/templates/ryba/css/template.css">
</head>
<body class="com-specialists-standalone">
<div class="category jsn_list">
	<div class="category__head">
		<h2><?php echo htmlspecialchars($pageTitle); ?></h2>
		<span class="cat_head-res">Найдено: <span><?php echo (int) $total; ?></span></span>
	</div>
	<div class="category__body">
		<div class="category__masters">
			<div data-da=".pagination__wrap,922,1" class="category__masters-sidebar">
				<h2>Фильтр специалистов</h2>
				<form action="<?php echo $liveSite; ?>/specialists-list.php" class="form-horizontal filter" method="get">
					<div class="masters-sidebar__body">
						<span class="clearable">
							<select name="city" class="filed__master">
								<option value="">Город</option>
								<?php foreach ($cities as $c) : ?>
									<option value="<?php echo htmlspecialchars($c); ?>"<?php echo $city === $c ? ' selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
						<span class="clearable">
							<select name="area" class="filed__master">
								<option value="">Район</option>
								<?php foreach ($areas as $a) : ?>
									<option value="<?php echo htmlspecialchars($a); ?>"<?php echo $area === $a ? ' selected' : ''; ?>><?php echo htmlspecialchars($a); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
						<span class="clearable">
							<select name="cat_id" class="filed__master">
								<option value="0">Специальность</option>
								<?php foreach ($categories as $id => $title) : ?>
									<option value="<?php echo $id; ?>"<?php echo $catId === $id ? ' selected' : ''; ?>><?php echo htmlspecialchars($title); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
						<span class="clearable">
							<label>Вид услуги:</label>
							<?php foreach ($homeLabels as $val => $label) : ?>
								<label class="checkbox-inline"><input type="checkbox" name="home[]" value="<?php echo $val; ?>"<?php echo in_array($val, $homeArr) ? ' checked' : ''; ?>> <?php echo htmlspecialchars($label); ?></label>
							<?php endforeach; ?>
						</span>
					</div>
					<input type="hidden" name="limit" value="<?php echo (int) $limit; ?>">
					<input class="submit__search" type="submit" value="Поиск">
				</form>
			</div>

			<?php if (!empty($items)) : ?>
				<ul class="specialists-list category__content-info-list">
					<?php foreach ($items as $item) : ?>
						<li class="category__item">
							<span class="category_cinfo-name"><?php echo htmlspecialchars($item['name']); ?></span>
							<span class="category_cinfo-id">(ID: <?php echo (int) $item['id']; ?>)</span>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ($pagesTotal > 1) : ?>
					<div class="pagination__wrap">
						<?php
						$baseUrl = $liveSite . '/specialists-list.php?limit=' . $limit . '&cat_id=' . $catId . '&city=' . rawurlencode($city) . '&area=' . rawurlencode($area);
						foreach ($homeArr as $h) {
							$baseUrl .= '&home[]=' . $h;
						}
						if ($currentPage > 1) {
							echo '<a href="' . $baseUrl . '&limitstart=' . ($offset - $limit) . '">← Назад</a> ';
						}
						echo '<span>Страница ' . $currentPage . ' из ' . $pagesTotal . '</span>';
						if ($currentPage < $pagesTotal) {
							echo ' <a href="' . $baseUrl . '&limitstart=' . ($offset + $limit) . '">Вперёд →</a>';
						}
						?>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p class="alert alert-warning">Специалисты не найдены.</p>
			<?php endif; ?>
		</div>
	</div>
</div>
<p class="back-link"><a href="<?php echo $liveSite; ?>/">← На главную</a></p>
</body>
</html>

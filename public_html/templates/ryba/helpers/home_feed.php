<?php
/**
 * Home page feed: newest "Поиск моделей" and "Акции" cards (10 per page).
 *
 * Cards are rendered with the same item templates as /modeli and /poisk-aktsij.
 * Cards are narrowed to the viewer's profile city when it is set; otherwise all cities are shown.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * Carries the data the aktsii card template reads from `$this`.
 */
final class VglHomeFeedCardView
{
	public $stocksByUser = [];
	public $allCategories = [];
	public $allServices = [];
	public $allTags = [];
	public $categoryByUser = [];

	public function capture(string $file, array $vars): string
	{
		extract($vars, EXTR_SKIP);
		ob_start();
		include $file;

		return (string) ob_get_clean();
	}
}

final class VglHomeFeed
{
	public const LIMIT = 10;
	public const FEED_MODELI = 'modeli';
	public const FEED_AKTSII = 'aktsii';
	public const PARAM_FEED = 'hf';
	public const PARAM_PAGE = 'hf_page';
	public const ANCHOR = 'home-feed';

	private const FIELD_NAMES = ['sity', 'area', 'street', 'house_number', 'telefon', 'about', 'avatar', 'portfolio_field', 'home', 'payment_method', 'suitable_for_children', 'vyberite_spetsialnos'];

	/** @return array<string, string> */
	public static function tabs(): array
	{
		return [
			self::FEED_MODELI => 'Поиск моделей',
			self::FEED_AKTSII => 'Акции',
		];
	}

	public static function currentFeed(): string
	{
		$feed = Factory::getApplication()->getInput()->getCmd(self::PARAM_FEED, self::FEED_MODELI);

		return isset(self::tabs()[$feed]) ? $feed : self::FEED_MODELI;
	}

	public static function currentPage(): int
	{
		return max(1, (int) Factory::getApplication()->getInput()->getUint(self::PARAM_PAGE, 1));
	}

	public static function viewerCity(): string
	{
		try {
			if (!class_exists(\Viglin\Component\Poisk\Site\Helper\ListMapHelper::class)) {
				$file = JPATH_SITE . '/components/com_poisk/src/Helper/ListMapHelper.php';
				if (is_file($file)) {
					require_once $file;
				}
			}

			return trim(\Viglin\Component\Poisk\Site\Helper\ListMapHelper::viewerCity());
		} catch (\Throwable $e) {
			return '';
		}
	}

	public static function url(string $feed, int $page = 1): string
	{
		$query = [self::PARAM_FEED => $feed];
		if ($page > 1) {
			$query[self::PARAM_PAGE] = $page;
		}

		return rtrim(Uri::root(true), '/') . '/?' . http_build_query($query) . '#' . self::ANCHOR;
	}

	/**
	 * @return array{html: string, total: int, page: int, pages: int, city: string, error: bool}
	 */
	public static function build(): array
	{
		$feed = self::currentFeed();
		$page = self::currentPage();
		$city = self::viewerCity();
		$result = ['html' => '', 'total' => 0, 'page' => $page, 'pages' => 1, 'city' => $city, 'error' => false];

		try {
			$data = $feed === self::FEED_AKTSII
				? self::loadAktsii($city, $page)
				: self::loadModeli($city, $page);

			if ($data['total'] > 0 && $data['items'] === [] && $page > 1) {
				$page = max(1, (int) ceil($data['total'] / self::LIMIT));
				$data = $feed === self::FEED_AKTSII
					? self::loadAktsii($city, $page)
					: self::loadModeli($city, $page);
			}

			$result['total'] = $data['total'];
			$result['page'] = $page;
			$result['pages'] = max(1, (int) ceil($data['total'] / self::LIMIT));
			$result['html'] = $feed === self::FEED_AKTSII
				? self::renderAktsii($data['items'])
				: self::renderModeli($data['items']);
		} catch (\Throwable $e) {
			$result['error'] = true;
		}

		return $result;
	}

	public static function render(): string
	{
		$feed = self::currentFeed();
		$feedData = self::build();
		$tabs = self::tabs();
		$city = $feedData['city'];

		ob_start();
		?>
		<section class="search__catalog home-feed" id="<?php echo self::ANCHOR; ?>">
			<div class="container">
				<h2 class="home-feed__title">Новые предложения</h2>
				<nav class="home-feed__tabs" aria-label="Раздел новых предложений">
					<?php foreach ($tabs as $key => $label) : ?>
						<a class="home-feed__tab<?php echo $key === $feed ? ' is-active' : ''; ?>"
							href="<?php echo htmlspecialchars(self::url($key), ENT_QUOTES, 'UTF-8'); ?>"
							<?php echo $key === $feed ? 'aria-current="page"' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
					<?php endforeach; ?>
				</nav>
				<?php if ($city !== '') : ?>
					<p class="home-feed__city">Показаны предложения в городе: <strong><?php echo htmlspecialchars($city, ENT_QUOTES, 'UTF-8'); ?></strong></p>
				<?php endif; ?>
				<?php if ($feedData['error']) : ?>
					<div class="alert alert-warning">Не удалось загрузить предложения. Попробуйте обновить страницу.</div>
				<?php elseif ($feedData['html'] === '') : ?>
					<div class="alert alert-warning"><?php echo $feed === self::FEED_AKTSII ? 'Акции не найдены.' : 'Поиск моделей не найден.'; ?></div>
				<?php else : ?>
					<div class="category jsn_stockList<?php echo $feed === self::FEED_MODELI ? ' search-catalog' : ''; ?> home-feed__list">
						<div class="category__body">
							<div class="category__masters">
								<?php echo $feedData['html']; ?>
								<?php echo self::renderPager($feed, $feedData['page'], $feedData['pages']); ?>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private static function renderPager(string $feed, int $page, int $pages): string
	{
		if ($pages < 2) {
			return '';
		}

		$link = static function (int $target, string $label, string $inner) use ($feed): string {
			return '<li class=""><a title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" href="'
				. htmlspecialchars(self::url($feed, $target), ENT_QUOTES, 'UTF-8')
				. '" class="pagenav" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' . $inner . '</a></li>';
		};
		$disabled = static function (string $inner): string {
			return '<li class="disabled"><a><span aria-hidden="true">' . $inner . '</span></a></li>';
		};

		$html = '<div class="pagination__wrap home-feed__pager" style="clear:both"><ul class="pagination">';
		$html .= $page > 1
			? $link(1, 'Перейти на первую страницу', '<span class="icon-first" aria-hidden="true"></span>')
			. $link($page - 1, 'Перейти на предыдущую страницу', '<span class="icon-previous" aria-hidden="true"></span>')
			: $disabled('<span class="icon-first" aria-hidden="true"></span>')
			. $disabled('<span class="icon-previous" aria-hidden="true"></span>');

		$from = max(1, $page - 2);
		$to = min($pages, $from + 4);
		$from = max(1, $to - 4);
		for ($i = $from; $i <= $to; $i++) {
			if ($i === $page) {
				$html .= '<li class="active hidden-phone" style="color: #F9CE54; font-weight: 900"><a class="active" aria-current="true" aria-label="Страница ' . $i . '">' . $i . '</a></li>';
			} else {
				$html .= '<li class="hidden-phone"><a title="' . $i . '" href="'
					. htmlspecialchars(self::url($feed, $i), ENT_QUOTES, 'UTF-8')
					. '" class="pagenav" aria-label="Перейти на ' . $i . '">' . $i . '</a></li>';
			}
		}

		$html .= $page < $pages
			? $link($page + 1, 'Перейти на следующую страницу', '<span class="icon-next" aria-hidden="true"></span>')
			. $link($pages, 'Перейти на последнюю страницу', '<span class="icon-last" aria-hidden="true"></span>')
			: $disabled('<span class="icon-next" aria-hidden="true"></span>')
			. $disabled('<span class="icon-last" aria-hidden="true"></span>');

		return $html . '</ul></div>';
	}

	/**
	 * Models: the component's own list model, "newest" order (search id DESC), one card per search offer.
	 *
	 * @return array{items: list<object>, total: int}
	 */
	private static function loadModeli(string $city, int $page): array
	{
		$app = Factory::getApplication();
		$config = ['ignore_request' => true];
		$component = $app->bootComponent('com_modeli');
		$model = $component instanceof \Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface
			? $component->getMVCFactory()->createModel('List', 'Site', $config)
			: new \Viglin\Component\Modeli\Site\Model\ListModel($config);

		$model->setState('cat_id', 0);
		$model->setState('city', $city);
		$model->setState('area', '');
		$model->setState('home', []);
		$model->setState('payment', []);
		$model->setState('children', 0);
		$model->setState('booking_mode', '');
		$model->setState('avail_date', '');
		$model->setState('list.ordering', 'newest');
		$model->setState('list.direction', 'DESC');
		$model->setState('list.limit', self::LIMIT);
		$model->setState('list.start', ($page - 1) * self::LIMIT);

		return [
			'items' => array_values((array) $model->getItems()),
			'total' => (int) $model->getTotal(),
		];
	}

	private static function renderModeli(array $items): string
	{
		if ($items === []) {
			return '';
		}

		$userIds = array_values(array_unique(array_map(static function ($item): int {
			return (int) ($item->master_id ?? 0);
		}, $items)));
		$fieldsByUser = $userIds !== []
			? \Viglin\Component\Modeli\Site\Helper\ModeliHelper::getFieldsForUserIds($userIds, self::FIELD_NAMES)
			: [];

		$file = JPATH_SITE . '/components/com_modeli/tmpl/list/default_item.php';
		$render = static function (object $item) use ($file, $fieldsByUser): string {
			ob_start();
			include $file;

			return (string) ob_get_clean();
		};

		$html = '';
		foreach ($items as $item) {
			$html .= $render($item);
		}

		return $html;
	}

	/**
	 * Promotions: one card per promotion row, newest first (promotion id DESC).
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	private static function loadAktsii(string $city, int $page): array
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$fieldIds = self::userFieldIds($db, ['vyberite_spetsialnos', 'sity']);
		$fieldSpec = (int) ($fieldIds['vyberite_spetsialnos'] ?? 0);
		$fieldCity = (int) ($fieldIds['sity'] ?? 0);
		if ($fieldSpec <= 0) {
			return ['items' => [], 'total' => 0];
		}

		$itemId = 'CAST(' . $db->quoteName('u.id') . ' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci';
		$apply = static function ($query) use ($db, $itemId, $fieldSpec, $fieldCity, $city) {
			$query
				->where($db->quoteName('s.is_active') . ' = 1')
				->where($db->quoteName('s.count_stock') . ' > 0')
				->where($db->quoteName('u.block') . ' = 0')
				->where(
					'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_values', 'specfv')
					. ' WHERE ' . $db->quoteName('specfv.item_id') . ' = ' . $itemId
					. ' AND ' . $db->quoteName('specfv.field_id') . ' = ' . $fieldSpec
					. ' AND ' . $db->quoteName('specfv.value') . ' <> ' . $db->quote('')
					. ' AND ' . $db->quoteName('specfv.value') . ' <> ' . $db->quote('{}') . ')'
				);
			if ($city !== '' && $fieldCity > 0) {
				$query->where(
					'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__fields_values', 'cityfv')
					. ' WHERE ' . $db->quoteName('cityfv.item_id') . ' = ' . $itemId
					. ' AND ' . $db->quoteName('cityfv.field_id') . ' = ' . $fieldCity
					. ' AND (' . $db->quoteName('cityfv.value') . ' = ' . $db->quote($city)
					. ' OR ' . $db->quoteName('cityfv.value') . ' = ' . $db->quote(' ' . $city)
					. ' OR ' . $db->quoteName('cityfv.value') . ' = ' . $db->quote($city . ' ') . '))'
				);
			}

			return $query;
		};
		$from = static function ($query) use ($db) {
			return $query
				->from($db->quoteName('#__vigling_user_stock_services', 's'))
				->join('INNER', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('s.user_id'));
		};

		$countQuery = $apply($from($db->getQuery(true)->select('COUNT(*)')));
		$db->setQuery($countQuery);
		$total = (int) $db->loadResult();
		if ($total === 0) {
			return ['items' => [], 'total' => 0];
		}

		$columns = ['s.id' => 'promo_id', 's.user_id' => 'user_id', 'u.name' => 'name', 's.legacy_cat_id' => 'legacy_cat_id',
			's.legacy_tag_id' => 'legacy_tag_id', 's.price' => 'price', 's.old_price' => 'old_price', 's.count_stock' => 'count_stock',
			's.about_stock' => 'about_stock', 's.duration_min' => 'duration_min'];
		$select = static function (bool $withRecommendation) use ($db, $columns): array {
			$cols = [];
			foreach ($columns as $column => $alias) {
				$cols[] = $db->quoteName($column, $alias);
			}
			if ($withRecommendation) {
				$cols[] = $db->quoteName('s.recommendation', 'recommendation');
			}

			return $cols;
		};
		$offset = ($page - 1) * self::LIMIT;

		try {
			$listQuery = $apply($from($db->getQuery(true)->select($select(true))))
				->order($db->quoteName('s.id') . ' DESC')
				->setLimit(self::LIMIT, $offset);
			$db->setQuery($listQuery);
			$rows = $db->loadAssocList() ?: [];
		} catch (\Throwable $e) {
			$listQuery = $apply($from($db->getQuery(true)->select($select(false))))
				->order($db->quoteName('s.id') . ' DESC')
				->setLimit(self::LIMIT, $offset);
			$db->setQuery($listQuery);
			$rows = $db->loadAssocList() ?: [];
		}

		return ['items' => $rows, 'total' => $total];
	}

	private static function renderAktsii(array $rows): string
	{
		if ($rows === []) {
			return '';
		}

		if (!class_exists(\Viglin\Component\Poisk\Site\Helper\PoiskHelper::class)) {
			$file = JPATH_SITE . '/components/com_poisk/src/Helper/PoiskHelper.php';
			if (is_file($file)) {
				require_once $file;
			}
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$userIds = array_values(array_unique(array_map(static function (array $row): int {
			return (int) $row['user_id'];
		}, $rows)));
		$fieldsByUser = \Viglin\Component\Poisk\Site\Helper\PoiskHelper::getFieldsForUserIds($userIds, self::FIELD_NAMES);
		$categoryByUser = self::specialistCategoryByUser($db, $userIds);

		$catIds = array_values(array_unique(array_filter(array_map('intval', array_values($categoryByUser)))));
		$serviceIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
			return (int) $row['legacy_cat_id'];
		}, $rows))));
		$tagIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
			return (int) $row['legacy_tag_id'];
		}, $rows))));

		$sanitize = class_exists('\\Joomla\\Plugin\\User\\Vigling\\Service\\UserServicesService');
		$file = JPATH_SITE . '/components/com_aktsii/tmpl/list/default_item.php';
		$view = new VglHomeFeedCardView();
		$view->allCategories = self::titlesById($db, '#__categories', $catIds, ['extension' => 'com_content', 'published' => 1, 'level' => 2]);
		$view->allServices = self::titlesById($db, '#__content', $serviceIds, ['state' => 1]);
		$view->allTags = self::titlesById($db, '#__tags', $tagIds, ['published' => 1]);
		$view->categoryByUser = $categoryByUser;

		$html = '';
		$styleSeen = false;
		foreach ($rows as $row) {
			$userId = (int) $row['user_id'];
			$recommendation = (string) ($row['recommendation'] ?? '');
			$view->stocksByUser = [
				$userId => [[
					'cat_id' => (int) $row['legacy_cat_id'],
					'tag_id' => (int) $row['legacy_tag_id'],
					'price' => (float) $row['price'],
					'old_price' => $row['old_price'] !== null ? (float) $row['old_price'] : null,
					'stock_count' => (int) $row['count_stock'],
					'comment' => $row['about_stock'],
					'duration' => (int) $row['duration_min'],
					'recommendation' => $sanitize
						? \Joomla\Plugin\User\Vigling\Service\UserServicesService::sanitizeRecommendation($recommendation)
						: trim($recommendation),
				]],
			];
			$item = (object) ['id' => $userId, 'name' => (string) $row['name']];
			$fields = $fieldsByUser[$userId] ?? $fieldsByUser[(string) $userId] ?? [];

			$card = $view->capture($file, ['item' => $item, 'fields' => $fields]);
			// The card template carries a large shared <style> block; keep it once.
			$card = (string) preg_replace_callback('#<style\b[^>]*>.*?</style>#s', static function (array $m) use (&$styleSeen): string {
				if (!$styleSeen) {
					$styleSeen = true;

					return $m[0];
				}

				return '';
			}, $card);
			$html .= $card;
		}

		return $html;
	}

	/**
	 * @param list<string> $names
	 * @return array<string, int>
	 */
	private static function userFieldIds($db, array $names): array
	{
		$query = $db->getQuery(true)
			->select([$db->quoteName('name'), $db->quoteName('id')])
			->from($db->quoteName('#__fields'))
			->where($db->quoteName('context') . ' = ' . $db->quote('com_users.user'))
			->where($db->quoteName('name') . ' IN (' . implode(',', array_map([$db, 'quote'], $names)) . ')');
		$db->setQuery($query);
		$ids = [];
		foreach ($db->loadAssocList() ?: [] as $row) {
			$ids[(string) $row['name']] = (int) $row['id'];
		}

		return $ids;
	}

	/**
	 * @param list<int> $userIds
	 * @return array<int, int>
	 */
	private static function specialistCategoryByUser($db, array $userIds): array
	{
		$ids = self::userFieldIds($db, ['vyberite_spetsialnos']);
		$fieldId = (int) ($ids['vyberite_spetsialnos'] ?? 0);
		if ($fieldId <= 0 || $userIds === []) {
			return [];
		}

		$query = $db->getQuery(true)
			->select([$db->quoteName('fv.item_id'), $db->quoteName('fv.value')])
			->from($db->quoteName('#__fields_values', 'fv'))
			->where($db->quoteName('fv.field_id') . ' = ' . $fieldId)
			->whereIn($db->quoteName('fv.item_id'), array_map('strval', $userIds), \Joomla\Database\ParameterType::STRING);
		$db->setQuery($query);

		$result = [];
		foreach ($db->loadObjectList() ?: [] as $row) {
			$value = json_decode((string) $row->value, true);
			if (is_array($value) && isset($value[0])) {
				$result[(int) $row->item_id] = (int) $value[0];
			}
		}

		return $result;
	}

	/**
	 * @param list<int>             $ids
	 * @param array<string, mixed>  $where
	 * @return array<int, array<string, mixed>>
	 */
	private static function titlesById($db, string $table, array $ids, array $where): array
	{
		if ($ids === []) {
			return [];
		}

		$query = $db->getQuery(true)
			->select([$db->quoteName('id'), $db->quoteName('title')])
			->from($db->quoteName($table))
			->whereIn($db->quoteName('id'), $ids);
		foreach ($where as $column => $value) {
			$query->where($db->quoteName($column) . ' = ' . (is_int($value) ? $value : $db->quote((string) $value)));
		}
		$db->setQuery($query);

		return $db->loadAssocList('id') ?: [];
	}
}

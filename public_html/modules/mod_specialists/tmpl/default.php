<?php

\defined('_JEXEC') or die;

/** @var \Joomla\CMS\Helper\ModuleHelper $module */
/** @var array $listUrl */
/** @var array $categories */
/** @var bool $showCategories */
/** @var bool $showHomeFilter */

$listUrl = $listUrl ?? '/specialists-list.php';
$categories = $categories ?? [];
$showCategories = $showCategories ?? true;
$showHomeFilter = $showHomeFilter ?? true;

$homeLabels = [1 => 'Салон', 2 => 'Вызов на дом', 3 => 'Мастер на дому'];
?>
<div class="mod-specialists mod-specialists-search<?php echo $module->class_sfx ? ' ' . htmlspecialchars($module->class_sfx) : ''; ?>">
	<div class="category__masters-sidebar">
		<h2>Поиск специалистов</h2>
		<form action="<?php echo htmlspecialchars($listUrl); ?>" class="form-horizontal filter" method="get">
			<div class="masters-sidebar__body">
				<span class="clearable">
					<input type="text" name="city" class="filed__master" placeholder="Город" value="">
				</span>
				<span class="clearable">
					<input type="text" name="area" class="filed__master" placeholder="Район" value="">
				</span>
				<?php if ($showCategories && !empty($categories)) : ?>
					<span class="clearable">
						<select name="cat_id" class="filed__master">
							<option value="0">Специальность</option>
							<?php foreach ($categories as $id => $cat) : ?>
								<option value="<?php echo (int) $id; ?>"><?php echo htmlspecialchars(is_array($cat) ? ($cat['title'] ?? '') : $cat); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
				<?php endif; ?>
				<?php if ($showHomeFilter) : ?>
					<span class="clearable">
						<label>Вид услуги:</label>
						<?php foreach ($homeLabels as $val => $label) : ?>
							<label class="checkbox-inline"><input type="checkbox" name="home[]" value="<?php echo $val; ?>"> <?php echo htmlspecialchars($label); ?></label>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
			</div>
			<input type="hidden" name="limit" value="20">
			<input class="submit__search" type="submit" value="Поиск">
		</form>
		<p class="mod-specialists-link"><a href="<?php echo htmlspecialchars($listUrl); ?>">Все специалисты →</a></p>
	</div>
</div>

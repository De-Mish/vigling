<?php
/**
 * Удаляет из #__extensions и #__menu:
 * - Akeeba Backup package, Astroid Package, COM_JLSitemap;
 * - все расширения Easy Profile (пакет, плагины, модули, файл).
 * Запуск: php migration_scripts/23_remove_akeeba_astroid_jlsitemap_easyprofile.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
if (!is_file($base . '/configuration.php')) {
    $base = dirname($base);
}
require_once __DIR__ . '/load_j6_config.php';
$cfg = loadJ6Config($base . '/configuration.php');
if (!$cfg || empty($cfg->db)) {
    die("Ошибка: configuration.php или БД.\n");
}

$mysqli = new mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->db);
if ($mysqli->connect_error) {
    die("Ошибка подключения: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$prefix = $cfg->dbprefix;
$extTable = $prefix . 'extensions';
$menuTable = $prefix . 'menu';

$nameLike = $mysqli->real_escape_string('%Easy Profile%');
$akeebaLike = $mysqli->real_escape_string('%Akeeba Backup%');
$astroidLike = $mysqli->real_escape_string('%Astroid%');
$jlsitemapLike = $mysqli->real_escape_string('%JLSitemap%');

$res = $mysqli->query("SELECT extension_id, type, element, folder, name FROM `{$extTable}` WHERE "
    . " name LIKE '{$nameLike}' OR name LIKE '{$akeebaLike}' OR name LIKE '{$astroidLike}' OR name LIKE '{$jlsitemapLike}' "
    . " OR (type = 'component' AND element = 'com_jlsitemap') "
    . " OR (type = 'package' AND (element LIKE 'pkg_akeeba%' OR element LIKE 'pkg_astroid%' OR element = 'pkg_easyprofile'))");
if (!$res) {
    die("Ошибка SELECT: " . $mysqli->error . "\n");
}

$ids = [];
$found = [];
while ($row = $res->fetch_object()) {
    $ids[] = (int) $row->extension_id;
    $found[] = $row->type . '/' . ($row->folder ? $row->folder . '/' : '') . $row->element . ' — ' . $row->name;
}

if (empty($ids)) {
    echo "В БД не найдено ни одного из перечисленных расширений. Нечего удалять.\n";
    $mysqli->close();
    exit(0);
}

echo "Найдено записей в #__extensions:\n";
foreach ($found as $f) {
    echo "  - " . $f . "\n";
}

$idList = implode(',', $ids);
$delMenu = $mysqli->query("DELETE FROM `{$menuTable}` WHERE component_id IN ({$idList})");
$menuCount = $delMenu !== false ? $mysqli->affected_rows : 0;
if ($delMenu === false) {
    echo "Ошибка удаления из #__menu: " . $mysqli->error . "\n";
} else {
    echo "Удалено пунктов меню: {$menuCount}\n";
}

$delExt = $mysqli->query("DELETE FROM `{$extTable}` WHERE extension_id IN ({$idList})");
$extCount = $delExt !== false ? $mysqli->affected_rows : 0;
if ($delExt === false) {
    echo "Ошибка удаления из #__extensions: " . $mysqli->error . "\n";
} else {
    echo "Удалено записей расширений: {$extCount}\n";
}

$mysqli->close();
echo "Готово. Обновите страницу админки.\n";

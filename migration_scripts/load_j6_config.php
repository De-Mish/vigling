<?php
/**
 * Читает J6 configuration.php без require (чтобы не переобъявлять класс JConfig).
 * Возвращает объект с полями: host, user, password, db, dbprefix.
 */
function loadJ6Config($path) {
    if (!is_file($path)) {
        return null;
    }
    $content = file_get_contents($path);
    $obj = new stdClass();
    $props = ['host', 'user', 'password', 'db', 'dbprefix'];
    foreach ($props as $p) {
        if (preg_match('/public\s+\$' . $p . '\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/', $content, $m)) {
            $obj->$p = $m[1];
        } elseif (preg_match('/public\s+\$' . $p . '\s*=\s*(true|false|\d+)\s*;/', $content, $m)) {
            $obj->$p = $m[1] === 'true' ? true : ($m[1] === 'false' ? false : (int) $m[1]);
        } else {
            $obj->$p = '';
        }
    }

    $envMap = [
        'JOOMLA_DB_HOST' => 'host',
        'JOOMLA_DB_USER' => 'user',
        'JOOMLA_DB_PASSWORD' => 'password',
        'JOOMLA_DB_NAME' => 'db',
    ];
    foreach ($envMap as $envName => $property) {
        $value = getenv($envName);
        if ($value !== false && $value !== '') {
            $obj->$property = $value;
        }
    }

    return $obj;
}

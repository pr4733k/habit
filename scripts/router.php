<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && preg_match('~^/daily/(\d{4}-\d{2}-\d{2})\.json$~D', $path, $match)) {
    $_GET = ['action' => 'day', 'date' => $match[1]];
    require $_SERVER['DOCUMENT_ROOT'] . '/daily/api.php';
    return true;
}
if (in_array(rtrim((string)$path, '/'), ['/daily', '/log', '/daily/log'], true)
    && is_file($_SERVER['DOCUMENT_ROOT'] . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($_SERVER['DOCUMENT_ROOT'] . '/index.html');
    return true;
}
return false;

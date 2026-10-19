<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file = is_string($path) ? realpath($_SERVER['DOCUMENT_ROOT'] . $path) : false;
if ($file !== false && str_starts_with($file,rtrim($_SERVER['DOCUMENT_ROOT'],'/') . '/') && is_file($file)) { return false; }
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';

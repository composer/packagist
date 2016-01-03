<?php

ini_set('date.timezone', 'UTC');

use Symfony\Component\ClassLoader\ApcClassLoader;
use Symfony\Component\HttpFoundation\Request;

/**
 * @var \Symfony\Component\ClassLoader\ClassLoader
 */
$loader = require __DIR__.'/../app/autoload.php';
include_once __DIR__.'/../app/bootstrap.php.cache';

// Use APC for autoloading to improve performance.
if (function_exists('apc_store')) {
    $apcLoader = new ApcClassLoader(sha1(__FILE__), $loader);
    $loader->unregister();
    $apcLoader->register(true);
}

$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

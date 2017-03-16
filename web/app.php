<?php

use Symfony\Component\HttpFoundation\Request;

/**
 * @var \Symfony\Component\ClassLoader\ClassLoader
 */
$loader = require __DIR__.'/../app/autoload.php';
include_once __DIR__.'/../app/bootstrap.php.cache';

$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();

if ($_SERVER['REMOTE_ADDR'] === '144.217.203.53') {
    Request::setTrustedProxies([$_SERVER['REMOTE_ADDR']]);
    // force all trusted header names
    Request::setTrustedHeaderName(Request::HEADER_FORWARDED, '');
    Request::setTrustedHeaderName(Request::HEADER_CLIENT_IP, 'X_REAL_IP');
    Request::setTrustedHeaderName(Request::HEADER_CLIENT_HOST, '');
    Request::setTrustedHeaderName(Request::HEADER_CLIENT_PROTO, '');
    Request::setTrustedHeaderName(Request::HEADER_CLIENT_PORT, '');
}

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

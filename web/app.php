<?php

ini_set('date.timezone', 'UTC');

use App\Kernel;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/config/bootstrap.php';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);

    Debug::enable();
}

if ($trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? false) {
    Request::setTrustedProxies(explode(',', $trustedProxies), Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
}

if ($trustedHosts = $_SERVER['TRUSTED_HOSTS'] ?? false) {
    Request::setTrustedHosts([$trustedHosts]);
}

Request::setTrustedProxies(
    // remote_addr is set to the correct client IP but we need to mark it trusted so that Symfony picks up the X-Forwarded-Host,
    // X-Forwarded-Port and X-Forwarded-Proto headers correctly and sees the right request URL
    [$_SERVER['REMOTE_ADDR']],
    // Use all X-Forwarded-* headers except X-Forwarded-For as nginx handles the IP computation
    Request::HEADER_X_FORWARDED_AWS_ELB ^ Request::HEADER_X_FORWARDED_FOR
);

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

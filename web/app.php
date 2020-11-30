<?php

use Symfony\Component\HttpFoundation\Request;

require __DIR__.'/../app/autoload.php';
$kernel = new AppKernel('prod', false);

if (PHP_VERSION_ID < 70100) {
    if (in_array($_SERVER['REMOTE_ADDR'], ['144.217.203.53', '54.38.136.239', '54.37.131.18', '142.44.164.249', '142.44.164.255', '54.37.2.184', '139.99.121.122', '54.37.4.73', '51.38.227.34'], true)) {
        Request::setTrustedProxies([$_SERVER['REMOTE_ADDR']]);
        // force all trusted header names
        Request::setTrustedHeaderName(Request::HEADER_FORWARDED, '');
        Request::setTrustedHeaderName(Request::HEADER_CLIENT_IP, 'X_REAL_IP');
        Request::setTrustedHeaderName(Request::HEADER_CLIENT_HOST, '');
        Request::setTrustedHeaderName(Request::HEADER_CLIENT_PROTO, '');
        Request::setTrustedHeaderName(Request::HEADER_CLIENT_PORT, '');
    }
} else {
    Request::setTrustedProxies(
        // remote_addr is set to the correct client IP but we need to mark it trusted so that Symfony picks up the X-Forwarded-Host,
        // X-Forwarded-Port and X-Forwarded-Proto headers correctly and sees the right request URL
        [$_SERVER['REMOTE_ADDR']],
        // Use all X-Forwarded-* headers except X-Forwarded-For as nginx handles the IP computation
        Request::HEADER_X_FORWARDED_AWS_ELB ^ Request::HEADER_X_FORWARDED_FOR
    );
}

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

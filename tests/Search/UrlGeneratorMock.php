<?php declare(strict_types=1);

namespace App\Tests\Search;

use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\RequestContext;

final class UrlGeneratorMock extends CompiledUrlGenerator
{
    public function __construct()
    {
        // extracted from var/cache/dev/url_generating_routes.php
        $routes = [
            'view_providers' => [['name', '_format'], ['_format' => 'html', '_controller' => 'App\\Controller\\PackageController::viewProvidersAction'], ['name' => '[A-Za-z0-9/_.-]+?', '_format' => '(json)'], [['variable', '.', '(?:json)', '_format', true], ['variable', '/', '[A-Za-z0-9/_.-]+?', 'name', true], ['text', '/providers']], [], [], []],
            'view_package' => [['name', '_format'], ['_format' => 'html', '_controller' => 'App\\Controller\\PackageController::viewPackageAction'], ['name' => '[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?', '_format' => '(json)'], [['variable', '.', '(?:json)', '_format', true], ['variable', '/', '[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?', 'name', true], ['text', '/packages']], [], [], []],
            'search_api' => [[], ['_controller' => 'App\\Controller\\WebController::searchApi'], [], [['text', '/search.json']], [], [], []],
        ];

        parent::__construct($routes, new RequestContext());
    }
}

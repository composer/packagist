<?php

use App\Kernel;

require dirname(__DIR__).'/vendor/autoload_runtime.php';

// ignore until https://github.com/doctrine/DoctrineBundle/issues/1895 is fixed
Doctrine\Deprecations\Deprecation::ignoreDeprecations(
    'https://github.com/doctrine/orm/pull/12005', // Ignore proxy related deprecations from upstream packages
);

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};

<?php

use Doctrine\Common\Annotations\AnnotationRegistry;

if (!$loader = @include __DIR__.'/../vendor/autoload.php') {

    $message = <<< EOF
<p>You must set up the project dependencies by running composer install</p>

EOF;

    if (PHP_SAPI === 'cli') {
        $message = strip_tags($message);
    }

    die($message);
}

AnnotationRegistry::registerLoader(array($loader, 'loadClass'));

return $loader;

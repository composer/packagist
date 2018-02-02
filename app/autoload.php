<?php

//ini_set('date.timezone', 'UTC');

if (!class_exists('\PHPUnit\Framework\TestCase', true)) {
    class_alias('\PHPUnit_Framework_TestCase', '\PHPUnit\Framework\TestCase');
} elseif (!class_exists('\PHPUnit_Framework_TestCase', true)) {
    class_alias('\PHPUnit\Framework\TestCase', '\PHPUnit_Framework_TestCase');
}

if (!class_exists('\PHPUnit\Util\Test', true)) {
    class_alias('\PHPUnit_Util_Test', '\PHPUnit\Util\Test');
} elseif (!class_exists('\PHPUnit_Util_Test', true)) {
    class_alias('\PHPUnit\Util\Test', '\PHPUnit_Util_Test');
}


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

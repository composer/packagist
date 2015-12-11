<?php

use Doctrine\Common\Annotations\AnnotationRegistry;

error_reporting(error_reporting() & ~E_USER_DEPRECATED);

if (!$loader = @include __DIR__.'/../vendor/autoload.php') {

    $message = <<< EOF
<p>You must set up the project dependencies by running the following commands:</p>
<pre>
    curl -s http://getcomposer.org/installer | php
    php composer.phar install
</pre>

EOF;

    if (PHP_SAPI === 'cli') {
        $message = strip_tags($message);
    }

    die($message);
}

AnnotationRegistry::registerLoader(array($loader, 'loadClass'));

return $loader;

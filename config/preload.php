<?php

foreach (glob(dirname(__DIR__).'/var/cache/prod/*.preload.php') ?: [] as $file) {
    require $file;
}

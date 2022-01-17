<?php

use staabm\PHPStanDba\QueryReflection\RuntimeConfiguration;
use staabm\PHPStanDba\QueryReflection\MysqliQueryReflector;
use staabm\PHPStanDba\QueryReflection\QueryReflection;
use staabm\PHPStanDba\QueryReflection\RecordingQueryReflector;
use staabm\PHPStanDba\QueryReflection\ReplayQueryReflector;
use staabm\PHPStanDba\QueryReflection\ReflectionCache;

require_once __DIR__ . '/vendor/autoload.php';

// phpstan-dba
$cacheFile = __DIR__.'/.phpstan-dba.cache';
$config = new RuntimeConfiguration();
// $config->debugMode(true);

$env = new \Symfony\Component\Dotenv\Dotenv();
$env->load(__DIR__.'/.env', __DIR__.'/.env.local');
$dsn = parse_url($_SERVER['DATABASE_URL']);

QueryReflection::setupReflector(
    new RecordingQueryReflector(
        ReflectionCache::create(
            $cacheFile
        ),
        new MysqliQueryReflector(new mysqli($dsn['host'], $dsn['user'], $dsn['pass'] ?? '', trim($dsn['path'], '/'), $dsn['port'] ?? 3306))
    ),
    $config
);

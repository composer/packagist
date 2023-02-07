<?php

declare(strict_types=1);

use App\Validator\Copyright;
use App\Validator\Password;
use App\Validator\TypoSquatters;
use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Php80\Rector\Class_\AnnotationToAttributeRector;
use Rector\Php80\ValueObject\AnnotationToAttribute;
use Rector\PHPUnit\Set\PHPUnitLevelSetList;
use Rector\Symfony\Set\SymfonySetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

    // register a single rule
    $rectorConfig->rule(InlineConstructorDefaultToPropertyRector::class);

    $rectorConfig->ruleWithConfiguration(AnnotationToAttributeRector::class, [
        new AnnotationToAttribute(Password::class),
        new AnnotationToAttribute(TypoSquatters::class),
        new AnnotationToAttribute(Copyright::class),
    ]);

    // define sets of rules
    $rectorConfig->sets([
        SymfonySetList::SYMFONY_62,
        DoctrineSetList::DOCTRINE_ORM_29,
        PHPUnitLevelSetList::UP_TO_PHPUNIT_100,
    ]);
};

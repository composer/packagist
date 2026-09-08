<?php

declare(strict_types=1);

use App\Validator\Copyright;
use App\Validator\Password;
use App\Validator\TypoSquatters;
use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\AnnotationToAttributeRector;
use Rector\Php80\ValueObject\AnnotationToAttribute;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withImportNames()
    ->withPreparedSets(symfonyCodeQuality: true)
    ->withComposerBased(
        twig: true,
        doctrine: true,
        phpunit: true,
        symfony: true,
    )
    ->withRules([InlineConstructorDefaultToPropertyRector::class])
    ->withConfiguredRule(AnnotationToAttributeRector::class, [
        new AnnotationToAttribute(Password::class),
        new AnnotationToAttribute(TypoSquatters::class),
        new AnnotationToAttribute(Copyright::class),
    ]);

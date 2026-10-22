<?php

$header = <<<EOF
This file is part of Packagist.

(c) Jordi Boggiano <j.boggiano@seld.be>
    Nils Adermann <naderman@naderman.de>

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF;

$finder = PhpCsFixer\Finder::create()
    ->files()
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->name('*.php')
    ->notPath('Fixtures')
    ->notPath('Search/results')
;

$config = new PhpCsFixer\Config();
return $config
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PHP8x4Migration' => true,
        '@PHPUnit10x0Migration:risky' => true,
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,

        // overrides
        'blank_line_after_opening_tag' => false,
        'linebreak_after_opening_tag' => false,
        'yoda_style' => false,
        'phpdoc_summary' => false,
        'increment_style' => false,
        'declare_strict_types' => true,
        'single_line_throw' => false,
    ])
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;

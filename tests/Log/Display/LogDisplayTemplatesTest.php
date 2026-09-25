<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Log\Display;

use PHPUnit\Framework\TestCase;

/**
 * The two logs share one folder of display partials, so a partial that is renamed for one of them must
 * not leave the other pointing at a template that no longer exists.
 */
class LogDisplayTemplatesTest extends TestCase
{
    private const DISPLAY_DIR = __DIR__.'/../../../src/Log/Display';
    private const TEMPLATE_DIR = __DIR__.'/../../../templates/log/display';

    public function testEveryTemplateReferencedByADisplayClassExists(): void
    {
        foreach (self::referencedTemplates() as $template => $classes) {
            self::assertFileExists(
                self::TEMPLATE_DIR.'/'.$template,
                $template.' is referenced by '.implode(', ', $classes).' but does not exist',
            );
        }
    }

    public function testEveryTemplateIsReferencedByADisplayClass(): void
    {
        $referenced = self::referencedTemplates();

        foreach (glob(self::TEMPLATE_DIR.'/*.html.twig') as $file) {
            self::assertArrayHasKey(
                basename($file),
                $referenced,
                basename($file).' is not referenced by any display class and looks orphaned',
            );
        }
    }

    /**
     * @return array<string, list<string>> template file name => referencing classes
     */
    private static function referencedTemplates(): array
    {
        $referenced = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DISPLAY_DIR));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("{'log/display/([a-z_]+\.html\.twig)'}", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $template) {
                $referenced[$template][] = $file->getBasename('.php');
            }
        }

        self::assertNotEmpty($referenced, 'no display class references a template, the scan is broken');

        return $referenced;
    }
}

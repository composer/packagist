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

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dev-only: versions assets by file mtime so rebuilt CSS/JS bypasses the browser cache.
 */
class MtimeVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/web')]
        private readonly string $publicDir,
    ) {
    }

    public function getVersion(string $path): string
    {
        $mtime = @filemtime($this->publicDir.'/'.ltrim($path, '/'));

        return false === $mtime ? '' : (string) $mtime;
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        return '' === $version ? $path : $path.'?v='.$version;
    }
}

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

namespace App\HtmlSanitizer;

use Composer\Pcre\Preg;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class ReadmeImageSanitizer implements AttributeSanitizerInterface
{
    public function __construct(private ?string $host, private string $ownerRepo, private string $basePath)
    {
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function getSupportedElements(): ?array
    {
        return ['img'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if (!str_contains($value, '//')) {
            return match ($this->host) {
                'github.com' => 'https://raw.github.com/'.$this->ownerRepo.'/HEAD/'.$this->basePath.$value,
                'gitlab.com' => 'https://gitlab.com/'.$this->ownerRepo.'/-/raw/HEAD/'.$this->basePath.$value,
                'bitbucket.org' => 'https://bitbucket.org/'.$this->ownerRepo.'/raw/HEAD/'.$this->basePath.$value,
                default => $value,
            };
        }

        if (str_starts_with($value, 'https://private-user-images.githubusercontent.com/')) {
            return Preg::replace('{^https://private-user-images.githubusercontent.com/\d+/\d+-([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\.\w+\?.*$}', 'https://github.com/user-attachments/assets/$1', $value, 1);
        }

        return $value;
    }
}

<?php

namespace App\HtmlSanitizer;

use Composer\Pcre\Preg;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class ReadmeLinkSanitizer implements AttributeSanitizerInterface
{
    public function __construct(private string|null $host, private string $ownerRepo, private string $basePath)
    {
    }

    public function getSupportedAttributes(): ?array
    {
        return ['href', 'target', 'id'];
    }

    public function getSupportedElements(): ?array
    {
        return ['a'];
    }

    /**
     * @param 'href'|'target'|'id' $attribute
     * @param string $value
     */
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ($attribute === 'target') {
            if ($value !== '') {
                return '_blank';
            }

            return null;
        }

        if ($attribute === 'id') {
            if (!str_starts_with($value, 'user-content-')) {
                return 'user-content-'.$value;
            }

            return $value;
        }

        if (str_starts_with($value, '#') && !str_starts_with($value, '#user-content-')) {
            return '#user-content-'.substr($value, 1);
        }

        if (str_starts_with($value, 'mailto:')) {
            return $value;
        }

        if ($this->host === 'github.com' && !str_contains($value, '//')) {
            return 'https://github.com/'.$this->ownerRepo.'/blob/HEAD/'.$this->basePath.$value;
        }

        if ($this->host === 'gitlab.com' && !str_contains($value, '//')) {
            return 'https://gitlab.com/'.$this->ownerRepo.'/-/blob/HEAD/'.$this->basePath.$value;
        }

        if (str_starts_with($value, 'https://private-user-images.githubusercontent.com/')) {
            return Preg::replace('{^https://private-user-images.githubusercontent.com/\d+/\d+-([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\.\w+\?.*$}', 'https://github.com/user-attachments/assets/$1', $value, 1);
        }

        return $value;
    }
}

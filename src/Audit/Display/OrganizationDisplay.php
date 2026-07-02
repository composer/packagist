<?php declare(strict_types=1);

namespace App\Audit\Display;

/**
 * @phpstan-type OrganizationDisplayArray array{id: string, slug: string, display_name: string}
 */
final readonly class OrganizationDisplay
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $displayName,
    ) {}

    /**
     * @param OrganizationDisplayArray $record
     */
    public static function fromRecord(array $record): self
    {
        return new self(
            $record['id'],
            $record['slug'],
            $record['display_name'],
        );
    }

    /**
     * @return OrganizationDisplayArray
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'display_name' => $this->displayName,
        ];
    }
}

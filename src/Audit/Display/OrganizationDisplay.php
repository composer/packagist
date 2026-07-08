<?php declare(strict_types=1);

namespace App\Audit\Display;

/**
 * @phpstan-type OrganizationDisplayArray array{id: string, org_slug: string, org_name: string}
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
            $record['org_slug'],
            $record['org_name'],
        );
    }

    /**
     * @return OrganizationDisplayArray
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'org_slug' => $this->slug,
            'org_name' => $this->displayName,
        ];
    }
}

<?php

namespace App\Package;

use App\Entity\Version;
use Symfony\Contracts\EventDispatcher\Event;

final readonly class UpdateVersionInformationResult
{
    public function __construct(
        public bool $updated,
        public ?int $id,
        public string $version,
        public ?Version $entity = null,
        /** @var list<Event> $events */
        public array $events = [],
    ) {}
}

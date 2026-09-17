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

namespace App\Support\Attributes;

/**
 * The type-specific payload of a support request, stored in the support_request.attributes JSON
 * column and mapped back to the right class by {@see \App\Support\SupportRequestType::hydrateAttributes()}.
 *
 * Adding a request type means adding one of these, not another nullable column.
 */
interface SupportRequestAttributes
{
    /** @return array<string, mixed> JSON-serialisable form, the inverse of each class's fromArray() */
    public function toArray(): array;

    /**
     * The distinguishing bit for the admin queue's one-line summary, or null for types whose payload
     * says nothing a human would scan for, which fall back to the free-text description.
     */
    public function summary(): ?string;
}

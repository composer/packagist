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

namespace App\Form\Model;

use App\Entity\FilterListEntry;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use Composer\Semver\VersionParser;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[UniqueEntity(
    fields: ['list', 'packageName', 'version', 'source'],
    entityClass: FilterListEntry::class,
    identifierFieldNames: ['id'],
    message: 'A filter list entry for this list, package, version and source already exists.',
)]
class FilterListEntryRequest
{
    public ?int $id = null;

    #[Assert\NotNull]
    public ?FilterLists $list = null;

    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '{^[a-zA-Z0-9]++(?:[_.-]?[a-zA-Z0-9]++)*+/[a-zA-Z0-9]++(?:[_.-]?[a-zA-Z0-9]++)*+$}',
        message: 'Use the canonical "vendor/name" form of a package name.',
    )]
    public string $packageName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $version = '';

    public ?string $reason = null;

    public ?string $link = null;

    public ?string $internalNote = null;

    public FilterSources $source = FilterSources::PACKAGIST;

    public static function createFromEntry(FilterListEntry $entry): self
    {
        $request = new self();
        $request->id = $entry->getId();
        $request->list = $entry->getList();
        $request->packageName = $entry->getPackageName();
        $request->version = $entry->getVersion();
        $request->reason = $entry->getReason();
        $request->link = $entry->getLink();
        $request->internalNote = $entry->getInternalNote();
        $request->source = $entry->getSource();

        return $request;
    }

    #[Assert\Callback]
    public function validateVersionConstraint(ExecutionContextInterface $context): void
    {
        if ($this->version === '') {
            return;
        }

        try {
            (new VersionParser())->parseConstraints($this->version);
        } catch (\UnexpectedValueException $e) {
            $context->buildViolation('Invalid version constraint: %message%')
                ->setParameter('%message%', $e->getMessage())
                ->atPath('version')
                ->addViolation();
        }
    }
}

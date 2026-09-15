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

use App\FilterList\FilterLists;
use Composer\Pcre\Preg;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Backs the admin "add filter list entries" form, where the package name and
 * version constraint are entered as "<vendor/name> <constraint>" one per line
 * and every other field is applied to each of them.
 */
class FilterListEntryBulkRequest
{
    /** Each line costs a uniqueness query, so cap what a single paste can trigger. */
    private const MAX_LINES = 500;

    #[Assert\NotNull]
    public ?FilterLists $list = null;

    #[Assert\NotBlank]
    public string $packages = '';

    public ?string $reason = null;

    public ?string $link = null;

    public ?string $internalNote = null;

    /**
     * @return list<BulkFilterListLine>
     */
    public function parseLines(): array
    {
        $lines = [];
        foreach (Preg::split('{\R}u', $this->packages) as $index => $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            // Only the first run of whitespace separates the two, so multi-token
            // constraints such as ">=1.0 <2.0" survive intact.
            $parts = Preg::split('{\s+}', $line, 2);

            $lines[] = new BulkFilterListLine($index + 1, $parts[0], $parts[1] ?? '');
        }

        return $lines;
    }

    public function toEntryRequest(BulkFilterListLine $line): FilterListEntryRequest
    {
        $request = new FilterListEntryRequest();
        $request->list = $this->list;
        $request->packageName = $line->packageName;
        $request->version = $line->version;
        $request->reason = $this->reason;
        $request->link = $this->link;
        $request->internalNote = $this->internalNote;

        return $request;
    }

    #[Assert\Callback]
    public function validateEntries(ExecutionContextInterface $context): void
    {
        // Without a list (its own NotNull reports that) there is no slot to check against.
        if ($this->list === null) {
            return;
        }

        $lines = $this->parseLines();
        if (\count($lines) > self::MAX_LINES) {
            $context->buildViolation('Too many entries: %count% lines given, at most %max% can be added at once.')
                ->setParameter('%count%', (string) \count($lines))
                ->setParameter('%max%', (string) self::MAX_LINES)
                ->atPath('packages')
                ->addViolation();

            return;
        }

        /** @var array<string, int> $seen */
        $seen = [];
        foreach ($lines as $line) {
            if ($line->version === '') {
                $context->buildViolation('Line %line%: a version constraint is required after the package name.')
                    ->setParameter('%line%', (string) $line->lineNumber)
                    ->atPath('packages')
                    ->addViolation();

                continue;
            }

            // Validating the expanded request as a separate object (rather than
            // nesting it into this context) keeps every message on the packages
            // path, and reuses its name/constraint rules and uniqueness check.
            foreach ($context->getValidator()->validate($this->toEntryRequest($line)) as $violation) {
                $context->buildViolation('Line %line% (%package%): %message%')
                    ->setParameter('%line%', (string) $line->lineNumber)
                    ->setParameter('%package%', $line->packageName)
                    ->setParameter('%message%', (string) $violation->getMessage())
                    ->atPath('packages')
                    ->addViolation();
            }

            // UniqueEntity only looks at the database, so two identical lines would
            // otherwise pass validation and collide on the unique index at flush time.
            $slot = $this->list->value."\0".$line->packageName."\0".$line->version;
            if (isset($seen[$slot])) {
                $context->buildViolation('Line %line%: duplicates line %first% (%package% %version%).')
                    ->setParameter('%line%', (string) $line->lineNumber)
                    ->setParameter('%first%', (string) $seen[$slot])
                    ->setParameter('%package%', $line->packageName)
                    ->setParameter('%version%', $line->version)
                    ->atPath('packages')
                    ->addViolation();

                continue;
            }

            $seen[$slot] = $line->lineNumber;
        }
    }
}

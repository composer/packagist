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

use App\Entity\AuditRecord;
use App\Entity\PackageTransparencyLog;
use App\Log\AuditLogEventType;
use App\Log\Display\TransparencyLogDisplayFactory;
use App\Log\TransparencyLogEventType;
use App\Log\TransparencyLogScrubber;
use App\Tests\IntegrationTestCase;
use App\Tests\Log\TransparencyLogScrubberTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class TransparencyLogTemplateTest extends IntegrationTestCase
{
    /**
     * Enum-backed reasons render as a label from these groups.
     */
    private const REASON_LABEL_GROUPS = ['abandonment_reason', 'freeze_reason', 'deletion_reason'];

    /**
     * @return iterable<string, array{AuditLogEventType, AuditRecord}>
     */
    public static function projectedRecords(): iterable
    {
        foreach (TransparencyLogScrubberTest::projectedRecords() as $name => [$type, $record, ]) {
            yield $name => [$type, $record];
        }
    }

    #[DataProvider('projectedRecords')]
    public function testEveryPublishedValueIsShown(AuditLogEventType $auditType, AuditRecord $record): void
    {
        $type = TransparencyLogEventType::fromAuditLogEventType($auditType);
        self::assertNotNull($type);

        $attributes = new TransparencyLogScrubber()->scrub($auditType, $record->attributes);
        $entry = PackageTransparencyLog::project($record, $type, 0, $attributes, 42, 'acme', 'acme/widget');
        $display = new TransparencyLogDisplayFactory()->buildSingle($entry);

        $html = self::getService(Environment::class)->render($display->getTemplateName(), ['display' => $display]);
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5);

        $missing = [];
        foreach (self::leaves($attributes) as $path => $value) {
            if ($value === null) {
                continue;
            }

            // a short number would match anywhere
            $renderings = str_ends_with('.'.$path, '.id') ? ['#'.$value] : $this->renderings((string) $value);
            $shown = array_filter(
                $renderings,
                static fn (string $rendering): bool => str_contains($text, $rendering),
            );
            if ($shown === []) {
                $missing[] = $path;
            }
        }

        self::assertSame([], $missing, $type->value.': published but not shown on the page');
    }

    /**
     * The value itself, or the label of an enum-backed reason.
     *
     * @return list<string>
     */
    private function renderings(string $value): array
    {
        $translator = self::getService(TranslatorInterface::class);
        $renderings = [$value];
        foreach (self::REASON_LABEL_GROUPS as $group) {
            $key = 'log.'.$group.'.'.$value;
            if ($translator->getCatalogue()->has($key)) {
                $renderings[] = $translator->trans($key);
            }
        }

        return $renderings;
    }

    /**
     * Dotted path => scalar leaf.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<string, scalar|null>
     */
    private static function leaves(array $value, string $prefix = ''): array
    {
        $leaves = [];
        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (\is_array($item)) {
                $leaves = [...$leaves, ...self::leaves($item, $path)];
                continue;
            }

            \assert(\is_scalar($item) || $item === null);
            $leaves[$path] = $item;
        }

        return $leaves;
    }
}

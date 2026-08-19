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

namespace App\Tests\Audit;

use App\Audit\TransparencyLogScrubber;
use PHPUnit\Framework\TestCase;

class TransparencyLogScrubberTest extends TestCase
{
    public function testRemovesEmailsInternalNotesAndMetadataButKeepsPublicData(): void
    {
        $scrubber = new TransparencyLogScrubber();

        $scrubbed = $scrubber->scrub([
            'name' => 'acme/widget',
            'repository' => 'https://github.com/acme/widget',
            'reason' => 'public takedown notice',
            'reasonText' => 'visible to everyone',
            'internalReason' => 'reporter jane@example.com, ticket #42',
            'internalReasonText' => 'internal moderation note',
            'internal_note' => 'private',
            'email' => 'user@example.com',
            'email_from' => 'old@example.com',
            'email_to' => 'new@example.com',
            'actor' => ['id' => 7, 'username' => 'bob'],
            'metadata' => ['source' => ['reference' => 'abc123']],
            'nested' => ['internalReason' => 'deep secret', 'keep' => 'ok'],
        ]);

        // dropped
        self::assertArrayNotHasKey('internalReason', $scrubbed);
        self::assertArrayNotHasKey('internalReasonText', $scrubbed);
        self::assertArrayNotHasKey('internal_note', $scrubbed);
        self::assertArrayNotHasKey('email', $scrubbed);
        self::assertArrayNotHasKey('email_from', $scrubbed);
        self::assertArrayNotHasKey('email_to', $scrubbed);
        self::assertArrayNotHasKey('metadata', $scrubbed);

        // kept
        self::assertSame('acme/widget', $scrubbed['name']);
        self::assertSame('public takedown notice', $scrubbed['reason']);
        self::assertSame('visible to everyone', $scrubbed['reasonText']);
        self::assertSame(['id' => 7, 'username' => 'bob'], $scrubbed['actor']);

        // denylisted keys are removed recursively, siblings preserved
        self::assertArrayNotHasKey('internalReason', $scrubbed['nested']);
        self::assertSame('ok', $scrubbed['nested']['keep']);
    }
}

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

namespace App\Tests\SecurityAdvisory;

use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisoryCollection;
use App\SecurityAdvisory\SecurityAdvisoryResolver;
use PHPUnit\Framework\TestCase;

class SecurityAdvisoryResolverTest extends TestCase
{
    private SecurityAdvisoryResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SecurityAdvisoryResolver();
    }

    public function testResolveAddNewAdvisory(): void
    {
        [$new, $removed] = $this->resolver->resolve([], new RemoteSecurityAdvisoryCollection([$this->createRemoteAdvisory('test')]), 'test');

        $this->assertSame([], $removed);
        $this->assertCount(1, $new);
    }

    public function testResolveAddNewMarksOldAdvisoryWithdrawnDifferentPackage(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', 'acme/other-package'), 'test');
        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$this->createRemoteAdvisory('test')]), 'test');

        $this->assertSame([$advisory], $withdrawn);
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertNotNull($advisory->getWithdrawnAt());
        $this->assertTrue($advisory->hasSources());
        $this->assertCount(1, $new);
    }

    public function testResolveAddNewMarksOldAdvisoryWithdrawnSamePackage(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', 'acme/package', 'CVE-2022-1111'), 'test');
        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$this->createRemoteAdvisory('test', 'acme/package', 'CVE-2022-2222')]), 'test');

        $this->assertSame([$advisory], $withdrawn);
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertCount(1, $new);
    }

    public function testResolveMarksOldAdvisoryWithdrawn(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([$advisory], $withdrawn);
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertTrue($advisory->hasSources());
    }

    public function testResolveDontRemoveAdvisoryFromOtherSource(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('other'), 'other');
        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);

        $this->assertTrue($advisory->hasSources());
        $this->assertFalse($advisory->isWithdrawn());
    }

    public function testResolveDontRemoveAdvisoryWithMultipleSources(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->addSource('other-id', 'other', null);
        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);

        $this->assertTrue($advisory->hasSources());
        $this->assertFalse($advisory->isWithdrawn());
        $this->assertNull($advisory->getSourceRemoteId('test'));
        $this->assertNotNull($advisory->getSourceRemoteId('other'));
    }

    public function testResolveAddSourceToMatchingAdvisory(): void
    {
        $remoteAdvisory = $this->createRemoteAdvisory('test');
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('other'), 'other');
        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);

        $this->assertNotNull($advisory->getSourceRemoteId('test'));
        $this->assertNotNull($advisory->getSourceRemoteId('other'));
    }

    public function testResolveRemoteIdChangedSameCve(): void
    {
        $remoteAdvisory = $this->createRemoteAdvisory('test', cve: 'CVE-2024-9999999999');
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-9999999999'), 'test');
        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);

        $this->assertSame($remoteAdvisory->id, $advisory->getSourceRemoteId('test'));
    }

    public function testResolveReMatchingAWithdrawnAdvisoryUnWithdrawsIt(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->withdraw();
        $this->assertTrue($advisory->isWithdrawn());

        $remoteId = $advisory->getSourceRemoteId('test');
        $this->assertNotNull($remoteId);
        $remoteAdvisory = new RemoteSecurityAdvisory($remoteId, 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', null, new \DateTimeImmutable(), null, [], 'test', null);

        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->isWithdrawn());
        $this->assertNull($advisory->getWithdrawnAt());
    }

    public function testResolveDoesNotResurrectWithdrawnAdvisoryThroughFuzzyMatch(): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $advisory = new SecurityAdvisory(
            new RemoteSecurityAdvisory('old-id', 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-1111', $date, null, [], 'test', null),
            'test',
        );
        $advisory->withdraw();

        // Same package/title/link/versions/date, only the remote id differs and the CVE is gone:
        // a low enough difference score that it would fuzzy-match were the advisory still active.
        $newRemote = new RemoteSecurityAdvisory('new-id', 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', null, $date, null, [], 'test', null);

        [$new, $withdrawn] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$newRemote]), 'test');

        $this->assertCount(1, $new);
        $this->assertNotSame($advisory, $new[0]);
        $this->assertSame('new-id', $new[0]->getRemoteId());
        $this->assertTrue($advisory->isWithdrawn());
    }

    public function testResolveKeepsAdvisoryWithdrawnWhenAnActiveAdvisoryHoldsTheCve(): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $active = new SecurityAdvisory(
            new RemoteSecurityAdvisory('active-id', 'Active advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-3333', $date, null, [], 'test', null),
            'test',
        );
        $withdrawnAdvisory = new SecurityAdvisory(
            new RemoteSecurityAdvisory('stale-id', 'Stale advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-3333', $date, null, [], 'test', null),
            'test',
        );
        $withdrawnAdvisory->withdraw();

        // The source reports the stale advisory as live again while the active one still owns the CVE.
        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('active-id', 'Active advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-3333', $date, null, [], 'test', null),
            new RemoteSecurityAdvisory('stale-id', 'Stale advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-3333', $date, null, [], 'test', null),
        ]);

        [$new, $withdrawn] = $this->resolver->resolve([$active, $withdrawnAdvisory], $collection, 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($active->isWithdrawn());
        $this->assertTrue($withdrawnAdvisory->isWithdrawn(), 'must stay withdrawn while the active advisory owns the CVE');
        $this->assertNotNull($withdrawnAdvisory->getWithdrawnAt());
    }

    public function testResolveUnWithdrawsWhenTheAdvisoryHoldingTheCveIsWithdrawnInTheSameRun(): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $droppedByTheSource = new SecurityAdvisory(
            new RemoteSecurityAdvisory('ghsa-a', 'Advisory A', 'acme/package', '^1.0', 'https://example.org/a', 'CVE-2024-1111', $date, null, [], 'test', null),
            'test',
        );
        $reportedAsLiveAgain = new SecurityAdvisory(
            new RemoteSecurityAdvisory('ghsa-b', 'Advisory B', 'acme/package', '^1.0', 'https://example.org/b', 'CVE-2024-1111', $date, null, [], 'test', null),
            'test',
        );
        $reportedAsLiveAgain->withdraw();

        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('ghsa-b', 'Advisory B', 'acme/package', '^1.0', 'https://example.org/b', 'CVE-2024-1111', $date, null, [], 'test', null),
        ]);

        [$new, $withdrawn] = $this->resolver->resolve([$droppedByTheSource, $reportedAsLiveAgain], $collection, 'test');

        $this->assertSame([], $new);
        $this->assertSame([$droppedByTheSource], $withdrawn);
        $this->assertTrue($droppedByTheSource->isWithdrawn());
        $this->assertFalse($reportedAsLiveAgain->isWithdrawn(), 'the advisory the source reports as live must not stay withdrawn once the CVE holder is withdrawn in the same run');
    }

    public function testResolveEmpty(): void
    {
        [$new, $withdrawn] = $this->resolver->resolve([], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
    }

    public function testRemoveWithdrawnMarksSingleSourceAdvisoryAsWithdrawn(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $remoteId = $advisory->getSourceRemoteId('test');
        $this->assertNotNull($remoteId);
        $collection = new RemoteSecurityAdvisoryCollection([], ['acme/package' => [$remoteId => true]]);

        [$remaining, $withdrawn] = $this->resolver->removeWithdrawn([$advisory], $collection, 'test');

        $this->assertSame([], $remaining);
        $this->assertSame([$advisory], $withdrawn);
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertNotNull($advisory->getWithdrawnAt());
        // The source is left attached so the advisory remains discoverable for historical lookups.
        $this->assertTrue($advisory->hasSources());
    }

    public function testRemoveWithdrawnKeepsAdvisoryWithRemainingSource(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->addSource('other-id', 'other', null);
        $remoteId = $advisory->getSourceRemoteId('test');
        $this->assertNotNull($remoteId);
        $collection = new RemoteSecurityAdvisoryCollection([], ['acme/package' => [$remoteId => true]]);

        [$remaining, $withdrawn] = $this->resolver->removeWithdrawn([$advisory], $collection, 'test');

        $this->assertSame([$advisory], $remaining);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->isWithdrawn());
        $this->assertNull($advisory->getSourceRemoteId('test'));
        $this->assertNotNull($advisory->getSourceRemoteId('other'));
    }

    public function testRemoveWithdrawnIgnoresUnknownRemoteId(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $collection = new RemoteSecurityAdvisoryCollection([], ['acme/package' => ['unknown-id' => true]]);

        [$remaining, $withdrawn] = $this->resolver->removeWithdrawn([$advisory], $collection, 'test');

        $this->assertSame([$advisory], $remaining);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->isWithdrawn());
        $this->assertTrue($advisory->hasSources());
    }

    public function testRemoveWithdrawnIgnoresOtherSource(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('other'), 'other');
        $remoteId = $advisory->getSourceRemoteId('other');
        $this->assertNotNull($remoteId);
        $collection = new RemoteSecurityAdvisoryCollection([], ['acme/package' => [$remoteId => true]]);

        [$remaining, $withdrawn] = $this->resolver->removeWithdrawn([$advisory], $collection, 'test');

        $this->assertSame([$advisory], $remaining);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->isWithdrawn());
        $this->assertTrue($advisory->hasSources());
    }

    private function createRemoteAdvisory(string $source, string $packageName = 'acme/package', ?string $cve = null): RemoteSecurityAdvisory
    {
        return new RemoteSecurityAdvisory(
            uniqid('id-'),
            'Security Advisory',
            $packageName,
            '^1.0',
            'https://example.org',
            $cve,
            new \DateTimeImmutable(),
            null,
            [],
            $source,
            null,
        );
    }
}

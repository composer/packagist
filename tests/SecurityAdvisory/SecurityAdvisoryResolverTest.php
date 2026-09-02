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
use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisoryCollection;
use App\SecurityAdvisory\SecurityAdvisoryResolver;
use PHPUnit\Framework\Attributes\DataProvider;
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
        [$new, $removed] = $this->resolve([], new RemoteSecurityAdvisoryCollection([$this->createRemoteAdvisory('test')]), 'test');

        $this->assertSame([], $removed);
        $this->assertCount(1, $new);
    }

    public function testResolveAddNewMarksOldAdvisoryWithdrawnDifferentPackage(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', 'acme/other-package'), 'test');
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$this->createRemoteAdvisory('test')]), 'test');

        $this->assertSame([$advisory], $withdrawn);
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertNotNull($advisory->getWithdrawnAt());
        $this->assertTrue($advisory->hasSources());
        $this->assertCount(1, $new);
    }

    public function testResolveAddNewMarksOldAdvisoryWithdrawnSamePackage(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', 'acme/package', 'CVE-2022-1111'), 'test');
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$this->createRemoteAdvisory('test', 'acme/package', 'CVE-2022-2222')]), 'test');

        $this->assertSame([$advisory], $withdrawn);
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertCount(1, $new);
    }

    public function testResolveMarksOldAdvisoryWithdrawn(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([$advisory], $withdrawn);
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertTrue($advisory->hasSources());
    }

    public function testResolveDontRemoveAdvisoryFromOtherSource(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('other'), 'other');
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);

        $this->assertTrue($advisory->hasSources());
        $this->assertFalse($advisory->isWithdrawn());
    }

    public function testResolveDontRemoveAdvisoryWithMultipleSources(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->addSource('other-id', 'other', null);
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);

        $this->assertFalse($advisory->isWithdrawn());
        $this->assertCount(2, $advisory->getSources());
        $this->assertTrue($advisory->findSecurityAdvisorySource('test')?->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource('other')?->isWithdrawn());
    }

    public function testResolveAddSourceToMatchingAdvisory(): void
    {
        $remoteAdvisory = $this->createRemoteAdvisory('test');
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('other'), 'other');
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);

        $this->assertNotNull($advisory->getSourceRemoteId('test'));
        $this->assertNotNull($advisory->getSourceRemoteId('other'));
    }

    public function testResolveRemoteIdChangedSameCve(): void
    {
        $remoteAdvisory = $this->createRemoteAdvisory('test', cve: 'CVE-2024-9999999999');
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-9999999999'), 'test');
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);

        $this->assertSame($remoteAdvisory->id, $advisory->getSourceRemoteId('test'));
    }

    public function testResolveReMatchingAWithdrawnAdvisoryUnWithdrawsIt(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->withdrawSource('test');
        $this->assertTrue($advisory->isWithdrawn());

        $remoteId = $advisory->getSourceRemoteId('test');
        $this->assertNotNull($remoteId);
        $remoteAdvisory = new RemoteSecurityAdvisory($remoteId, 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', null, new \DateTimeImmutable(), null, [], 'test', null);

        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

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
        $advisory->withdrawSource('test');

        // Same package/title/link/versions/date, only the remote id differs and the CVE is gone:
        // a low enough difference score that it would fuzzy-match were the advisory still active.
        $newRemote = new RemoteSecurityAdvisory('new-id', 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', null, $date, null, [], 'test', null);

        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$newRemote]), 'test');

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
        $withdrawnAdvisory->withdrawSource('test');

        // The source reports the stale advisory as live again while the active one still owns the CVE.
        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('active-id', 'Active advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-3333', $date, null, [], 'test', null),
            new RemoteSecurityAdvisory('stale-id', 'Stale advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-3333', $date, null, [], 'test', null),
        ]);

        [$new, $withdrawn] = $this->resolve([$active, $withdrawnAdvisory], $collection, 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($active->isWithdrawn());
        $this->assertTrue($withdrawnAdvisory->isWithdrawn(), 'must stay withdrawn while the active advisory owns the CVE');
        $this->assertNotNull($withdrawnAdvisory->getWithdrawnAt());
        $this->assertTrue($withdrawnAdvisory->findSecurityAdvisorySource('test')?->isWithdrawn(), 'the source must stay withdrawn with the advisory');
    }

    public function testResolveRevivesOnALaterRunOnceTheCveHolderIsGone(): void
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
        $withdrawnAdvisory->withdrawSource('test');

        $staleRemote = new RemoteSecurityAdvisory('stale-id', 'Stale advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-3333', $date, null, [], 'test', null);

        // First run: the CVE is still taken, so nothing is revived.
        $this->resolve([$active, $withdrawnAdvisory], new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('active-id', 'Active advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-3333', $date, null, [], 'test', null),
            $staleRemote,
        ]), 'test');

        $this->assertTrue($withdrawnAdvisory->isWithdrawn());

        // Second run: the source drops the advisory that held the CVE.
        [$new, $withdrawn] = $this->resolve([$active, $withdrawnAdvisory], new RemoteSecurityAdvisoryCollection([$staleRemote]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([$active], $withdrawn);
        $this->assertFalse($withdrawnAdvisory->isWithdrawn());
        $this->assertFalse($withdrawnAdvisory->findSecurityAdvisorySource('test')?->isWithdrawn());
    }

    public function testResolveReinstatesAWithdrawnSourceOnAnAdvisoryThatStayedActive(): void
    {
        $remoteAdvisory = $this->createRemoteAdvisory('test');
        $advisory = new SecurityAdvisory($remoteAdvisory, 'test');
        $advisory->addSource('other-id', 'other', null);
        $advisory->withdrawSource('test');

        $this->assertFalse($advisory->isWithdrawn(), 'the other source keeps the advisory alive');
        $this->assertTrue($advisory->findSecurityAdvisorySource('test')?->isWithdrawn());

        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource('test')?->isWithdrawn(), 'the source lists it again, so it must no longer be flagged withdrawn');
        $this->assertFalse($advisory->findSecurityAdvisorySource('other')?->isWithdrawn());
    }

    public function testResolveAddsANewSourceToAWithdrawnAdvisoryAndRevivesIt(): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $advisory = new SecurityAdvisory(
            new RemoteSecurityAdvisory('other-id', 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-4444', $date, null, [], 'other', null),
            'other',
        );
        $advisory->withdrawSource('other');
        $this->assertTrue($advisory->isWithdrawn());

        // A different source picks the advisory up, matched on package name + CVE.
        $remoteAdvisory = new RemoteSecurityAdvisory('test-id', 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2022-4444', $date, null, [], 'test', null);

        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource('test')?->isWithdrawn());
        $this->assertTrue($advisory->findSecurityAdvisorySource('other')?->isWithdrawn(), 'the source that dropped it stays withdrawn');
    }

    public function testResolveWithdrawsTheAdvisoryOnlyOnceTheLastSourceDropsIt(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->addSource('other-id', 'other', null);

        [, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->isWithdrawn());

        [, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'other');

        $this->assertSame([$advisory], $withdrawn);
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertFalse($advisory->hasActiveSources());
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
        $reportedAsLiveAgain->withdrawSource('test');

        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('ghsa-b', 'Advisory B', 'acme/package', '^1.0', 'https://example.org/b', 'CVE-2024-1111', $date, null, [], 'test', null),
        ]);

        [$new, $withdrawn] = $this->resolve([$droppedByTheSource, $reportedAsLiveAgain], $collection, 'test');

        $this->assertSame([], $new);
        $this->assertSame([$droppedByTheSource], $withdrawn);
        $this->assertTrue($droppedByTheSource->isWithdrawn());
        $this->assertFalse($reportedAsLiveAgain->isWithdrawn(), 'the advisory the source reports as live must not stay withdrawn once the CVE holder is withdrawn in the same run');
    }

    public function testResolveRevivesOnlyOneOfTwoWithdrawnAdvisoriesSharingACve(): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $first = new SecurityAdvisory(
            new RemoteSecurityAdvisory('first-id', 'First advisory', 'acme/package', '^1.0', 'https://example.org/1', 'CVE-2022-5555', $date, null, [], 'test', null),
            'test',
        );
        $second = new SecurityAdvisory(
            new RemoteSecurityAdvisory('second-id', 'Second advisory', 'acme/package', '^1.0', 'https://example.org/2', 'CVE-2022-5555', $date, null, [], 'test', null),
            'test',
        );
        $first->withdrawSource('test');
        $second->withdrawSource('test');

        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('first-id', 'First advisory', 'acme/package', '^1.0', 'https://example.org/1', 'CVE-2022-5555', $date, null, [], 'test', null),
            new RemoteSecurityAdvisory('second-id', 'Second advisory', 'acme/package', '^1.0', 'https://example.org/2', 'CVE-2022-5555', $date, null, [], 'test', null),
        ]);

        [$new, $withdrawn] = $this->resolve([$first, $second], $collection, 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertCount(1, array_filter([$first, $second], static fn (SecurityAdvisory $a) => !$a->isWithdrawn()), 'only one of them may hold the CVE');
        $this->assertSame($first->isWithdrawn(), $first->findSecurityAdvisorySource('test')?->isWithdrawn());
        $this->assertSame($second->isWithdrawn(), $second->findSecurityAdvisorySource('test')?->isWithdrawn());
    }

    public function testResolveKeepsAdvisoryWithdrawnWhenANewAdvisoryTakesItsCve(): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $withdrawnAdvisory = new SecurityAdvisory(
            new RemoteSecurityAdvisory('stale-id', 'Stale advisory', 'acme/package', '^1.0', 'https://example.org/stale', 'CVE-2022-6666', $date, null, [], 'test', null),
            'test',
        );
        $withdrawnAdvisory->withdrawSource('test');

        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('stale-id', 'Stale advisory', 'acme/package', '^1.0', 'https://example.org/stale', 'CVE-2022-6666', $date, null, [], 'test', null),
            new RemoteSecurityAdvisory('fresh-id', 'Fresh advisory', 'acme/package', '^2.0', 'https://example.org/fresh', 'CVE-2022-6666', $date, null, [], 'test', null),
        ]);

        [$new, $withdrawn] = $this->resolve([$withdrawnAdvisory], $collection, 'test');

        $this->assertCount(1, $new);
        $this->assertSame('fresh-id', $new[0]->getRemoteId());
        $this->assertSame([], $withdrawn);
        $this->assertTrue($withdrawnAdvisory->isWithdrawn(), 'the new advisory owns the CVE now');
        $this->assertTrue($withdrawnAdvisory->findSecurityAdvisorySource('test')?->isWithdrawn());
    }

    /**
     * The source hands a withdrawn advisory's CVE to a sibling that had none, and re-lists the
     * withdrawn one in the same run. Whether the sibling is processed before or after the candidate
     * must not change the outcome: deciding from the entities would see the sibling's CVE only once
     * applyMatches() happened to reach it.
     */
    #[DataProvider('feedOrderProvider')]
    public function testResolveKeepsAdvisoryWithdrawnWhenASiblingIsAssignedItsCveInTheSameRun(bool $withdrawnFirst): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $sibling = new SecurityAdvisory(
            new RemoteSecurityAdvisory('gh-sibling', 'Sibling advisory', 'acme/package', '^1.0', 'https://example.org/s', null, $date, null, [], 'test', null),
            'test',
        );
        $withdrawnAdvisory = new SecurityAdvisory(
            new RemoteSecurityAdvisory('gh-withdrawn', 'Withdrawn advisory', 'acme/package', '^1.0', 'https://example.org/w', 'CVE-2024-1111', $date, null, [], 'test', null),
            'test',
        );
        $withdrawnAdvisory->withdrawSource('test');

        $withdrawnRemote = new RemoteSecurityAdvisory('gh-withdrawn', 'Withdrawn advisory', 'acme/package', '^1.0', 'https://example.org/w', 'CVE-2024-1111', $date, null, [], 'test', null);
        $siblingRemote = new RemoteSecurityAdvisory('gh-sibling', 'Sibling advisory', 'acme/package', '^1.0', 'https://example.org/s', 'CVE-2024-1111', $date, null, [], 'test', null);

        $collection = new RemoteSecurityAdvisoryCollection(
            $withdrawnFirst ? [$withdrawnRemote, $siblingRemote] : [$siblingRemote, $withdrawnRemote],
        );

        [$new, $withdrawn] = $this->resolve([$sibling, $withdrawnAdvisory], $collection, 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($sibling->isWithdrawn());
        $this->assertSame('CVE-2024-1111', $sibling->getCve());
        $this->assertTrue($withdrawnAdvisory->isWithdrawn(), 'the sibling holds the CVE once this run is applied');
        $this->assertTrue($withdrawnAdvisory->findSecurityAdvisorySource('test')?->isWithdrawn());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function feedOrderProvider(): array
    {
        return [
            'withdrawn advisory listed first' => [true],
            'sibling listed first' => [false],
        ];
    }

    public function testResolveDoesNotReportAnAlreadyWithdrawnAdvisoryAgain(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->withdrawSource('test');
        $withdrawnAt = $advisory->getWithdrawnAt();

        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn, 'nothing changed, so the worker has nothing to flush');
        $this->assertSame($withdrawnAt, $advisory->getWithdrawnAt());
    }

    public function testRemoveWithdrawnDoesNotReportAnAlreadyWithdrawnAdvisoryAgain(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->withdrawSource('test');
        $remoteId = $advisory->getSourceRemoteId('test');
        $this->assertNotNull($remoteId);

        [$remaining, $withdrawn] = $this->resolver->removeWithdrawn([$advisory], new RemoteSecurityAdvisoryCollection([], ['acme/package' => [$remoteId => true]]), 'test');

        $this->assertSame([], $remaining, 'a withdrawn advisory is still kept out of the plan');
        $this->assertSame([], $withdrawn);
    }

    public function testResolveKeepsAdvisoryWithdrawnWhenAnAdvisoryOfAnotherSourceHoldsTheCve(): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $friendsOfPhpOnly = new SecurityAdvisory(
            new RemoteSecurityAdvisory('acme/package/CVE-2024-0001.yaml', 'Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2024-0001', $date, null, [], FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null),
            FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME,
        );
        $gitHubOnly = new SecurityAdvisory(
            new RemoteSecurityAdvisory('GHSA-b', 'Advisory', 'acme/package', '^1.0', 'https://example.org/b', 'CVE-2024-0001', $date, null, [], GitHubSecurityAdvisoriesSource::SOURCE_NAME, null),
            GitHubSecurityAdvisoriesSource::SOURCE_NAME,
        );
        $gitHubOnly->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        // A GitHub run never matches the FriendsOfPHP advisory, but it still owns the CVE.
        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('GHSA-b', 'Advisory', 'acme/package', '^1.0', 'https://example.org/b', 'CVE-2024-0001', $date, null, [], GitHubSecurityAdvisoriesSource::SOURCE_NAME, null),
        ]);

        $this->resolve([$friendsOfPhpOnly, $gitHubOnly], $collection, GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertFalse($friendsOfPhpOnly->isWithdrawn());
        $this->assertTrue($gitHubOnly->isWithdrawn());
    }

    public function testResolveDecidesRevivalOnTheStoredCveNotTheFeedValue(): void
    {
        $date = new \DateTimeImmutable('2024-01-01');
        $advisory = new SecurityAdvisory(
            new RemoteSecurityAdvisory('GHSA-c', 'Advisory', 'acme/package', '^1.0', 'https://example.org/c', 'CVE-2024-0002', $date, null, [], GitHubSecurityAdvisoriesSource::SOURCE_NAME, null),
            GitHubSecurityAdvisoriesSource::SOURCE_NAME,
        );
        // FriendsOfPHP becomes the main source, so a GitHub run does not overwrite the stored CVE.
        $advisory->addSource('acme/package/CVE-2024-0002.yaml', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null, $date);
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->withdrawSource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);
        $holder = new SecurityAdvisory(
            new RemoteSecurityAdvisory('GHSA-h', 'Advisory', 'acme/package', '^1.0', 'https://example.org/h', 'CVE-2024-0003', $date, null, [], GitHubSecurityAdvisoriesSource::SOURCE_NAME, null),
            GitHubSecurityAdvisoriesSource::SOURCE_NAME,
        );

        // GitHub re-lists the advisory claiming the holder's CVE; storage keeps CVE-2024-0002, so nothing clashes.
        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory('GHSA-c', 'Advisory', 'acme/package', '^1.0', 'https://example.org/c', 'CVE-2024-0003', $date, null, [], GitHubSecurityAdvisoriesSource::SOURCE_NAME, null),
            new RemoteSecurityAdvisory('GHSA-h', 'Advisory', 'acme/package', '^1.0', 'https://example.org/h', 'CVE-2024-0003', $date, null, [], GitHubSecurityAdvisoriesSource::SOURCE_NAME, null),
        ]);

        $this->resolve([$advisory, $holder], $collection, GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertSame('CVE-2024-0002', $advisory->getCve());
        $this->assertFalse($advisory->isWithdrawn());
    }

    public function testResolveRenamesByAddingARowAndWithdrawingTheOldOne(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-0004'), 'test');
        $oldId = $advisory->getRemoteId();
        $advisory->withdrawSource('test');

        $renamed = new RemoteSecurityAdvisory('new-id', 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2024-0004', new \DateTimeImmutable(), null, [], 'test', null);
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$renamed]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertSame([$oldId, 'new-id'], $advisory->getSourceRemoteIds('test'), 'row ids are identifiers and are never rewritten');
        $this->assertTrue($advisory->findSecurityAdvisorySource('test', $oldId)?->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource('test', 'new-id')?->isWithdrawn());
        $this->assertFalse($advisory->isWithdrawn());
    }

    public function testResolveReListingAnOldIdReinstatesThatRowAndWithdrawsTheDroppedOne(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-0005'), 'test');
        $oldId = $advisory->getRemoteId();
        $advisory->addSource('new-id', 'test', null);
        $this->assertTrue($advisory->findSecurityAdvisorySource('test', $oldId)?->isWithdrawn());

        $reListed = new RemoteSecurityAdvisory($oldId, 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2024-0005', new \DateTimeImmutable(), null, [], 'test', null);
        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$reListed]), 'test');

        $this->assertSame([], $new, 'an id the source used before is an exact match, not a new advisory');
        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->findSecurityAdvisorySource('test', $oldId)?->isWithdrawn());
        $this->assertTrue($advisory->findSecurityAdvisorySource('test', 'new-id')?->isWithdrawn(), 'the id the feed dropped is withdrawn');
        $this->assertFalse($advisory->isWithdrawn());
    }

    public function testDeferContestedCvesHoldsBackTheTakerOnly(): void
    {
        $releaser = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-0006'), 'test');
        $taker = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-0007'), 'test');
        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory($releaser->getRemoteId(), 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2024-0008', new \DateTimeImmutable(), null, [], 'test', null),
            new RemoteSecurityAdvisory($taker->getRemoteId(), 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2024-0006', new \DateTimeImmutable(), null, [], 'test', null),
        ]);

        $plan = $this->resolver->planResolve([$releaser, $taker], $collection, 'test');
        $cvesBefore = [$releaser->getPackagistAdvisoryId() => 'CVE-2024-0006', $taker->getPackagistAdvisoryId() => 'CVE-2024-0007'];
        $this->resolver->applyMatches($plan);
        $deferred = $this->resolver->deferContestedCves($plan, $cvesBefore);

        $this->assertSame([$taker->getPackagistAdvisoryId() => 'CVE-2024-0006'], $deferred);
        $this->assertNull($taker->getCve(), 'held back until the release is flushed');
        $this->assertSame('CVE-2024-0008', $releaser->getCve(), 'the releaser goes out in the first flush');

        $this->resolver->assignDeferredCves($plan, $deferred);

        $this->assertSame('CVE-2024-0006', $taker->getCve());
    }

    public function testDeferContestedCvesHandlesASwap(): void
    {
        $first = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-0009'), 'test');
        $second = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-0010'), 'test');
        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory($first->getRemoteId(), 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2024-0010', new \DateTimeImmutable(), null, [], 'test', null),
            new RemoteSecurityAdvisory($second->getRemoteId(), 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2024-0009', new \DateTimeImmutable(), null, [], 'test', null),
        ]);

        $plan = $this->resolver->planResolve([$first, $second], $collection, 'test');
        $cvesBefore = [$first->getPackagistAdvisoryId() => 'CVE-2024-0009', $second->getPackagistAdvisoryId() => 'CVE-2024-0010'];
        $this->resolver->applyMatches($plan);
        $deferred = $this->resolver->deferContestedCves($plan, $cvesBefore);

        $this->assertCount(2, $deferred);
        $this->assertNull($first->getCve());
        $this->assertNull($second->getCve());

        $this->resolver->assignDeferredCves($plan, $deferred);

        $this->assertSame('CVE-2024-0010', $first->getCve());
        $this->assertSame('CVE-2024-0009', $second->getCve());
    }

    public function testDeferContestedCvesLeavesUncontestedChangesAlone(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test', cve: 'CVE-2024-0011'), 'test');
        $collection = new RemoteSecurityAdvisoryCollection([
            new RemoteSecurityAdvisory($advisory->getRemoteId(), 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', 'CVE-2024-0012', new \DateTimeImmutable(), null, [], 'test', null),
        ]);

        $plan = $this->resolver->planResolve([$advisory], $collection, 'test');
        $this->resolver->applyMatches($plan);

        $this->assertSame([], $this->resolver->deferContestedCves($plan, [$advisory->getPackagistAdvisoryId() => 'CVE-2024-0011']));
        $this->assertSame('CVE-2024-0012', $advisory->getCve());
    }

    public function testResolveEmpty(): void
    {
        [$new, $withdrawn] = $this->resolve([], new RemoteSecurityAdvisoryCollection([]), 'test');

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
        $this->assertCount(2, $advisory->getSources());
        $this->assertTrue($advisory->findSecurityAdvisorySource('test')?->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource('other')?->isWithdrawn());
    }

    public function testRemoveWithdrawnReinstatesTheSourceWhenItListsTheAdvisoryAgain(): void
    {
        $remoteAdvisory = $this->createRemoteAdvisory('test');
        $advisory = new SecurityAdvisory($remoteAdvisory, 'test');
        $advisory->withdrawSource('test');
        $this->assertTrue($advisory->isWithdrawn());

        [$new, $withdrawn] = $this->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $withdrawn);
        $this->assertFalse($advisory->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource('test')?->isWithdrawn());
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

    /**
     * Runs every step the worker runs, back to back. The worker flushes between them, which is what
     * SecurityAdvisoryWorkerIntegrationTest covers; this only checks the classification.
     *
     * @param SecurityAdvisory[] $existingAdvisories
     *
     * @return array{SecurityAdvisory[], SecurityAdvisory[]} [$newAdvisories, $withdrawnAdvisories]
     */
    private function resolve(array $existingAdvisories, RemoteSecurityAdvisoryCollection $remoteAdvisories, string $sourceName): array
    {
        [$existingAdvisories, $withdrawnAtSource] = $this->resolver->removeWithdrawn($existingAdvisories, $remoteAdvisories, $sourceName);
        $plan = $this->resolver->planResolve($existingAdvisories, $remoteAdvisories, $sourceName);
        $withdrawnAdvisories = [...$withdrawnAtSource, ...$this->resolver->applyWithdrawals($plan)];
        $cvesBefore = [];
        foreach ($plan->existingAdvisories as $advisory) {
            $cvesBefore[$advisory->getPackagistAdvisoryId()] = $advisory->getCve();
        }
        $newAdvisories = $this->resolver->applyMatches($plan);
        $this->resolver->assignDeferredCves($plan, $this->resolver->deferContestedCves($plan, $cvesBefore));
        $this->resolver->applyUnwithdrawals($plan, $newAdvisories);

        return [$newAdvisories, $withdrawnAdvisories];
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

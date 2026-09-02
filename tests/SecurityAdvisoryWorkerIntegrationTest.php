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

namespace App\Tests;

use App\Entity\Job;
use App\Entity\SecurityAdvisory;
use App\EventListener\SecurityAdvisoryUpdateListener;
use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisoryCollection;
use App\SecurityAdvisory\SecurityAdvisoryResolver;
use App\SecurityAdvisory\SecurityAdvisorySourceInterface;
use App\Service\Locker;
use App\Service\SecurityAdvisoryWorker;
use Composer\IO\ConsoleIO;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Psr\Log\NullLogger;
use Seld\Signal\SignalHandler;

class SecurityAdvisoryWorkerIntegrationTest extends IntegrationTestCase
{
    /**
     * Guards the package_name_active_cve_idx unique index itself: two ACTIVE (withdrawnAt IS NULL)
     * advisories must never coexist for the same (packageName, cve). This is the case a naive
     * (packageName, cve, withdrawnAt) composite index would fail to catch, because MySQL exempts a
     * row from a unique index entirely once any indexed column is NULL - and withdrawnAt is NULL for
     * every active row. The activeCve generated column (NULL only when withdrawn) is what makes this
     * still throw.
     */
    public function testTwoActiveAdvisoriesCannotShareTheSameCve(): void
    {
        $first = new SecurityAdvisory($this->remoteAdvisory('GHSA-aaaa-0000-0000', 'acme/package', 'CVE-2024-40004'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $second = new SecurityAdvisory($this->remoteAdvisory('GHSA-bbbb-1111-1111', 'acme/package', 'CVE-2024-40004'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->store($first, $second);
    }

    /**
     * Exercises the real unique constraint on (packageName, activeCve): a withdrawn advisory keeps
     * its CVE (with a non-null withdrawnAt, so activeCve is NULL), while the source reassigns the
     * same CVE to another (live) advisory in the same run. Since activeCve is NULL for the withdrawn
     * row, it is exempt from the uniqueness check and both rows can coexist.
     */
    public function testWithdrawnAdvisoryFreesCveForReassignment(): void
    {
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-old-0000-0000', 'acme/package', 'CVE-2024-10001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $survivor = new SecurityAdvisory($this->remoteAdvisory('GHSA-new-1111-1111', 'acme/package', 'CVE-2024-20002'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($withdrawn, $survivor);

        $collection = new RemoteSecurityAdvisoryCollection(
            [$this->remoteAdvisory('GHSA-new-1111-1111', 'acme/package', 'CVE-2024-10001')],
            ['acme/package' => ['GHSA-old-0000-0000' => true]],
        );

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/package');

        $this->assertCount(2, $advisories);

        $active = array_values(array_filter($advisories, static fn (SecurityAdvisory $a) => !$a->isWithdrawn()));
        $withdrawnAdvisories = array_values(array_filter($advisories, static fn (SecurityAdvisory $a) => $a->isWithdrawn()));

        $this->assertCount(1, $active);
        $this->assertSame('GHSA-new-1111-1111', $active[0]->getRemoteId());
        $this->assertSame('CVE-2024-10001', $active[0]->getCve());

        $this->assertCount(1, $withdrawnAdvisories);
        $this->assertSame('GHSA-old-0000-0000', $withdrawnAdvisories[0]->getRemoteId());
        $this->assertSame('CVE-2024-10001', $withdrawnAdvisories[0]->getCve());
        $this->assertNotNull($withdrawnAdvisories[0]->getWithdrawnAt());
    }

    public function testPureOrphanWithdrawnAdvisoryIsKeptButMarkedWithdrawn(): void
    {
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-orphan-0000', 'acme/orphan', 'CVE-2024-30003'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($withdrawn);

        $collection = new RemoteSecurityAdvisoryCollection([], ['acme/orphan' => ['GHSA-orphan-0000' => true]]);

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/orphan');

        $this->assertCount(1, $advisories);
        $this->assertTrue($advisories[0]->isWithdrawn());
        $this->assertNotNull($advisories[0]->getWithdrawnAt());

        // Withdrawn advisories must not surface in the active composer audit / API results any more.
        $active = $this->getEM()->getRepository(SecurityAdvisory::class)->getPackageSecurityAdvisories('acme/orphan');
        $this->assertSame([], $active);
    }

    /**
     * Same reassignment as {@see self::testWithdrawnAdvisoryFreesCveForReassignment()}, but the
     * advisory that inherits the CVE was stored first, so Doctrine computes its change set (and runs
     * its UPDATE) before the withdrawal UPDATE. Both land in the same flush, so without the worker's
     * intermediate flush the outcome hangs on entity order and can trip package_name_active_cve_idx.
     */
    public function testWithdrawnAdvisoryFreesCveForReassignmentWhateverTheEntityOrder(): void
    {
        $survivor = new SecurityAdvisory($this->remoteAdvisory('GHSA-live-2222-2222', 'acme/reordered', 'CVE-2024-40004'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-stale-3333-3333', 'acme/reordered', 'CVE-2024-50005'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($survivor, $withdrawn);

        $collection = new RemoteSecurityAdvisoryCollection(
            [$this->remoteAdvisory('GHSA-live-2222-2222', 'acme/reordered', 'CVE-2024-50005')],
            ['acme/reordered' => ['GHSA-stale-3333-3333' => true]],
        );

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/reordered');

        $this->assertCount(2, $advisories);
        $this->assertSame('CVE-2024-50005', $this->activeAdvisory($advisories)->getCve());
    }

    /**
     * The withdrawn advisory keeps its CVE and the source publishes a brand new advisory carrying
     * that same CVE in the same run. removeWithdrawn() leaves the withdrawn advisory out of the list
     * handed to planResolve(), so the replacement is persisted as a new entity, and Doctrine commits
     * inserts before updates - the withdrawal must be flushed first for the insert to fit.
     */
    public function testWithdrawnAdvisoryFreesCveForAReplacementInsertedInTheSameRun(): void
    {
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-gone-4444-4444', 'acme/replaced', 'CVE-2024-60006'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($withdrawn);

        $collection = new RemoteSecurityAdvisoryCollection(
            [$this->remoteAdvisory('GHSA-fresh-5555-5555', 'acme/replaced', 'CVE-2024-60006')],
            ['acme/replaced' => ['GHSA-gone-4444-4444' => true]],
        );

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/replaced');

        $this->assertCount(2, $advisories);
        $this->assertSame('GHSA-fresh-5555-5555', $this->activeAdvisory($advisories)->getRemoteId());
    }

    /**
     * The CVE is reassigned to a still-listed advisory, while the advisory that used to hold it is
     * simply absent from the source response (no withdrawn-map entry). The withdrawal UPDATE and the
     * reassignment UPDATE would land in one flush, and Doctrine runs them in entity order, so both
     * orders are stored here: whichever way round it resolves them, one of the two packages puts the
     * reassignment first and violates package_name_active_cve_idx unless the withdrawal is committed first.
     */
    public function testCveReassignedFromAnAdvisoryTheSourceStoppedListing(): void
    {
        $survivorFirst = new SecurityAdvisory($this->remoteAdvisory('GHSA-survivor-a0', 'acme/dropped-first', 'CVE-2024-70007'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $droppedFirst = new SecurityAdvisory($this->remoteAdvisory('GHSA-dropped-a1', 'acme/dropped-first', 'CVE-2024-80008'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $droppedSecond = new SecurityAdvisory($this->remoteAdvisory('GHSA-dropped-b0', 'acme/dropped-second', 'CVE-2024-80009'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $survivorSecond = new SecurityAdvisory($this->remoteAdvisory('GHSA-survivor-b1', 'acme/dropped-second', 'CVE-2024-70010'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->store($survivorFirst, $droppedFirst, $droppedSecond, $survivorSecond);

        // No withdrawn map entries: the dropped advisories are just absent from the source's response.
        $collection = new RemoteSecurityAdvisoryCollection([
            $this->remoteAdvisory('GHSA-survivor-a0', 'acme/dropped-first', 'CVE-2024-80008'),
            $this->remoteAdvisory('GHSA-survivor-b1', 'acme/dropped-second', 'CVE-2024-80009'),
        ]);

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $repository = $this->getEM()->getRepository(SecurityAdvisory::class);

        foreach ([
            'acme/dropped-first' => ['GHSA-survivor-a0', 'CVE-2024-80008'],
            'acme/dropped-second' => ['GHSA-survivor-b1', 'CVE-2024-80009'],
        ] as $packageName => [$remoteId, $cve]) {
            $advisories = $repository->findByPackageName($packageName);

            $this->assertCount(2, $advisories);
            $this->assertSame($cve, $this->activeAdvisory($advisories)->getCve());
            $this->assertSame($remoteId, $this->activeAdvisory($advisories)->getRemoteId());
        }
    }

    public function testSourceWithdrawnByOneOfTwoSourcesIsKept(): void
    {
        $advisory = new SecurityAdvisory($this->remoteAdvisory('GHSA-shared-0000', 'acme/two-sources', 'CVE-2024-12001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->addSource('shared/2024/12001', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null);
        $this->store($advisory);

        $this->runWorkerWithSource(new RemoteSecurityAdvisoryCollection([], ['acme/two-sources' => ['GHSA-shared-0000' => true]]));

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/two-sources');

        $this->assertCount(1, $advisories);
        $this->assertFalse($advisories[0]->isWithdrawn());
        $this->assertCount(2, $advisories[0]->getSources());
        $this->assertSame('GHSA-shared-0000', $advisories[0]->getSourceRemoteId(GitHubSecurityAdvisoriesSource::SOURCE_NAME));
        $this->assertTrue($advisories[0]->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
        $this->assertFalse($advisories[0]->findSecurityAdvisorySource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
    }

    /**
     * Two advisories swap a CVE while the one receiving it is withdrawn: the advisory holding the
     * CVE moves to a different one and the withdrawn advisory is reported as live again in the same
     * run. Both orderings are exercised, because within a single flush Doctrine is free to run the
     * revival UPDATE before the one freeing the key.
     */
    public function testCveHandedBackToAWithdrawnAdvisoryTheSourceListsAgain(): void
    {
        $reinstatedFirst = new SecurityAdvisory($this->remoteAdvisory('GHSA-a1-back-0000', 'acme/swap-first', 'CVE-2024-90001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $reinstatedFirst->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $holderFirst = new SecurityAdvisory($this->remoteAdvisory('GHSA-b1-hold-1111', 'acme/swap-first', 'CVE-2024-90001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $holderSecond = new SecurityAdvisory($this->remoteAdvisory('GHSA-a2-hold-2222', 'acme/swap-second', 'CVE-2024-90003'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $reinstatedSecond = new SecurityAdvisory($this->remoteAdvisory('GHSA-b2-back-3333', 'acme/swap-second', 'CVE-2024-90003'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $reinstatedSecond->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->store($reinstatedFirst, $holderFirst, $holderSecond, $reinstatedSecond);

        $collection = new RemoteSecurityAdvisoryCollection([
            $this->remoteAdvisory('GHSA-b1-hold-1111', 'acme/swap-first', 'CVE-2024-90002'),
            $this->remoteAdvisory('GHSA-a1-back-0000', 'acme/swap-first', 'CVE-2024-90001'),
            $this->remoteAdvisory('GHSA-a2-hold-2222', 'acme/swap-second', 'CVE-2024-90004'),
            $this->remoteAdvisory('GHSA-b2-back-3333', 'acme/swap-second', 'CVE-2024-90003'),
        ]);

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $repository = $this->getEM()->getRepository(SecurityAdvisory::class);

        foreach (['acme/swap-first' => 'CVE-2024-90001', 'acme/swap-second' => 'CVE-2024-90003'] as $packageName => $reinstatedCve) {
            $advisories = $repository->findByPackageName($packageName);

            $this->assertCount(2, $advisories);
            foreach ($advisories as $advisory) {
                $this->assertFalse($advisory->isWithdrawn(), $advisory->getRemoteId().' must have been reinstated');
            }
            $this->assertContains($reinstatedCve, array_map(static fn (SecurityAdvisory $a) => $a->getCve(), $advisories));
        }
    }

    /**
     * A brand new advisory claims the CVE that an advisory the source still lists is giving up in
     * the same run. Doctrine runs every INSERT of a flush ahead of the UPDATEs, so both rows would
     * carry the CVE at the same time if the update were not committed first.
     */
    public function testNewAdvisoryTakesOverACveFromAnAdvisoryUpdatedInTheSameRun(): void
    {
        $existing = new SecurityAdvisory($this->remoteAdvisory('GHSA-holder-0000', 'acme/handover', 'CVE-2024-11001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($existing);

        $collection = new RemoteSecurityAdvisoryCollection([
            $this->remoteAdvisory('GHSA-holder-0000', 'acme/handover', 'CVE-2024-11002'),
            $this->remoteAdvisory('GHSA-newcomer-1111', 'acme/handover', 'CVE-2024-11001'),
        ]);

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/handover');

        $this->assertCount(2, $advisories);
        $byRemoteId = [];
        foreach ($advisories as $advisory) {
            $this->assertFalse($advisory->isWithdrawn());
            $byRemoteId[$advisory->getRemoteId()] = $advisory->getCve();
        }

        $this->assertSame('CVE-2024-11002', $byRemoteId['GHSA-holder-0000']);
        $this->assertSame('CVE-2024-11001', $byRemoteId['GHSA-newcomer-1111']);
    }

    /**
     * The source lists a withdrawn advisory again while another active advisory still owns its CVE.
     * It cannot be revived without breaking package_name_active_cve_idx, so both its own flag and its
     * source's stay set rather than drifting apart.
     */
    public function testAdvisoryHeldBackByACveClashKeepsItsSourceWithdrawn(): void
    {
        $active = new SecurityAdvisory($this->remoteAdvisory('GHSA-owner-0000', 'acme/clash', 'CVE-2024-13001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $heldBack = new SecurityAdvisory($this->remoteAdvisory('GHSA-held-1111', 'acme/clash', 'CVE-2024-13001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $heldBack->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($active, $heldBack);

        $collection = new RemoteSecurityAdvisoryCollection([
            $this->remoteAdvisory('GHSA-owner-0000', 'acme/clash', 'CVE-2024-13001'),
            $this->remoteAdvisory('GHSA-held-1111', 'acme/clash', 'CVE-2024-13001'),
        ]);

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/clash');

        $this->assertCount(2, $advisories);
        $withdrawnAdvisories = array_values(array_filter($advisories, static fn (SecurityAdvisory $a) => $a->isWithdrawn()));
        $this->assertCount(1, $withdrawnAdvisories);
        $this->assertSame('GHSA-held-1111', $withdrawnAdvisories[0]->getRemoteId());
        $this->assertFalse($withdrawnAdvisories[0]->hasActiveSources());
    }

    public function testAdvisoryHeldBackByACveClashIsRevivedOnALaterRun(): void
    {
        $active = new SecurityAdvisory($this->remoteAdvisory('GHSA-owner-0000', 'acme/recover', 'CVE-2024-14001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $heldBack = new SecurityAdvisory($this->remoteAdvisory('GHSA-held-1111', 'acme/recover', 'CVE-2024-14001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $heldBack->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($active, $heldBack);

        $this->runWorkerWithSource(new RemoteSecurityAdvisoryCollection([
            $this->remoteAdvisory('GHSA-owner-0000', 'acme/recover', 'CVE-2024-14001'),
            $this->remoteAdvisory('GHSA-held-1111', 'acme/recover', 'CVE-2024-14001'),
        ]));

        // The source stops listing the advisory that owned the CVE.
        $this->runWorkerWithSource(new RemoteSecurityAdvisoryCollection([
            $this->remoteAdvisory('GHSA-held-1111', 'acme/recover', 'CVE-2024-14001'),
        ]));

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/recover');

        $revived = $this->activeAdvisory($advisories);
        $this->assertSame('GHSA-held-1111', $revived->getRemoteId());
        $this->assertSame('CVE-2024-14001', $revived->getCve());
        $this->assertFalse($revived->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
    }

    public function testWithdrawnSourceIsReinstatedOnAnAdvisoryThatStayedActive(): void
    {
        $advisory = new SecurityAdvisory($this->remoteAdvisory('GHSA-back-0000', 'acme/returning', 'CVE-2024-15001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->addSource('returning/2024/15001', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null);
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($advisory);

        $this->runWorkerWithSource(new RemoteSecurityAdvisoryCollection([
            $this->remoteAdvisory('GHSA-back-0000', 'acme/returning', 'CVE-2024-15001'),
        ]));

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/returning');

        $this->assertCount(1, $advisories);
        $this->assertFalse($advisories[0]->isWithdrawn());
        $this->assertFalse($advisories[0]->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
        $this->assertFalse($advisories[0]->findSecurityAdvisorySource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
    }

    /**
     * A FriendsOfPHP-only advisory holds the CVE. A GitHub run never matches it, but reviving the
     * withdrawn GitHub advisory with the same CVE would still collide with it.
     */
    public function testRevivalRespectsAnAdvisoryOfAnotherSourceHoldingTheCve(): void
    {
        $friendsOfPhp = new SecurityAdvisory($this->remoteAdvisory('acme/cross/CVE-2024-16001.yaml', 'acme/cross', 'CVE-2024-16001', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME), FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);
        $gitHub = new SecurityAdvisory($this->remoteAdvisory('GHSA-cross-0000', 'acme/cross', 'CVE-2024-16001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $gitHub->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($friendsOfPhp, $gitHub);

        $this->runWorkerWithSource(new RemoteSecurityAdvisoryCollection([$this->remoteAdvisory('GHSA-cross-0000', 'acme/cross', 'CVE-2024-16001')]));

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/cross');

        $this->assertCount(2, $advisories);
        $this->assertSame('acme/cross/CVE-2024-16001.yaml', $this->activeAdvisory($advisories)->getRemoteId());
    }

    /**
     * GitHub withdrew GHSA-1 and later publishes GHSA-2 for the same CVE. The fuzzy CVE match reuses
     * the withdrawn advisory: its GHSA-1 row must stay as it is (the id is part of the row's
     * identifier, so a rename would leave later UPDATEs targeting a row that no longer exists) and a
     * GHSA-2 row is added and reinstated. assertWithdrawalFlagsInSync() is what catches the
     * database drifting from the entities here.
     */
    public function testRenamedAdvisoryGetsANewSourceRowInsteadOfARenamedOne(): void
    {
        $advisory = new SecurityAdvisory($this->remoteAdvisory('GHSA-rename-0001', 'acme/renamed', 'CVE-2024-17001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($advisory);

        $this->runWorkerWithSource(new RemoteSecurityAdvisoryCollection([$this->remoteAdvisory('GHSA-rename-0002', 'acme/renamed', 'CVE-2024-17001')]));

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/renamed');

        $this->assertCount(1, $advisories);
        $this->assertFalse($advisories[0]->isWithdrawn());
        $this->assertSame(['GHSA-rename-0001', 'GHSA-rename-0002'], $advisories[0]->getSourceRemoteIds(GitHubSecurityAdvisoriesSource::SOURCE_NAME));
        $this->assertTrue($advisories[0]->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-rename-0001')?->isWithdrawn());
        $this->assertFalse($advisories[0]->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-rename-0002')?->isWithdrawn());
    }

    /**
     * The source moves a CVE from one still-listed advisory to another. Both UPDATEs would land in
     * the same flush in identity-map order, so both orders are stored: one of the two packages has
     * the taker first and violates package_name_active_cve_idx unless its CVE is held back until
     * the release is committed.
     */
    public function testCveHandedBetweenTwoListedAdvisoriesInTheSameRun(): void
    {
        $releaserFirst = new SecurityAdvisory($this->remoteAdvisory('GHSA-rel-a0', 'acme/handover-first', 'CVE-2024-18001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $takerFirst = new SecurityAdvisory($this->remoteAdvisory('GHSA-take-a1', 'acme/handover-first', 'CVE-2024-18002'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $takerSecond = new SecurityAdvisory($this->remoteAdvisory('GHSA-take-b0', 'acme/handover-second', 'CVE-2024-18004'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $releaserSecond = new SecurityAdvisory($this->remoteAdvisory('GHSA-rel-b1', 'acme/handover-second', 'CVE-2024-18003'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($releaserFirst, $takerFirst, $takerSecond, $releaserSecond);

        $this->runWorkerWithSource(new RemoteSecurityAdvisoryCollection([
            $this->remoteAdvisory('GHSA-rel-a0', 'acme/handover-first', 'CVE-2024-18009'),
            $this->remoteAdvisory('GHSA-take-a1', 'acme/handover-first', 'CVE-2024-18001'),
            $this->remoteAdvisory('GHSA-rel-b1', 'acme/handover-second', 'CVE-2024-18009'),
            $this->remoteAdvisory('GHSA-take-b0', 'acme/handover-second', 'CVE-2024-18003'),
        ]));

        $this->getEM()->clear();
        $repository = $this->getEM()->getRepository(SecurityAdvisory::class);

        foreach ([
            'acme/handover-first' => ['GHSA-take-a1' => 'CVE-2024-18001', 'GHSA-rel-a0' => 'CVE-2024-18009'],
            'acme/handover-second' => ['GHSA-take-b0' => 'CVE-2024-18003', 'GHSA-rel-b1' => 'CVE-2024-18009'],
        ] as $packageName => $expected) {
            $byRemoteId = [];
            foreach ($repository->findByPackageName($packageName) as $advisory) {
                $this->assertFalse($advisory->isWithdrawn());
                $byRemoteId[$advisory->getRemoteId()] = $advisory->getCve();
            }
            ksort($byRemoteId);
            ksort($expected);
            $this->assertSame($expected, $byRemoteId);
        }
    }

    /**
     * @param SecurityAdvisory[] $advisories
     */
    private function activeAdvisory(array $advisories): SecurityAdvisory
    {
        $active = array_values(array_filter($advisories, static fn (SecurityAdvisory $a) => !$a->isWithdrawn()));
        $this->assertCount(1, $active);

        return $active[0];
    }

    private function runWorkerWithSource(RemoteSecurityAdvisoryCollection $collection): void
    {
        $doctrine = static::getContainer()->get(ManagerRegistry::class);
        \assert($doctrine instanceof ManagerRegistry);

        $source = new class($collection) implements SecurityAdvisorySourceInterface {
            public function __construct(private readonly RemoteSecurityAdvisoryCollection $collection)
            {
            }

            public function getAdvisories(ConsoleIO $io): ?RemoteSecurityAdvisoryCollection
            {
                return $this->collection;
            }
        };

        $worker = new SecurityAdvisoryWorker(
            new Locker($doctrine),
            new NullLogger(),
            $doctrine,
            [GitHubSecurityAdvisoriesSource::SOURCE_NAME => $source],
            new SecurityAdvisoryResolver(),
            new SecurityAdvisoryUpdateListener($doctrine, $this->createStub(Client::class)),
        );

        $job = new Job('advisory-job', 'security:advisory', ['source' => GitHubSecurityAdvisoriesSource::SOURCE_NAME]);
        $result = $worker->process($job, SignalHandler::create());

        $this->assertSame(Job::STATUS_COMPLETED, $result['status']);
        $this->assertWithdrawalFlagsInSync();
    }

    /**
     * withdrawnAt lives on both tables: on the advisory because the activeCve generated column
     * backing package_name_active_cve_idx can only read its own row, and on each source so a withdrawal by
     * one of several sources is not lost. They must never disagree.
     */
    private function assertWithdrawalFlagsInSync(): void
    {
        $rows = $this->getEM()->getConnection()->fetchAllAssociative(
            'SELECT a.packagistAdvisoryId, a.withdrawnAt, COUNT(s.securityAdvisory_id) AS listingSources
                FROM security_advisory a
                LEFT JOIN security_advisory_source s ON s.securityAdvisory_id = a.id AND s.withdrawnAt IS NULL
                GROUP BY a.id, a.packagistAdvisoryId, a.withdrawnAt'
        );

        foreach ($rows as $row) {
            $this->assertSame(
                null !== $row['withdrawnAt'],
                0 === (int) $row['listingSources'],
                $row['packagistAdvisoryId'].' must be withdrawn exactly when no source lists it any more'
            );
        }
    }

    private function remoteAdvisory(string $remoteId, string $packageName, string $cve, string $source = GitHubSecurityAdvisoriesSource::SOURCE_NAME): RemoteSecurityAdvisory
    {
        return new RemoteSecurityAdvisory(
            $remoteId,
            'Advisory '.$cve,
            $packageName,
            '^1.0',
            'https://example.org/'.$remoteId,
            $cve,
            new \DateTimeImmutable('2024-01-01 00:00:00'),
            null,
            [],
            $source,
            null,
        );
    }
}

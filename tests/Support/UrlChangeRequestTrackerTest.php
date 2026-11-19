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

namespace App\Tests\Support;

use App\Entity\PackageFreezeReason;
use App\Entity\SupportRequest;
use App\Entity\User;
use App\Support\Attributes\PackageUrlChange;
use App\Support\Attributes\PackageUrlChangeAttributes;
use App\Support\SupportRequestStatus;
use App\Support\UrlChangeRequestTracker;
use App\Tests\IntegrationTestCase;

/**
 * The edit form's save needs a reachable https repository, so the tracker it calls is tested here
 * with packages that already carry the new URL, as they do once the save has flushed.
 */
class UrlChangeRequestTrackerTest extends IntegrationTestCase
{
    private const string OLD_URL = 'https://example.org/mover/thing';
    private const string NEW_URL = 'https://example.org/moved/thing';

    public function testLastAppliedChangeResolvesTheRequestAndTellsTheRequester(): void
    {
        [$admin, $request] = $this->givenRequest(['mover/thing' => self::NEW_URL]);
        $package = self::createPackage('mover/thing', self::NEW_URL);
        $this->store($package);

        $this->tracker()->noteAppliedChange($package, self::OLD_URL, $request->publicId, $admin);

        $reloaded = $this->reload($request);
        self::assertSame(SupportRequestStatus::Resolved, $reloaded->status);
        self::assertCount(2, $reloaded->messages, 'an internal note for the change, then the reply');

        $this->assertEmailCount(1);
        $mail = $this->getMailerMessage();
        self::assertNotNull($mail);
        $this->assertEmailAddressContains($mail, 'To', 'mover@example.org');
        $this->assertEmailTextBodyContains($mail, 'mover/thing: '.self::NEW_URL);
    }

    public function testRequestStaysOpenWhileAnotherPackageHasNotMoved(): void
    {
        [$admin, $request] = $this->givenRequest(['mover/thing' => self::NEW_URL, 'mover/other' => 'https://example.org/moved/other']);
        $package = self::createPackage('mover/thing', self::NEW_URL);
        $this->store($package, self::createPackage('mover/other', 'https://example.org/mover/other'));

        $this->tracker()->noteAppliedChange($package, self::OLD_URL, $request->publicId, $admin);

        $reloaded = $this->reload($request);
        self::assertSame(SupportRequestStatus::Open, $reloaded->status);
        self::assertCount(1, $reloaded->messages);
        $this->assertEmailCount(0);
    }

    public function testRequestStaysOpenWhileThePackageIsFrozen(): void
    {
        [$admin, $request] = $this->givenRequest(['mover/thing' => self::NEW_URL]);
        $package = self::createPackage('mover/thing', self::NEW_URL);
        $package->freeze(PackageFreezeReason::Gone);
        $this->store($package);

        $this->tracker()->noteAppliedChange($package, self::OLD_URL, $request->publicId, $admin);

        self::assertSame(SupportRequestStatus::Open, $this->reload($request)->status);
        $this->assertEmailCount(0);
    }

    public function testUnrelatedSavesLeaveTheRequestAlone(): void
    {
        [$admin, $request] = $this->givenRequest(['mover/thing' => self::NEW_URL]);
        $package = self::createPackage('mover/thing', self::NEW_URL);
        $unrelated = self::createPackage('mover/unrelated', self::NEW_URL);
        $this->store($package, $unrelated);

        // no reference, URL not actually changed, package not in the request, unknown reference
        $this->tracker()->noteAppliedChange($package, self::OLD_URL, '', $admin);
        $this->tracker()->noteAppliedChange($package, self::NEW_URL, $request->publicId, $admin);
        $this->tracker()->noteAppliedChange($unrelated, self::OLD_URL, $request->publicId, $admin);
        $this->tracker()->noteAppliedChange($package, self::OLD_URL, 'PKSR-0000-0000-0000', $admin);

        $reloaded = $this->reload($request);
        self::assertSame(SupportRequestStatus::Open, $reloaded->status);
        self::assertCount(0, $reloaded->messages);
        $this->assertEmailCount(0);
    }

    public function testClosedRequestIsLeftAlone(): void
    {
        [$admin, $request] = $this->givenRequest(['mover/thing' => self::NEW_URL]);
        $request->close(new \DateTimeImmutable());
        $package = self::createPackage('mover/thing', self::NEW_URL);
        $this->store($request, $package);

        $this->tracker()->noteAppliedChange($package, self::OLD_URL, $request->publicId, $admin);

        $reloaded = $this->reload($request);
        self::assertSame(SupportRequestStatus::Closed, $reloaded->status);
        self::assertCount(0, $reloaded->messages);
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array{User, SupportRequest}
     */
    private function givenRequest(array $changes): array
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $requester = self::createUser('mover', 'mover@example.org', githubId: '23456');
        $list = [];
        foreach ($changes as $name => $url) {
            $list[] = new PackageUrlChange($name, $url);
        }
        $request = SupportRequest::create($requester, 'It moved.', new PackageUrlChangeAttributes($list));
        $this->store($admin, $requester, $request);

        return [$admin, $request];
    }

    private function tracker(): UrlChangeRequestTracker
    {
        return self::getService(UrlChangeRequestTracker::class);
    }

    private function reload(SupportRequest $request): SupportRequest
    {
        $em = self::getEM();
        $em->clear();
        $reloaded = $em->getRepository(SupportRequest::class)->findOneBy(['publicId' => $request->publicId]);
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}

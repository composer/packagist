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

namespace App\Tests\Controller;

use App\Audit\VersionDeletionReason;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\SecurityAdvisory;
use App\Entity\Version;
use App\Log\AuditLogEventType;
use App\Model\VersionIdCache;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\Severity;
use App\Service\Scheduler;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiControllerTest extends IntegrationTestCase
{
    public function testGithubFailsOnGet(): void
    {
        $this->client->request('GET', '/api/github');
        $this->assertEquals(Response::HTTP_METHOD_NOT_ALLOWED, $this->client->getResponse()->getStatusCode(), 'GET method should not be allowed for GitHub Post-Receive URL');
    }

    public function testGitHubFailsWithInvalidCredentials(): void
    {
        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $this->client->request('POST', '/api/github?username=INVALID_USER&apiToken=INVALID_TOKEN', ['payload' => $payload]);
        $this->assertEquals(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid username and API Token are sent: '.$this->client->getResponse()->getContent());
    }

    #[DataProvider('githubApiProvider')]
    public function testGithubApi($url): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), $url, maintainers: [$user]);
        $this->store($user, $package);

        $scheduler = $this->createMock(Scheduler::class);

        $scheduler->expects($this->once())
            ->method('scheduleUpdate')
            ->with($package);

        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $this->client->request('POST', '/api/github?username=test&apiToken=api-token', ['payload' => $payload]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public static function githubApiProvider(): array
    {
        return [
            ['https://github.com/composer/composer.git'],
            ['http://github.com/composer/composer.git'],
            ['http://github.com/composer/composer'],
            ['git@github.com:composer/composer.git'],
        ];
    }

    public function testGithubHookRejectsMalformedSignature(): void
    {
        $payload = json_encode(['repository' => ['url' => 'https://github.com/composer/composer']]);
        $this->client->request(
            'POST',
            '/api/github',
            [],
            [],
            ['HTTP_X-Hub-Signature-256' => 'not-an-algo=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );
        $this->assertEquals(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testGithubHookIgnoresEventsThatCannotMoveARef(): void
    {
        // A user webhook set to "send me everything" turns every workflow run, check and comment into
        // a crawl: one package saw 196 of them in seven hours without a ref moving. Only a push can.
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/composer/composer', maintainers: [$user]);
        $this->store($user, $package);

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->never())->method('scheduleUpdate');
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $this->client->request('POST', '/api/github?username=test&apiToken=api-token', ['payload' => $payload], [], ['HTTP_X-GitHub-Event' => 'check_run']);
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    #[TestWith(['push'])]
    #[TestWith(['release'])]
    #[TestWith(['ping'])]
    public function testGithubHookSchedulesOnRefEvents(string $event): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/composer/composer', maintainers: [$user]);
        $this->store($user, $package);

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())->method('scheduleUpdate')->with($package);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $this->client->request('POST', '/api/github?username=test&apiToken=api-token', ['payload' => $payload], [], ['HTTP_X-GitHub-Event' => $event]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testGithubHookAcceptsOrgPingWithoutRepository(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->never())->method('scheduleUpdate');
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $payload = json_encode(['zen' => 'Keep it logically awesome.', 'organization' => ['login' => 'acme']]);
        $this->client->request('POST', '/api/github', ['payload' => $payload], [], ['HTTP_X-GitHub-Event' => 'ping']);
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testGithubHookSkipsFrozenPackages(): void
    {
        // The updater bails on a frozen package, but only after getDriver() has hit the GitHub API,
        // and a spam package with a hook can push every minute. Nothing in the job is worth paying for.
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/composer/composer', maintainers: [$user]);
        $package->freeze(PackageFreezeReason::Spam);
        $this->store($user, $package);

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->never())->method('scheduleUpdate');
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $this->client->request('POST', '/api/github?username=test&apiToken=api-token', ['payload' => $payload]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([], $body['jobs']);
        $this->assertSame([$package->getName()], $body['skipped']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[TestWith(['push', ['ref' => 'refs/heads/feature/foo', 'deleted' => true], 'feature/foo', 'dev-feature/foo', 'dev-feature/foo'])]
    #[TestWith(['push', ['ref' => 'refs/heads/2.x', 'deleted' => true], '2.x', '2.x-dev', '2.9999999.9999999.9999999-dev'])]
    #[TestWith(['push', ['ref' => 'refs/heads/v1.5', 'deleted' => true], 'v1.5', 'v1.5.x-dev', '1.5.9999999.9999999-dev'])]
    #[TestWith(['delete', ['ref' => 'feature/foo', 'ref_type' => 'branch'], 'feature/foo', 'dev-feature/foo', 'dev-feature/foo'])]
    public function testGithubHookBranchDeletionRemovesTheDevVersionWithoutACrawl(string $event, array $payload, string $branch, string $version, string $normalizedVersion): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/acme/widget', maintainers: [$user]);
        $package->setDumpedAtV2(new \DateTimeImmutable('-1 hour'));
        $devVersion = self::createDevVersion($package, $version, $normalizedVersion);
        $this->store($user, $package, $devVersion);
        $versionId = $devVersion->getId();

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->never())->method('scheduleUpdate');
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $this->sendGitHubEvent($event, $payload);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([], $body['jobs']);
        $this->assertSame(['branch' => $branch, 'packages' => [$package->getName() => 'removed']], $body['branchDeletion']);

        $em = self::getEM();
        $em->clear();
        $this->assertNull($em->getRepository(Version::class)->find($versionId), 'Version should be hard-deleted');
        $this->assertTrue($em->getRepository(Package::class)->find($package->getId())->isDumpRequested(), 'Package should be marked for dump');
        $this->assertNotNull($em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::VersionDeleted->value, 'packageId' => $package->getId()]), 'Deletion should be audited');
    }

    public function testGithubHookBranchDeletionPurgesAnAutoSoftDeletedVersionWithoutRedumping(): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/acme/widget', maintainers: [$user]);
        $package->setDumpedAtV2(new \DateTimeImmutable('-1 hour'));
        $devVersion = self::createDevVersion($package, 'dev-feature', 'dev-feature');
        $devVersion->setSoftDeletedAt(new \DateTimeImmutable('-2 hours'));
        $devVersion->setDeletionReason(VersionDeletionReason::AutoDeletedMissing);
        $this->store($user, $package, $devVersion);
        $versionId = $devVersion->getId();

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->never())->method('scheduleUpdate');
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $this->sendGitHubEvent('push', ['ref' => 'refs/heads/feature', 'deleted' => true]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([$package->getName() => 'removed'], $body['branchDeletion']['packages']);

        $em = self::getEM();
        $em->clear();
        $this->assertNull($em->getRepository(Version::class)->find($versionId), 'Version should be hard-deleted');
        $this->assertFalse($em->getRepository(Package::class)->find($package->getId())->isDumpRequested(), 'A soft-deleted version was already out of the dump');
    }

    #[TestWith([VersionDeletionReason::DeletedByMaintainer])]
    #[TestWith([VersionDeletionReason::DeletedByAdmin])]
    #[TestWith([VersionDeletionReason::Hidden])]
    public function testGithubHookBranchDeletionLeavesPulledVersionsAlone(VersionDeletionReason $reason): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/acme/widget', maintainers: [$user]);
        $devVersion = self::createDevVersion($package, 'dev-feature', 'dev-feature');
        $devVersion->setSoftDeletedAt(new \DateTimeImmutable('-2 hours'));
        $devVersion->setDeletionReason($reason);
        $this->store($user, $package, $devVersion);
        $versionId = $devVersion->getId();

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->never())->method('scheduleUpdate');
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $this->sendGitHubEvent('push', ['ref' => 'refs/heads/feature', 'deleted' => true]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([$package->getName() => 'nothing_to_remove'], $body['branchDeletion']['packages']);

        self::getEM()->clear();
        $this->assertNotNull(self::getEM()->getRepository(Version::class)->find($versionId), 'A pulled version must stay as the record of the takedown');
    }

    public function testGithubHookBranchDeletionWithoutAVersionSchedulesNothing(): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/acme/widget', maintainers: [$user]);
        $this->store($user, $package);

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->never())->method('scheduleUpdate');
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $this->sendGitHubEvent('push', ['ref' => 'refs/heads/dependabot/composer/foo-1.2.3', 'deleted' => true]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame([], $body['jobs']);
        $this->assertSame([$package->getName() => 'nothing_to_remove'], $body['branchDeletion']['packages']);
    }

    public function testGithubHookBranchDeletionSchedulesAnUpdateWhileThePackageIsLocked(): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/acme/widget', maintainers: [$user]);
        $devVersion = self::createDevVersion($package, 'dev-feature', 'dev-feature');
        $this->store($user, $package, $devVersion);
        $versionId = $devVersion->getId();

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())->method('scheduleUpdate')->with($package);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        // an updater holds the package lock from its own connection while it crawls
        $updater = DriverManager::getConnection(self::getEM()->getConnection()->getParams());
        try {
            $this->assertSame(1, (int) $updater->fetchOne('SELECT GET_LOCK(?, 0)', ['package_update_'.$package->getId()]));
            $this->sendGitHubEvent('push', ['ref' => 'refs/heads/feature', 'deleted' => true]);
        } finally {
            $updater->close();
        }

        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('branchDeletion', $body);

        self::getEM()->clear();
        $this->assertNotNull(self::getEM()->getRepository(Version::class)->find($versionId), 'The running update owns the version');
    }

    public function testGithubHookDefaultBranchDeletionSchedulesAnUpdate(): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/acme/widget', maintainers: [$user]);
        $devVersion = self::createDevVersion($package, 'dev-main', 'dev-main');
        $devVersion->setIsDefaultBranch(true);
        $this->store($user, $package, $devVersion);
        $versionId = $devVersion->getId();

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())->method('scheduleUpdate')->with($package);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $this->sendGitHubEvent('push', ['ref' => 'refs/heads/main', 'deleted' => true]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('branchDeletion', $body);

        self::getEM()->clear();
        $this->assertNotNull(self::getEM()->getRepository(Version::class)->find($versionId), 'Dependents and readme derive from the default branch, so the crawl must sort it out');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[TestWith(['push', ['ref' => 'refs/tags/v1.0.0', 'deleted' => true]])]
    #[TestWith(['delete', ['ref' => 'v1.0.0', 'ref_type' => 'tag']])]
    #[TestWith(['push', ['ref' => 'refs/heads/feature', 'deleted' => false, 'created' => true]])]
    #[TestWith(['push', ['ref' => 'refs/heads/feature', 'deleted' => false]])]
    public function testGithubHookPushesThatDoNotDeleteABranchScheduleAnUpdate(string $event, array $payload): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), 'https://github.com/acme/widget', maintainers: [$user]);
        $devVersion = self::createDevVersion($package, 'dev-feature', 'dev-feature');
        $this->store($user, $package, $devVersion);
        $versionId = $devVersion->getId();

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())->method('scheduleUpdate')->with($package);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', self::getEM());
        static::$kernel->getContainer()->set(Scheduler::class, $scheduler);

        $this->sendGitHubEvent($event, $payload);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('branchDeletion', $body);

        self::getEM()->clear();
        $this->assertNotNull(self::getEM()->getRepository(Version::class)->find($versionId));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendGitHubEvent(string $event, array $payload): void
    {
        $payload += ['repository' => ['url' => 'https://github.com/acme/widget']];
        $this->client->request('POST', '/api/github?username=test&apiToken=api-token', ['payload' => json_encode($payload, \JSON_THROW_ON_ERROR)], [], ['HTTP_X-GitHub-Event' => $event]);
    }

    private static function createDevVersion(Package $package, string $version, string $normalizedVersion): Version
    {
        $devVersion = new Version();
        $devVersion->setPackage($package);
        $devVersion->setName($package->getName());
        $devVersion->setVersion($version);
        $devVersion->setNormalizedVersion($normalizedVersion);
        $devVersion->setDevelopment(true);
        $devVersion->setLicense([]);
        $devVersion->setAutoload([]);
        $package->getVersions()->add($devVersion);

        return $devVersion;
    }

    public function testUnsafeApiRejectsSafeApiToken(): void
    {
        $user = self::createUser();
        $this->store($user);

        $payload = json_encode(['repository' => 'https://github.com/composer/composer']);
        $this->client->request('POST', '/api/create-package?username=test&apiToken=safe-api-token', ['payload' => $payload]);
        $this->assertEquals(Response::HTTP_NOT_ACCEPTABLE, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertEquals(json_encode(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request']), $this->client->getResponse()->getContent());
    }

    public function testSafeApiAcceptsBothApiTokens(): void
    {
        $url = 'https://github.com/composer/composer';
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), $url, maintainers: [$user]);
        $this->store($user, $package);

        $payload = json_encode(['repository' => $url]);
        $this->client->request('POST', '/api/update-package?username=test&apiToken=safe-api-token', ['payload' => $payload]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $payload = json_encode(['repository' => 'https://packagist.org/packages/'.$package->getName()]);
        $this->client->request('POST', '/api/update-package?username=test&apiToken=api-token', ['payload' => $payload]);
        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    #[Depends('testGitHubFailsWithInvalidCredentials')]
    #[DataProvider('urlProvider')]
    public function testUrlDetection($endpoint, $url, $expectedOK): void
    {
        if ($endpoint == 'bitbucket') {
            $canonUrl = substr($url, 0, 1);
            $absUrl = substr($url, 1);
            $payload = json_encode(['canon_url' => $canonUrl, 'repository' => ['absolute_url' => $absUrl]]);
        } else {
            $payload = json_encode(['repository' => $url]);
        }

        $this->client->request('POST', '/api/'.$endpoint.'?username=INVALID_USER&apiToken=INVALID_TOKEN', ['payload' => $payload]);

        $status = $this->client->getResponse()->getStatusCode();

        if (!$expectedOK) {
            $this->assertEquals(406, $status, 'POST method should return 406 "Not Acceptable" if an unknown URL was sent');
        } else {
            $this->assertEquals(403, $status, 'POST method should return 403 "Forbidden" for a valid URL with bad credentials.');
        }
    }

    public static function urlProvider(): array
    {
        return [
            // valid github URLs
            ['github', 'github.com/user/repo', true],
            ['github', 'github.com/user/repo.git', true],
            ['github', 'http://github.com/user/repo', true],
            ['github', 'https://github.com/user/repo', true],
            ['github', 'https://github.com/user/repo.git', true],
            ['github', 'git://github.com/user/repo', true],
            ['github', 'git://github.com/User/Repo.git', true],
            ['github', 'git@github.com:user/repo.git', true],
            ['github', 'git@github.com:user/repo', true],
            ['github', 'https://github.com/user/repo/', true],
            ['github', 'https://github.com/user/', true], // not strictly valid but marked as valid due to support for https://example.org/some-repo.git

            // valid bitbucket URLs
            ['bitbucket', 'bitbucket.org/user/repo', true],
            ['bitbucket', 'http://bitbucket.org/user/repo', true],
            ['bitbucket', 'https://bitbucket.org/user/repo', true],

            // valid others
            ['update-package', 'https://ghe.example.org/user/repository', true],
            ['update-package', 'https://example.org/some-repo.git', true],
            ['update-package', 'https://gitlab.org/user/repository', true],
            ['update-package', 'https://gitlab.org/user/sub/group/lala/repository', true],
            ['update-package', 'ssh://git@stash.xxxxx.com/uuuuu/qqqqq.git', true],
            ['update-package', 'ssh://git@stash.xxxxx.com:2222/uuuuu/qqqqq.git', true],
            ['update-package', 'ssh://git@stash.zzzzz.com/kkkkk.git', true],

            // invalid URLs
            ['github', 'php://github.com/user/repository', false],
            ['github', 'javascript://github.com/user/repository', false],
            ['github', 'http://', false],
            ['github', 'https://github.com/', false],
            ['github', 'https://github.com', false],
            ['update-package', 'ssh://ghe.example.org/user/jjjjj.git', false],
        ];
    }

    public function testSecurityAdvisories(): void
    {
        $advisory = new SecurityAdvisory(new RemoteSecurityAdvisory(
            'GHSA-1234-1234-1234',
            'Advisory Title',
            'acme/package',
            '<1.0.1',
            'https://example.org',
            'CVE-12345',
            new \DateTimeImmutable(),
            SecurityAdvisory::PACKAGIST_ORG,
            [],
            GitHubSecurityAdvisoriesSource::SOURCE_NAME,
            Severity::MEDIUM,
        ), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($advisory);

        $this->client->request('GET', '/api/security-advisories/?packages[]=acme/package');
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $content = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('acme/package', $content['advisories']);
        $this->assertCount(1, $content['advisories']['acme/package']);
    }

    public function testBearerTokenAuthentication(): void
    {
        $url = 'https://github.com/composer/composer';
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), $url, maintainers: [$user]);
        $this->store($user, $package);

        $payload = json_encode(['repository' => $url]);

        // Test with Bearer token in format username:apiToken
        $this->client->request(
            'POST',
            '/api/update-package',
            [],
            [],
            ['HTTP_Authorization' => 'Bearer test:api-token', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testBearerTokenAuthenticationWithSafeToken(): void
    {
        $url = 'https://github.com/composer/composer';
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), $url, maintainers: [$user]);
        $this->store($user, $package);

        $payload = json_encode(['repository' => $url]);

        // Test with Bearer token using safe API token for safe endpoint
        $this->client->request(
            'POST',
            '/api/update-package',
            [],
            [],
            ['HTTP_Authorization' => 'Bearer test:safe-api-token', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testBearerTokenAuthenticationInvalidCredentials(): void
    {
        $payload = json_encode(['repository' => 'https://github.com/composer/composer']);

        // Test with invalid Bearer token
        $this->client->request(
            'POST',
            '/api/update-package',
            [],
            [],
            ['HTTP_Authorization' => 'Bearer invalid:invalid-token', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertEquals(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertEquals(json_encode(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request']), $this->client->getResponse()->getContent());
    }

    public function testBearerTokenAuthenticationMalformed(): void
    {
        $payload = json_encode(['repository' => 'https://github.com/composer/composer']);

        // Test with malformed Bearer token (no colon)
        $this->client->request(
            'POST',
            '/api/update-package',
            [],
            [],
            ['HTTP_Authorization' => 'Bearer invalidtoken', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertEquals(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertEquals(json_encode(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request']), $this->client->getResponse()->getContent());
    }

    public function testBearerTokenAuthenticationOverridesQuery(): void
    {
        $url = 'https://github.com/composer/composer';
        $user = self::createUser();
        $package = self::createPackage('test/'.bin2hex(random_bytes(10)), $url, maintainers: [$user]);
        $this->store($user, $package);

        $payload = json_encode(['repository' => $url]);

        // Test that Bearer token is used even when query params are present but invalid
        $this->client->request(
            'POST',
            '/api/update-package?username=invalid&apiToken=invalid',
            [],
            [],
            ['HTTP_Authorization' => 'Bearer test:api-token', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertEquals(Response::HTTP_ACCEPTED, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testComposerVersionsProxiesUpstream(): void
    {
        $body = '{"stable":[{"path":"/download/2.7.1/composer.phar","version":"2.7.1"}]}';
        $mockClient = new MockHttpClient(function (string $method, string $url) use ($body) {
            $this->assertSame('GET', $method);
            $this->assertSame('https://getcomposer.org/versions', $url);

            return new MockResponse($body, ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
        });
        self::getContainer()->set('http_client', $mockClient);

        $this->client->request('GET', '/api/composer-versions');
        $response = $this->client->getResponse();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($body, $response->getContent());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('s-maxage=900', (string) $response->headers->get('Cache-Control'));
    }

    public function testComposerVersionsReturns502OnUpstreamFailure(): void
    {
        $mockClient = new MockHttpClient(new MockResponse('nope', ['http_code' => 500]));
        self::getContainer()->set('http_client', $mockClient);

        $this->client->request('GET', '/api/composer-versions');
        $response = $this->client->getResponse();

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode(), (string) $response->getContent());
        $this->assertJsonStringEqualsJsonString('{"status":"error","message":"Failed to fetch upstream version data, please try again later."}', (string) $response->getContent());
    }

    public function testTrackDownloadsCountsAResolvableVersion(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($package);

        // the endpoint resolves name+version to ids straight out of Redis, never MySQL
        self::getService(VersionIdCache::class)->insertVersionRaw($package->getId(), 'test/pkg', 4242, '1.0.0.0');

        $this->client->request(
            'POST',
            '/downloads/',
            server: ['HTTP_USER_AGENT' => 'Composer/2.8.0 (Linux; 6.1.0; PHP 8.4.0; curl 8.5)'],
            content: json_encode(['downloads' => [['name' => 'test/pkg', 'version' => '1.0.0.0']]], flags: \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['status' => 'success'], $this->decodeResponse());
    }

    public function testTrackDownloadsReportsUnresolvableVersionsAsPartial(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($package);

        // Composer 1 sent the default branch as 9999999-dev. Support for it was shut down on
        // 2025-09-01 and there is no longer a MySQL fallback resolving it via defaultBranch, so an
        // unknown version is reported back like any other.
        $this->client->request(
            'POST',
            '/downloads/',
            server: ['HTTP_USER_AGENT' => 'Composer/1.10.26 (Linux; 6.1.0; PHP 8.4.0; curl 8.5)'],
            content: json_encode(['downloads' => [['name' => 'test/pkg', 'version' => '9999999-dev']]], flags: \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodeResponse();
        self::assertSame('partial', $body['status']);
        self::assertStringContainsString('9999999-dev', $body['message'], 'the unresolved entry is reported back to the client');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}

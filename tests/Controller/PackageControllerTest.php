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

use App\Audit\AuditRecordType;
use App\Audit\VersionDeletionReason;
use App\Entity\Package;
use App\Entity\User;
use App\Entity\Version;
use App\Tests\IntegrationTestCase;
use Composer\Package\Version\VersionParser;
use PHPUnit\Framework\Attributes\TestWith;

class PackageControllerTest extends IntegrationTestCase
{
    public function testView(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg');
        $this->store($package);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertSame('composer require test/pkg', $crawler->filter('.requireme input')->attr('value'));
    }

    public function testEdit(): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$user]);

        $this->store($user, $package);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertSame('example.com/test/pkg', $crawler->filter('.canonical')->text());

        $form = $crawler->selectButton('Edit')->form();
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Update')->form(['form[repository]' => 'https://github.com/composer/composer']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame('github.com/composer/composer', $crawler->filter('.canonical')->text());
    }

    public function testCreateMaintainer(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $newMaintainer = self::createUser('maintainer', 'maintainer@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$owner]);

        $this->store($owner, $newMaintainer, $package);

        $this->client->loginUser($owner);

        $this->assertFalse($package->isMaintainer($newMaintainer));

        $crawler = $this->client->request('GET', '/packages/test/pkg');

        $form = $crawler->filter('[name="add_maintainer_form"]')->form();
        $form->setValues([
            'add_maintainer_form[user]' => 'maintainer',
        ]);

        $this->client->enableProfiler(); // This is required in 7.3.4 to assert emails were sent, see https://github.com/symfony/symfony/issues/61873
        $this->client->submit($form);

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailHeaderSame($email, 'To', $newMaintainer->getEmail());

        $this->assertResponseRedirects('/packages/test/pkg');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $maintainer = $em->getRepository(User::class)->find($newMaintainer->getId());
        $package = $em->getRepository(Package::class)->find($package->getId());

        $this->assertTrue($package->isMaintainer($maintainer));

        $auditRecord = $em->getRepository(\App\Entity\AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::MaintainerAdded->value,
            'packageId' => $package->getId(),
            'actorId' => $owner->getId(),
        ]);
        $this->assertNotNull($auditRecord);
    }

    public function testRemoveMaintainer(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $maintainer = self::createUser('maintainer', 'maintainer@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$owner, $maintainer]);

        $this->store($owner, $maintainer, $package);

        $this->client->loginUser($owner);

        $this->assertTrue($package->isMaintainer($maintainer));

        $crawler = $this->client->request('GET', '/packages/test/pkg');

        $form = $crawler->filter('[name="remove_maintainer_form"]')->form();
        $form->setValues([
            'remove_maintainer_form[user]' => $maintainer->getId(),
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/packages/test/pkg');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $maintainer = $em->getRepository(User::class)->find($maintainer->getId());
        $package = $em->getRepository(Package::class)->find($package->getId());

        $this->assertFalse($package->isMaintainer($maintainer));

        $auditRecord = $em->getRepository(\App\Entity\AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::MaintainerRemoved->value,
            'packageId' => $package->getId(),
            'actorId' => $owner->getId(),
        ]);

        $this->assertNotNull($auditRecord);
    }

    public function testTransferPackage(): void
    {
        $john = self::createUser('john', 'john@example.org');
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$john, $alice]);

        $this->store($john, $alice, $bob, $package);

        $this->client->loginUser($john);

        $this->assertTrue($package->isMaintainer($john));
        $this->assertTrue($package->isMaintainer($alice));
        $this->assertFalse($package->isMaintainer($bob));

        $crawler = $this->client->request('GET', '/packages/test/pkg');

        $form = $crawler->filter('[name="transfer_package_form"]')->form();
        $form->setValues([
            'transfer_package_form[maintainers][0]' => 'alice',
            'transfer_package_form[maintainers][1]' => 'bob',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/packages/test/pkg');

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailHeaderSame($email, 'To', $bob->getEmail());

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package = $em->getRepository(Package::class)->find($package->getId());
        $this->assertNotNull($package);

        $maintainerIds = array_map(fn (User $user) => $user->getId(), $package->getMaintainers()->toArray());
        $this->assertContains($alice->getId(), $maintainerIds);
        $this->assertContains($bob->getId(), $maintainerIds);
        $this->assertNotContains($john->getId(), $maintainerIds);

        $auditRecord = $em->getRepository(\App\Entity\AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::PackageTransferred->value,
            'packageId' => $package->getId(),
        ]);

        $this->assertNotNull($auditRecord, 'Audit record not found');
    }

    #[TestWith(['does_not_exist', 'value is not a valid username'])]
    #[TestWith([null, 'at least one maintainer must be specified'])]
    public function testTransferPackageReturnsValidationError(?string $value, string $message): void
    {
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org', enabled: false);
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$alice]);

        $this->store($alice, $bob, $package);

        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/packages/test/pkg');

        $form = $crawler->filter('[name="transfer_package_form"]')->form();
        $form->setValues([
            'transfer_package_form[maintainers][0]' => $value,
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/packages/test/pkg');
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $elements = $crawler->filter('.flash-container .alert-error');
        $this->assertCount(1, $elements);
        $text = $elements->text();
        $this->assertStringContainsStringIgnoringCase($message, $text);
    }

    #[TestWith([null, null, 200])]
    #[TestWith([null, 'auto_missing', 200])]
    #[TestWith([null, 'maintainer', 200])]
    #[TestWith([null, 'admin', 200])]
    #[TestWith([null, 'hidden', 404])]
    #[TestWith(['maintainer', 'hidden', 200])]
    #[TestWith(['admin', 'hidden', 200])]
    public function testViewPackageVersionRespectsHiddenVisibility(?string $actor, ?string $reason, int $expectedStatus): void
    {
        $maintainer = self::createUser('owner', 'owner@example.org');
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$maintainer]);
        $version = $this->createStableVersion($package, '1.0.0');
        if ($reason !== null) {
            $version->setSoftDeletedAt(new \DateTimeImmutable());
            $version->setDeletionReason(VersionDeletionReason::from($reason));
        }
        $this->store($maintainer, $admin, $package, $version);

        match ($actor) {
            'maintainer' => $this->client->loginUser($maintainer),
            'admin' => $this->client->loginUser($admin),
            null => null,
        };

        $this->client->request('GET', '/versions/'.$version->getId().'.json');
        self::assertResponseStatusCodeSame($expectedStatus);

        if ($expectedStatus === 404) {
            $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('error', $payload['status'] ?? null);
        }
    }

    public function testViewPackageVersionHiddenResponseIsNotSharedCached(): void
    {
        $maintainer = self::createUser('owner', 'owner@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$maintainer]);

        $hidden = $this->createStableVersion($package, '1.0.0');
        $hidden->setSoftDeletedAt(new \DateTimeImmutable());
        $hidden->setDeletionReason(VersionDeletionReason::Hidden);

        $maintainerSoftDeleted = $this->createStableVersion($package, '1.1.0');
        $maintainerSoftDeleted->setSoftDeletedAt(new \DateTimeImmutable());
        $maintainerSoftDeleted->setDeletionReason(VersionDeletionReason::DeletedByMaintainer);

        $this->store($maintainer, $package, $hidden, $maintainerSoftDeleted);

        // Hidden, served to authorized maintainer -> must NOT be shared-cacheable.
        $this->client->loginUser($maintainer);
        $this->client->request('GET', '/versions/'.$hidden->getId().'.json');
        self::assertResponseStatusCodeSame(200);
        $cacheControl = $this->client->getResponse()->headers->get('Cache-Control', '');
        self::assertStringNotContainsString('s-maxage', $cacheControl, 'Hidden version JSON must not advertise a shared-cache TTL');

        // Non-Hidden soft-delete reason, served to anonymous -> keeps shared cache. Confirms the
        // exemption above is Hidden-specific, not a blanket disable.
        $this->client->restart();
        $this->client->request('GET', '/versions/'.$maintainerSoftDeleted->getId().'.json');
        self::assertResponseStatusCodeSame(200);
        $cacheControl = $this->client->getResponse()->headers->get('Cache-Control', '');
        self::assertStringContainsString('s-maxage=86400', $cacheControl);
    }

    private function createStableVersion(Package $package, string $version): Version
    {
        $v = new Version();
        $v->setName($package->getName());
        $v->setVersion($version);
        $v->setNormalizedVersion(new VersionParser()->normalize($version));
        $v->setLicense(['MIT']);
        $v->setAutoload([]);
        $v->setDevelopment(false);
        $v->setPackage($package);
        $package->getVersions()->add($v);
        $v->setReleasedAt(new \DateTimeImmutable());
        $v->setUpdatedAt(new \DateTimeImmutable());

        return $v;
    }
}

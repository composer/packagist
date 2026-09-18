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
use App\Entity\User;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\Log\AuditLogEventType;
use App\Log\Display\AuditLogDisplayFactory;
use App\Log\Display\Event\CanonicalUrlChangedDisplay;
use App\Log\Display\Event\FilterListEntryAddedDisplay;
use App\Log\Display\Event\FilterListEntryDeletedDisplay;
use App\Log\Display\Event\FilterListEntryDisabledDisplay;
use App\Log\Display\Event\FilterListEntryEditedDisplay;
use App\Log\Display\Event\FilterListEntryEnabledDisplay;
use App\Log\Display\Event\GenericUserDisplay;
use App\Log\Display\Event\GitHubLinkedWithUserDisplay;
use App\Log\Display\Event\OrganizationInvitationDisplay;
use App\Log\Display\Event\PackageAbandonedDisplay;
use App\Log\Display\Event\PackageCreatedDisplay;
use App\Log\Display\Event\PackageDeletedDisplay;
use App\Log\Display\Event\PackageFrozenDisplay;
use App\Log\Display\Event\PackageUnabandonedDisplay;
use App\Log\Display\Event\PackageUnfrozenDisplay;
use App\Log\Display\Event\SecurityAdvisoryCreatedDisplay;
use App\Log\Display\Event\SecurityAdvisoryEditedDisplay;
use App\Log\Display\Event\SecurityAdvisoryWithdrawnDisplay;
use App\Log\Display\Event\TwoFaDeactivatedDisplay;
use App\Log\Display\Event\UserFreezeDisplay;
use App\Log\Display\Event\UserVerifiedDisplay;
use App\Log\Display\Event\VersionDeletedDisplay;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Ulid;

class AuditLogDisplayFactoryTest extends TestCase
{
    private AuditLogDisplayFactory $factory;
    private Security&Stub $security;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->factory = new AuditLogDisplayFactory($this->security);
    }

    public function testBuildPackageCreatedWithUserActor(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageCreated,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'actor' => ['id' => 123, 'username' => 'testuser'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageCreatedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('https://github.com/vendor/package', $display->repository);
        self::assertSame(123, $display->actor->id);
        self::assertSame('testuser', $display->actor->username);
        // records predating moderator submissions carry no 'user' attribute
        self::assertNull($display->maintainer);
        self::assertSame(AuditLogEventType::PackageCreated, $display->getType());
        self::assertSame('log/display/package_created.html.twig', $display->getTemplateName());
        self::assertSame('audit_log.type.package_created', $display->getTypeTranslationKey());
    }

    public function testBuildPackageCreatedWithSystemActor(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageCreated,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'actor' => 'automation',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageCreatedDisplay::class, $display);
        self::assertNull($display->actor->id);
        self::assertSame('automation', $display->actor->username);
    }

    public function testBuildPackageCreatedWithMaintainer(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageCreated,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'user' => ['id' => 456, 'username' => 'newowner'],
                'actor' => ['id' => 123, 'username' => 'moderator'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageCreatedDisplay::class, $display);
        self::assertSame(456, $display->maintainer->id);
        self::assertSame('newowner', $display->maintainer->username);
        self::assertSame('moderator', $display->actor->username);
    }

    public function testBuildPackageDeleted(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageDeleted,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'actor' => ['id' => 456, 'username' => 'admin'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageDeletedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('https://github.com/vendor/package', $display->repository);
        self::assertSame(456, $display->actor->id);
        self::assertSame('admin', $display->actor->username);
        self::assertSame(AuditLogEventType::PackageDeleted, $display->getType());
        self::assertSame('log/display/package_deleted.html.twig', $display->getTemplateName());
    }

    public function testBuildCanonicalUrlChanged(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::CanonicalUrlChanged,
            [
                'name' => 'vendor/package',
                'repository_from' => 'https://github.com/vendor/old-package',
                'repository_to' => 'https://github.com/vendor/new-package',
                'actor' => ['id' => 789, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(CanonicalUrlChangedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('https://github.com/vendor/old-package', $display->repositoryFrom);
        self::assertSame('https://github.com/vendor/new-package', $display->repositoryTo);
        self::assertSame(789, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
        self::assertSame(AuditLogEventType::CanonicalUrlChanged, $display->getType());
        self::assertSame('log/display/canonical_url_changed.html.twig', $display->getTemplateName());
    }

    public function testBuildVersionDeleted(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::VersionDeleted,
            [
                'name' => 'vendor/package',
                'version' => '1.0.0',
                'actor' => ['id' => 111, 'username' => 'moderator'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(VersionDeletedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('1.0.0', $display->version);
        self::assertSame(111, $display->actor->id);
        self::assertSame('moderator', $display->actor->username);
        self::assertSame(AuditLogEventType::VersionDeleted, $display->getType());
        self::assertSame('log/display/version_deleted.html.twig', $display->getTemplateName());
    }

    public function testBuildPackageAbandonedWithReplacement(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageAbandoned,
            [
                'name' => 'vendor/old-package',
                'repository' => 'https://github.com/vendor/old-package',
                'replacement_package' => 'vendor/new-package',
                'reason' => 'manual',
                'actor' => ['id' => 123, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageAbandonedDisplay::class, $display);
        self::assertSame('vendor/old-package', $display->packageName);
        self::assertSame('https://github.com/vendor/old-package', $display->repository);
        self::assertSame('vendor/new-package', $display->replacementPackage);
        self::assertSame('manual', $display->reason);
        self::assertSame(123, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
        self::assertSame(AuditLogEventType::PackageAbandoned, $display->getType());
        self::assertSame('log/display/package_abandoned.html.twig', $display->getTemplateName());
    }

    public function testBuildPackageAbandonedWithoutReplacement(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageAbandoned,
            [
                'name' => 'vendor/abandoned-package',
                'repository' => 'https://github.com/vendor/abandoned-package',
                'replacement_package' => null,
                'reason' => 'repository_archived',
                'actor' => 'automation',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageAbandonedDisplay::class, $display);
        self::assertSame('vendor/abandoned-package', $display->packageName);
        self::assertSame('https://github.com/vendor/abandoned-package', $display->repository);
        self::assertNull($display->replacementPackage);
        self::assertSame('repository_archived', $display->reason);
        self::assertNull($display->actor->id);
        self::assertSame('automation', $display->actor->username);
    }

    public function testBuildPackageUnabandoned(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageUnabandoned,
            [
                'name' => 'vendor/restored-package',
                'repository' => 'https://github.com/vendor/restored-package',
                'previous_replacement_package' => 'vendor/replacement',
                'actor' => ['id' => 234, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageUnabandonedDisplay::class, $display);
        self::assertSame('vendor/restored-package', $display->packageName);
        self::assertSame('https://github.com/vendor/restored-package', $display->repository);
        self::assertSame(234, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
        self::assertSame(AuditLogEventType::PackageUnabandoned, $display->getType());
        self::assertSame('log/display/package_unabandoned.html.twig', $display->getTemplateName());
    }

    public function testBuildPackageUnabandonedWithoutPreviousReplacement(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageUnabandoned,
            [
                'name' => 'vendor/restored-package',
                'repository' => 'https://github.com/vendor/restored-package',
                'previous_replacement_package' => null,
                'actor' => ['id' => 777, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageUnabandonedDisplay::class, $display);
        self::assertSame(777, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
    }

    public function testBuildPackageFrozen(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageFrozen,
            [
                'name' => 'vendor/suspicious-package',
                'repository' => 'https://github.com/vendor/suspicious-package',
                'reason' => 'spam',
                'actor' => ['id' => 123, 'username' => 'moderator'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageFrozenDisplay::class, $display);
        self::assertSame('vendor/suspicious-package', $display->packageName);
        self::assertSame('https://github.com/vendor/suspicious-package', $display->repository);
        self::assertSame('spam', $display->reason);
        self::assertSame(123, $display->actor->id);
        self::assertSame('moderator', $display->actor->username);
        self::assertSame(AuditLogEventType::PackageFrozen, $display->getType());
        self::assertSame('log/display/package_frozen.html.twig', $display->getTemplateName());
    }

    public function testBuildPackageUnfrozen(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageUnfrozen,
            [
                'name' => 'vendor/restored-package',
                'repository' => 'https://github.com/vendor/restored-package',
                'actor' => ['id' => 234, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageUnfrozenDisplay::class, $display);
        self::assertSame('vendor/restored-package', $display->packageName);
        self::assertSame('https://github.com/vendor/restored-package', $display->repository);
        self::assertSame(234, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
        self::assertSame(AuditLogEventType::PackageUnfrozen, $display->getType());
        self::assertSame('log/display/package_unfrozen.html.twig', $display->getTemplateName());
    }

    #[TestWith([false, 999, '**@**.**'])]
    #[TestWith([true, 999, 'john@doe.com'])]
    #[TestWith([false, 123, 'john@doe.com'])]
    public function testBuildUserVerified(bool $hasAuditorRole, int $authenticatedUserId, string $expectedEmail): void
    {
        // override setUp objects to get a real mock here
        $security = $this->createMock(Security::class);
        $security
            ->expects(self::once())
            ->method('isGranted')
            ->with('ROLE_AUDITOR')
            ->willReturn($hasAuditorRole);
        $this->factory = new AuditLogDisplayFactory($security);

        $user = new User();
        $reflectionProperty = new \ReflectionProperty($user, 'id');
        $reflectionProperty->setValue($user, $authenticatedUserId);

        $security
            ->expects(self::atMost(1))
            ->method('getUser')
            ->willReturn($user);

        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::UserVerified,
            [
                'user' => ['id' => 123, 'username' => 'johndoe'],
                'email' => 'john@doe.com',
                'actor' => 'unknown',
            ],
            userId: 123,
        );

        $display = $this->factory->buildSingle($auditRecord);
        self::assertInstanceOf(UserVerifiedDisplay::class, $display);
        self::assertSame('johndoe', $display->username);
        self::assertSame($expectedEmail, $display->email);
    }

    public function testBuildMultipleRecords(): void
    {
        $records = [
            $this->createAuditRecord(
                AuditLogEventType::PackageCreated,
                [
                    'name' => 'vendor/package1',
                    'repository' => 'https://github.com/vendor/package1',
                    'actor' => ['id' => 1, 'username' => 'user1'],
                ]
            ),
            $this->createAuditRecord(
                AuditLogEventType::PackageDeleted,
                [
                    'name' => 'vendor/package2',
                    'repository' => 'https://github.com/vendor/package2',
                    'actor' => ['id' => 2, 'username' => 'user2'],
                ]
            ),
            $this->createAuditRecord(
                AuditLogEventType::VersionDeleted,
                [
                    'name' => 'vendor/package3',
                    'version' => '1.0.0',
                    'actor' => ['id' => 3, 'username' => 'user3'],
                ]
            ),
        ];

        $displays = $this->factory->build($records);

        self::assertCount(3, $displays);
        self::assertInstanceOf(PackageCreatedDisplay::class, $displays[0]);
        self::assertInstanceOf(PackageDeletedDisplay::class, $displays[1]);
        self::assertInstanceOf(VersionDeletedDisplay::class, $displays[2]);
        self::assertSame('vendor/package1', $displays[0]->packageName);
        self::assertSame('vendor/package2', $displays[1]->packageName);
        self::assertSame('vendor/package3', $displays[2]->packageName);
    }

    public function testDateTimeIsPreserved(): void
    {
        $datetime = new \DateTimeImmutable('2024-01-15 10:30:00');
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::PackageCreated,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'actor' => ['id' => 1, 'username' => 'user'],
            ],
            $datetime
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertSame($datetime, $display->getDateTime());
    }

    public function testBuildGitHubLinkedWithUser(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::GitHubLinkedWithUser,
            [
                'user' => ['id' => 123, 'username' => 'johndoe'],
                'github_username' => 'github-testuser',
                'github_id' => 123456,
                'actor' => ['id' => 123, 'username' => 'testuser'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(GitHubLinkedWithUserDisplay::class, $display);
        self::assertSame('johndoe', $display->username);
        self::assertSame('github-testuser', $display->githubUsername);
        self::assertSame(123456, $display->githubId);
        self::assertSame(123, $display->actor->id);
        self::assertSame('testuser', $display->actor->username);
        self::assertSame(AuditLogEventType::GitHubLinkedWithUser, $display->getType());
        self::assertSame('log/display/github_linked_with_user.html.twig', $display->getTemplateName());
    }

    public function testBuildGitHubDisconnectedFromUser(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::GitHubDisconnectedFromUser,
            [
                'user' => ['id' => 123, 'username' => 'johndoe'],
                'actor' => ['id' => 456, 'username' => 'testuser'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(GenericUserDisplay::class, $display);
        self::assertSame('johndoe', $display->username);
        self::assertSame(456, $display->actor->id);
        self::assertSame('testuser', $display->actor->username);
        self::assertSame(AuditLogEventType::GitHubDisconnectedFromUser, $display->getType());
    }

    public function testBuildGitHubLinkedWithUserSystemActor(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::GitHubLinkedWithUser,
            [
                'user' => ['id' => 123, 'username' => 'johndoe'],
                'github_username' => 'gh-admin',
                'github_id' => 123456,
                'actor' => 'admin',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(GitHubLinkedWithUserDisplay::class, $display);
        self::assertSame('johndoe', $display->username);
        self::assertSame('gh-admin', $display->githubUsername);
        self::assertSame(123456, $display->githubId);
        self::assertNull($display->actor->id);
        self::assertSame('admin', $display->actor->username);
    }

    public function testBuildTwoFaActivated(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::TwoFaAuthenticationActivated,
            [
                'user' => ['id' => 1234, 'username' => 'testuser1234'],
                'actor' => ['id' => 123, 'username' => 'testuser'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(GenericUserDisplay::class, $display);
        self::assertSame('testuser1234', $display->username);
        self::assertSame(123, $display->actor->id);
        self::assertSame('testuser', $display->actor->username);
        self::assertSame(AuditLogEventType::TwoFaAuthenticationActivated, $display->getType());
    }

    public function testBuildTwoFaDeactivated(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::TwoFaAuthenticationDeactivated,
            [
                'user' => ['id' => 1234, 'username' => 'testuser1234'],
                'reason' => 'Manually disabled',
                'actor' => ['id' => 123, 'username' => 'testuser'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(TwoFaDeactivatedDisplay::class, $display);
        self::assertSame('testuser1234', $display->username);
        self::assertSame('Manually disabled', $display->reason);
        self::assertSame(123, $display->actor->id);
        self::assertSame('testuser', $display->actor->username);
        self::assertSame(AuditLogEventType::TwoFaAuthenticationDeactivated, $display->getType());
        self::assertSame('log/display/two_fa_deactivated.html.twig', $display->getTemplateName());
    }

    public function testBuildFilterListEntryAdded(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::FilterListEntryAdded,
            [
                'entry' => ['package_name' => 'acme/package', 'version' => '<1.0', 'list' => FilterLists::MALWARE->value, 'reason' => 'malware', 'source' => FilterSources::AIKIDO->value],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(FilterListEntryAddedDisplay::class, $display);
        self::assertSame('acme/package', $display->packageName);
        self::assertSame('<1.0', $display->version);
        self::assertSame('malware', $display->reason);
        self::assertSame(FilterLists::MALWARE, $display->list);
        self::assertNull($display->actor->id);
        self::assertSame('unknown', $display->actor->username);
        self::assertSame(FilterSources::AIKIDO, $display->source);
        self::assertSame(AuditLogEventType::FilterListEntryAdded, $display->getType());
        self::assertSame('log/display/filter_list_entry_added.html.twig', $display->getTemplateName());
    }

    public function testBuildFilterListEntryDeleted(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::FilterListEntryDeleted,
            [
                'entry' => ['package_name' => 'acme/package', 'version' => '<1.0', 'list' => FilterLists::MALWARE->value, 'reason' => 'malware', 'source' => FilterSources::AIKIDO->value],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(FilterListEntryDeletedDisplay::class, $display);
        self::assertSame('acme/package', $display->packageName);
        self::assertSame('<1.0', $display->version);
        self::assertSame('malware', $display->reason);
        self::assertSame(FilterLists::MALWARE, $display->list);
        self::assertNull($display->actor->id);
        self::assertSame('unknown', $display->actor->username);
        self::assertSame(FilterSources::AIKIDO, $display->source);
        self::assertSame(AuditLogEventType::FilterListEntryDeleted, $display->getType());
        self::assertSame('log/display/filter_list_entry_deleted.html.twig', $display->getTemplateName());
    }

    public function testBuildFilterListEntryDisabled(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::FilterListEntryDisabled,
            [
                'entry' => ['package_name' => 'acme/package', 'version' => '1.0', 'list' => FilterLists::MALWARE->value, 'reason' => 'false positive', 'source' => FilterSources::AIKIDO->value],
                'actor' => ['id' => 5, 'username' => 'admin'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(FilterListEntryDisabledDisplay::class, $display);
        self::assertSame('acme/package', $display->packageName);
        self::assertSame('1.0', $display->version);
        self::assertSame(FilterLists::MALWARE, $display->list);
        self::assertSame(FilterSources::AIKIDO, $display->source);
        self::assertSame('false positive', $display->reason);
        self::assertSame(5, $display->actor->id);
        self::assertSame('admin', $display->actor->username);
        self::assertSame('log/display/filter_list_entry_disabled.html.twig', $display->getTemplateName());
    }

    public function testBuildFilterListEntryEnabled(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::FilterListEntryEnabled,
            [
                'entry' => ['package_name' => 'acme/package', 'version' => '1.0', 'list' => FilterLists::MALWARE->value, 'reason' => 'restored', 'source' => FilterSources::AIKIDO->value],
                'actor' => ['id' => 5, 'username' => 'admin'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(FilterListEntryEnabledDisplay::class, $display);
        self::assertSame('acme/package', $display->packageName);
        self::assertSame(FilterSources::AIKIDO, $display->source);
        self::assertSame(AuditLogEventType::FilterListEntryEnabled, $display->getType());
    }

    public function testBuildFilterListEntryEdited(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::FilterListEntryEdited,
            [
                'entry' => [
                    'package_name' => 'acme/package',
                    'version' => '>=1.0,<2.0',
                    'list' => FilterLists::MALWARE->value,
                    'source' => FilterSources::AIKIDO->value,
                ],
                'previous' => ['version' => '1.0.0'],
                'actor' => ['id' => 5, 'username' => 'admin'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(FilterListEntryEditedDisplay::class, $display);
        self::assertSame('>=1.0,<2.0', $display->version);
        self::assertSame('1.0.0', $display->previousVersion);
        self::assertSame(FilterSources::AIKIDO, $display->source);
    }

    public function testBuildFilterListEntryEditedCarriesInternalNote(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::FilterListEntryEdited,
            [
                'entry' => [
                    'package_name' => 'acme/package',
                    'version' => '>=1.0,<2.0',
                    'list' => FilterLists::MALWARE->value,
                    'source' => FilterSources::AIKIDO->value,
                    'internal_note' => 'confirmed malware after manual review',
                ],
                'previous' => ['version' => '1.0.0', 'internal_note' => 'older note'],
                'actor' => ['id' => 5, 'username' => 'admin'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(FilterListEntryEditedDisplay::class, $display);
        self::assertSame('confirmed malware after manual review', $display->internalNote);
        self::assertSame('older note', $display->previousInternalNote);
    }

    public function testBuildSecurityAdvisoryCreated(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::SecurityAdvisoryCreated,
            [
                'advisoryId' => 'PKSA-abcd-1234-5678',
                'name' => 'acme/package',
                'source' => 'GitHub',
                'remoteId' => 'GHSA-aaaa-bbbb-cccc',
                'cve' => 'CVE-2024-12345',
                'title' => 'Remote code execution',
                'actor' => 'automation',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(SecurityAdvisoryCreatedDisplay::class, $display);
        self::assertSame('acme/package', $display->packageName);
        self::assertSame('PKSA-abcd-1234-5678', $display->advisoryId);
        self::assertSame('CVE-2024-12345', $display->cve);
        self::assertSame('Remote code execution', $display->title);
        self::assertSame('GitHub', $display->source);
        self::assertNull($display->actor->id);
        self::assertSame('automation', $display->actor->username);
        self::assertSame(AuditLogEventType::SecurityAdvisoryCreated, $display->getType());
        self::assertSame('log/display/security_advisory_created.html.twig', $display->getTemplateName());
        self::assertSame('audit_log.type.security_advisory_created', $display->getTypeTranslationKey());
    }

    public function testBuildSecurityAdvisoryEdited(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::SecurityAdvisoryEdited,
            [
                'advisoryId' => 'PKSA-abcd-1234-5678',
                'name' => 'acme/package',
                'source' => 'GitHub',
                'remoteId' => 'GHSA-aaaa-bbbb-cccc',
                'cve' => 'CVE-2024-12345',
                'title' => 'Edited advisory',
                'actor' => 'automation',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(SecurityAdvisoryEditedDisplay::class, $display);
        self::assertSame('acme/package', $display->packageName);
        self::assertSame('CVE-2024-12345', $display->cve);
        self::assertSame(AuditLogEventType::SecurityAdvisoryEdited, $display->getType());
        self::assertSame('log/display/security_advisory_edited.html.twig', $display->getTemplateName());
    }

    public function testBuildSecurityAdvisoryWithdrawn(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::SecurityAdvisoryWithdrawn,
            [
                'advisoryId' => 'PKSA-abcd-1234-5678',
                'name' => 'acme/package',
                'source' => 'GitHub',
                'remoteId' => 'GHSA-aaaa-bbbb-cccc',
                'cve' => null,
                'title' => 'Withdrawn advisory',
                'actor' => 'automation',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(SecurityAdvisoryWithdrawnDisplay::class, $display);
        self::assertNull($display->cve);
        self::assertSame(AuditLogEventType::SecurityAdvisoryWithdrawn, $display->getType());
        self::assertSame('log/display/security_advisory_withdrawn.html.twig', $display->getTemplateName());
    }

    public function testBuildUserFrozenHidesInternalReasonForNonAuditor(): void
    {
        // setUp's stub returns false for isGranted('ROLE_AUDITOR') by default.
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::UserFrozen,
            [
                'user' => ['id' => 123, 'username' => 'baduser'],
                'reason' => 'spam',
                'reasonText' => 'spamming packages',
                'internalReason' => 'linked to ticket #42',
                'actor' => ['id' => 456, 'username' => 'admin'],
            ],
            userId: 123,
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(UserFreezeDisplay::class, $display);
        self::assertSame('baduser', $display->username);
        self::assertSame('spam', $display->reason);
        self::assertSame('spamming packages', $display->reasonText);
        self::assertNull($display->internalReason);
        self::assertSame(456, $display->actor->id);
        self::assertSame('admin', $display->actor->username);
        self::assertSame(AuditLogEventType::UserFrozen, $display->getType());
        self::assertSame('log/display/user_freeze.html.twig', $display->getTemplateName());
        self::assertSame('audit_log.type.user_frozen', $display->getTypeTranslationKey());
    }

    public function testBuildUserFrozenShowsInternalReasonForAuditor(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);
        $this->factory = new AuditLogDisplayFactory($security);

        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::UserFrozen,
            [
                'user' => ['id' => 123, 'username' => 'baduser'],
                'reason' => 'bad_actor',
                'reasonText' => null,
                'internalReason' => 'linked to ticket #42',
                'actor' => ['id' => 456, 'username' => 'admin'],
            ],
            userId: 123,
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(UserFreezeDisplay::class, $display);
        self::assertSame('bad_actor', $display->reason);
        self::assertNull($display->reasonText);
        self::assertSame('linked to ticket #42', $display->internalReason);
    }

    public function testBuildUserUnfrozen(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::UserUnfrozen,
            [
                'user' => ['id' => 123, 'username' => 'reformed'],
                'reasonText' => 'appeal accepted',
                'internalReason' => null,
                'actor' => ['id' => 456, 'username' => 'admin'],
            ],
            userId: 123,
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(UserFreezeDisplay::class, $display);
        self::assertSame('reformed', $display->username);
        self::assertNull($display->reason);
        self::assertSame('appeal accepted', $display->reasonText);
        self::assertSame(456, $display->actor->id);
        self::assertSame(AuditLogEventType::UserUnfrozen, $display->getType());
        self::assertSame('log/display/user_freeze.html.twig', $display->getTemplateName());
    }

    public function testBuildOrganizationInvitationSent(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::OrganizationInvitationSent,
            [
                'organization' => ['id' => (string) new Ulid(), 'org_slug' => 'acme', 'org_name' => 'ACME Corp'],
                'email' => 'alice@example.org',
                'actor' => ['id' => 7, 'username' => 'owner'],
            ],
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(OrganizationInvitationDisplay::class, $display);
        self::assertSame(AuditLogEventType::OrganizationInvitationSent, $display->getType());
        self::assertSame('log/display/organization_invitation_sent.html.twig', $display->getTemplateName());
        self::assertSame('audit_log.type.organization_invitation_sent', $display->getTypeTranslationKey());
        self::assertSame('acme', $display->organization->slug);
        self::assertSame('owner', $display->actor->username);
        // Not an auditor: on the public log the invited email is obfuscated.
        self::assertSame('**@**.**', $display->email);
    }

    public function testBuildOrganizationInvitationExpiredHasNoActingUser(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::OrganizationInvitationExpired,
            [
                'organization' => ['id' => (string) new Ulid(), 'org_slug' => 'acme', 'org_name' => 'ACME Corp'],
                'email' => 'alice@example.org',
                'actor' => 'automation',
            ],
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(OrganizationInvitationDisplay::class, $display);
        self::assertSame(AuditLogEventType::OrganizationInvitationExpired, $display->getType());
        self::assertNull($display->actor->id);
        self::assertSame('automation', $display->actor->username);
    }

    #[TestWith([true, 'alice@example.org'])]
    #[TestWith([false, '**@**.**'])]
    public function testOrganizationInvitationEmailVisibility(bool $isAuditor, string $expectedEmail): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($isAuditor);
        $this->factory = new AuditLogDisplayFactory($security);

        $auditRecord = $this->createAuditRecord(
            AuditLogEventType::OrganizationInvitationRevoked,
            [
                'organization' => ['id' => (string) new Ulid(), 'org_slug' => 'acme', 'org_name' => 'ACME Corp'],
                'email' => 'alice@example.org',
                'actor' => ['id' => 7, 'username' => 'owner'],
            ],
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(OrganizationInvitationDisplay::class, $display);
        self::assertSame($expectedEmail, $display->email);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createAuditRecord(
        AuditLogEventType $type,
        array $attributes,
        ?\DateTimeImmutable $datetime = null,
        ?int $userId = null,
    ): AuditRecord {
        $datetime ??= new \DateTimeImmutable();

        $reflection = new \ReflectionClass(AuditRecord::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $datetimeProperty = $reflection->getProperty('datetime');
        $datetimeProperty->setValue($instance, $datetime);

        $typeProperty = $reflection->getProperty('type');
        $typeProperty->setValue($instance, $type);

        $attributesProperty = $reflection->getProperty('attributes');
        $attributesProperty->setValue($instance, $attributes);

        $attributesProperty = $reflection->getProperty('userId');
        $attributesProperty->setValue($instance, $userId);

        $instance->setIp('192.168.1.1');

        return $instance;
    }
}

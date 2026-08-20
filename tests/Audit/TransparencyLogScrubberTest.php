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

use App\Audit\AbandonmentReason;
use App\Audit\AuditRecordType;
use App\Audit\TransparencyLogScrubber;
use App\Audit\TransparencyLogType;
use App\Audit\VersionDeletionReason;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\User;
use App\Entity\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransparencyLogScrubberTest extends TestCase
{
    /**
     * Every value fed into a fixture that must never reach the transparency log includes this marker string
     */
    private const PRIVATE_MARKER = 'must-not-be-published';

    /**
     * Keys that must never survive scrubbing at any depth.
     */
    private const PRIVATE_KEYS_AT_ANY_DEPTH = ['email', 'email_from', 'email_to', 'internalReason', 'internalReasonText', 'internal_note'];

    /**
     * Keys that must never survive scrubbing at the top level of the attributes.
     */
    private const PRIVATE_KEYS_AT_TOP_LEVEL = ['metadata'];

    private const PACKAGE_NAME = 'acme/widget';
    private const REPOSITORY = 'https://github.com/acme/widget';
    private const VERSION = '1.2.3';

    private const MAINTAINER = ['id' => 11, 'username' => 'maintainer'];
    private const ADMIN = ['id' => 22, 'username' => 'admin'];
    private const NEW_OWNER = ['id' => 33, 'username' => 'newowner'];

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

    /**
     * Scrubs a real audit record of every type we project and pins the exact result.
     *
     * @param array<string, mixed> $expectedScrubbedAttributes
     */
    #[DataProvider('projectedRecords')]
    public function testProjectedRecordIsScrubbedToExactlyThePublicAttributes(AuditRecordType $type, AuditRecord $record, array $expectedScrubbedAttributes): void
    {
        self::assertSame($type, $record->type, 'the fixture must build the record type it claims to cover');
        self::assertNotNull(TransparencyLogType::fromAuditRecordType($type), $type->value.' is not projected, so it does not belong in this data set');

        $scrubbed = new TransparencyLogScrubber()->scrub($record->attributes);

        self::assertSame(
            self::flatten($expectedScrubbedAttributes),
            self::flatten($scrubbed),
            $type->value.': scrubbed attributes differ from the pinned public set. If an attribute was added to the audit record, decide whether it is publishable before updating this expectation.',
        );

        self::assertSame([], self::privateValuePaths($scrubbed), $type->value.': private data survived scrubbing');
        self::assertSame([], self::privateKeyPaths($scrubbed), $type->value.': a private key survived scrubbing');
    }

    /**
     * Ensure that every projected audit record type is tested
     */
    public function testEveryProjectedAuditRecordTypeHasAFixture(): void
    {
        $covered = [];
        foreach (self::projectedRecords() as [$type, , ]) {
            $covered[] = $type->value;
        }

        self::assertSame(array_unique($covered), $covered, 'each projected audit record type should be covered exactly once');

        $projected = array_map(static fn (AuditRecordType $type): string => $type->value, TransparencyLogType::projectedAuditRecordTypes());

        sort($covered);
        sort($projected);
        self::assertSame($projected, $covered, 'every projected audit record type needs a scrubbing fixture in '.self::class.'::projectedRecords()');
    }

    /**
     * A fixture that stops feeding private data would keep passing while testing nothing, so the set
     * of record types whose fixtures carry private data is pinned too.
     */
    public function testFixturesStillFeedPrivateDataIntoEveryRecordThatCanCarryIt(): void
    {
        $carryingPrivateData = [];
        foreach (self::projectedRecords() as [$type, $record, ]) {
            if (self::privateValuePaths($record->attributes) !== []) {
                $carryingPrivateData[] = $type->value;
            }
        }

        sort($carryingPrivateData);
        self::assertSame([
            // email_from/email_to
            'email_changed',
            // admin-only internalReason
            'package_deleted',
            // the version metadata blob, which carries author emails among other things
            'version_created',
            // admin-only internalReasonText
            'version_soft_deleted',
        ], $carryingPrivateData, 'a fixture no longer feeds private data into the scrubber, so its removal is no longer covered');
    }

    /**
     * One fixture per projected audit record type, keyed by the type value.
     *
     * @return iterable<string, array{AuditRecordType, AuditRecord, array<string, mixed>}>
     */
    public static function projectedRecords(): iterable
    {
        $package = self::package();
        $version = self::version($package);
        $maintainer = self::user(self::MAINTAINER);
        $admin = self::user(self::ADMIN);
        $newOwner = self::user(self::NEW_OWNER);

        // package ownership
        yield 'maintainer_added' => [
            AuditRecordType::MaintainerAdded,
            AuditRecord::maintainerAdded($package, $maintainer, $admin),
            ['name' => self::PACKAGE_NAME, 'user' => self::MAINTAINER, 'actor' => self::ADMIN],
        ];

        yield 'maintainer_removed' => [
            AuditRecordType::MaintainerRemoved,
            AuditRecord::maintainerRemoved($package, $maintainer, $admin),
            ['name' => self::PACKAGE_NAME, 'user' => self::MAINTAINER, 'actor' => self::ADMIN],
        ];

        yield 'package_transferred' => [
            AuditRecordType::PackageTransferred,
            AuditRecord::packageTransferred($package, $admin, [$maintainer], [$newOwner]),
            [
                'name' => self::PACKAGE_NAME,
                'actor' => self::ADMIN,
                'previous_maintainers' => [self::MAINTAINER],
                'current_maintainers' => [self::NEW_OWNER],
            ],
        ];

        // package management
        yield 'package_created' => [
            AuditRecordType::PackageCreated,
            AuditRecord::packageCreated($package, $maintainer),
            ['name' => self::PACKAGE_NAME, 'repository' => self::REPOSITORY, 'actor' => self::MAINTAINER],
        ];

        yield 'canonical_url_changed' => [
            AuditRecordType::CanonicalUrlChanged,
            AuditRecord::canonicalUrlChange($package, $maintainer, 'https://github.com/acme/old-widget'),
            [
                'name' => self::PACKAGE_NAME,
                'repository_from' => 'https://github.com/acme/old-widget',
                'repository_to' => self::REPOSITORY,
                'actor' => self::MAINTAINER,
            ],
        ];

        yield 'package_abandoned' => [
            AuditRecordType::PackageAbandoned,
            AuditRecord::packageAbandoned($package, $maintainer, 'acme/replacement', AbandonmentReason::Manual),
            [
                'name' => self::PACKAGE_NAME,
                'repository' => self::REPOSITORY,
                'replacement_package' => 'acme/replacement',
                'reason' => 'manual',
                'actor' => self::MAINTAINER,
            ],
        ];

        yield 'package_unabandoned' => [
            AuditRecordType::PackageUnabandoned,
            AuditRecord::packageUnabandoned($package, $maintainer),
            ['name' => self::PACKAGE_NAME, 'repository' => self::REPOSITORY, 'actor' => self::MAINTAINER],
        ];

        yield 'package_frozen' => [
            AuditRecordType::PackageFrozen,
            AuditRecord::packageFrozen($package, $admin, PackageFreezeReason::Malware),
            [
                'name' => self::PACKAGE_NAME,
                'repository' => self::REPOSITORY,
                'reason' => 'malware',
                'actor' => self::ADMIN,
            ],
        ];

        yield 'package_unfrozen' => [
            AuditRecordType::PackageUnfrozen,
            AuditRecord::packageUnfrozen($package, $admin),
            ['name' => self::PACKAGE_NAME, 'repository' => self::REPOSITORY, 'actor' => self::ADMIN],
        ];

        yield 'package_deleted' => [
            AuditRecordType::PackageDeleted,
            AuditRecord::packageDeleted($package, $admin, 'violates the terms of service', 'reported by jane, ticket '.self::PRIVATE_MARKER),
            [
                'name' => self::PACKAGE_NAME,
                'repository' => self::REPOSITORY,
                'reason' => 'violates the terms of service',
                'actor' => self::ADMIN,
            ],
        ];

        // version
        yield 'version_created' => [
            AuditRecordType::VersionCreated,
            AuditRecord::versionCreated($version, self::versionMetadata(), null),
            ['name' => self::PACKAGE_NAME, 'version' => self::VERSION, 'actor' => 'automation'],
        ];

        yield 'version_reference_change_blocked' => [
            AuditRecordType::VersionReferenceChangeBlocked,
            AuditRecord::versionReferenceChangeBlocked($package, self::VERSION, 'aaaaaaa', 'bbbbbbb'),
            ['name' => self::PACKAGE_NAME, 'version' => self::VERSION, 'ref_from' => 'aaaaaaa', 'ref_to' => 'bbbbbbb'],
        ];

        yield 'version_deleted' => [
            AuditRecordType::VersionDeleted,
            AuditRecord::versionDeleted($version, $maintainer),
            ['name' => self::PACKAGE_NAME, 'version' => self::VERSION, 'actor' => self::MAINTAINER],
        ];

        yield 'version_soft_deleted' => [
            AuditRecordType::VersionSoftDeleted,
            AuditRecord::versionSoftDeleted($version, VersionDeletionReason::DeletedByAdmin, 'contains a leaked credential', 'reported by jane, ticket '.self::PRIVATE_MARKER, $admin),
            [
                'name' => self::PACKAGE_NAME,
                'version' => self::VERSION,
                'reason' => 'admin',
                'reasonText' => 'contains a leaked credential',
                'actor' => self::ADMIN,
            ],
        ];

        yield 'version_recovered' => [
            AuditRecordType::VersionRecovered,
            AuditRecord::versionRecovered($version, VersionDeletionReason::Hidden, $admin),
            ['name' => self::PACKAGE_NAME, 'version' => self::VERSION, 'previousReason' => 'hidden', 'actor' => self::ADMIN],
        ];

        // account security
        yield 'two_fa_activated' => [
            AuditRecordType::TwoFaAuthenticationActivated,
            AuditRecord::twoFactorAuthenticationActivated($maintainer, $maintainer),
            ['user' => self::MAINTAINER, 'actor' => self::MAINTAINER],
        ];

        yield 'two_fa_deactivated' => [
            AuditRecordType::TwoFaAuthenticationDeactivated,
            AuditRecord::twoFactorAuthenticationDeactivated($maintainer, $admin, 'Disabled on request from user'),
            ['user' => self::MAINTAINER, 'actor' => self::ADMIN, 'reason' => 'Disabled on request from user'],
        ];

        yield 'password_reset' => [
            AuditRecordType::PasswordReset,
            AuditRecord::passwordReset($maintainer, $maintainer),
            ['user' => self::MAINTAINER, 'actor' => self::MAINTAINER],
        ];

        yield 'password_changed' => [
            AuditRecordType::PasswordChanged,
            AuditRecord::passwordChanged($maintainer, $maintainer),
            ['user' => self::MAINTAINER, 'actor' => self::MAINTAINER],
        ];

        yield 'email_changed' => [
            AuditRecordType::EmailChanged,
            AuditRecord::emailChanged(
                self::user(self::MAINTAINER, 'new.'.self::PRIVATE_MARKER.'@example.org'),
                $maintainer,
                'old.'.self::PRIVATE_MARKER.'@example.org',
            ),
            ['user' => self::MAINTAINER, 'actor' => self::MAINTAINER],
        ];

        yield 'github_linked_with_user' => [
            AuditRecordType::GitHubLinkedWithUser,
            AuditRecord::gitHubLinkedWithUser($maintainer, $maintainer, 'octo-maintainer', 4242),
            [
                'user' => self::MAINTAINER,
                'github_username' => 'octo-maintainer',
                'github_id' => 4242,
                'actor' => self::MAINTAINER,
            ],
        ];

        yield 'github_disconnected_from_user' => [
            AuditRecordType::GitHubDisconnectedFromUser,
            AuditRecord::gitHubDisconnectedFromUser($maintainer, $admin),
            ['user' => self::MAINTAINER, 'actor' => self::ADMIN],
        ];
    }

    /**
     * A trimmed-down version metadata blob: it is dropped wholesale, and its author emails are the
     * reason it must never be copied verbatim.
     *
     * @return array<string, mixed>
     */
    private static function versionMetadata(): array
    {
        return [
            'name' => self::PACKAGE_NAME,
            'version' => self::VERSION,
            'authors' => [['name' => 'Jane Doe', 'email' => 'jane.'.self::PRIVATE_MARKER.'@example.org']],
            'source' => ['type' => 'git', 'url' => self::REPOSITORY.'.git', 'reference' => 'aaaaaaa'],
            'support' => ['email' => 'support.'.self::PRIVATE_MARKER.'@example.org'],
        ];
    }

    private static function package(): Package
    {
        $package = new Package();
        $package->setName(self::PACKAGE_NAME);
        // set directly to avoid the network-based initialization setRepository() triggers
        new \ReflectionProperty($package, 'repository')->setValue($package, self::REPOSITORY);
        new \ReflectionProperty($package, 'id')->setValue($package, 42);

        return $package;
    }

    private static function version(Package $package): Version
    {
        $version = new Version();
        $version->setPackage($package);
        $version->setName($package->getName());
        $version->setVersion(self::VERSION);

        return $version;
    }

    /**
     * @param array{id: int, username: string} $data
     */
    private static function user(array $data, string $email = 'maintainer@example.org'): User
    {
        $user = new User();
        $user->setUsername($data['username']);
        $user->setEmail($email);
        new \ReflectionProperty($user, 'id')->setValue($user, $data['id']);

        return $user;
    }

    /**
     * Paths whose key or value carries the private marker.
     *
     * @param array<array-key, mixed> $attributes
     *
     * @return list<string>
     */
    private static function privateValuePaths(array $attributes): array
    {
        $found = [];
        foreach (self::flatten($attributes) as $path => $value) {
            if (str_contains($path, self::PRIVATE_MARKER) || (\is_string($value) && str_contains($value, self::PRIVATE_MARKER))) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Paths held under a key that must have been scrubbed, regardless of the value it carries.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<string>
     */
    private static function privateKeyPaths(array $attributes): array
    {
        $found = [];
        foreach (self::PRIVATE_KEYS_AT_TOP_LEVEL as $key) {
            if (\array_key_exists($key, $attributes)) {
                $found[] = $key;
            }
        }

        foreach (array_keys(self::flatten($attributes)) as $path) {
            foreach (self::PRIVATE_KEYS_AT_ANY_DEPTH as $key) {
                if ($path === $key || str_ends_with($path, '.'.$key)) {
                    $found[] = $path;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * Flattens an attribute tree into dotted path => leaf value pairs, so expectations compare exact
     * keys and value types without depending on attribute ordering.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $value, string $prefix = ''): array
    {
        $flat = [];
        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (\is_array($item) && $item !== []) {
                $flat = [...$flat, ...self::flatten($item, $path)];
                continue;
            }

            $flat[$path] = $item;
        }

        ksort($flat);

        return $flat;
    }
}

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

namespace App\Audit\Display;

use App\Audit\AuditRecordType;
use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use Symfony\Bundle\SecurityBundle\Security;

class AuditLogDisplayFactory
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * @param iterable<AuditRecord> $auditRecords
     *
     * @return array<AuditLogDisplayInterface>
     */
    public function build(iterable $auditRecords): array
    {
        $displays = [];
        foreach ($auditRecords as $record) {
            $displays[] = $this->buildSingle($record);
        }

        return $displays;
    }

    public function buildSingle(AuditRecord $record): AuditLogDisplayInterface
    {
        return match ($record->type) {
            AuditRecordType::MaintainerAdded => new MaintainerAddedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::MaintainerRemoved => new MaintainerRemovedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::PackageTransferred => new PackageTransferredDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['previous_maintainers'],
                $record->attributes['current_maintainers'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::PackageCreated => new PackageCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::PackageDeleted => new PackageDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['reason'] ?? null,
                $this->internalReason($record->attributes['internalReason'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::CanonicalUrlChanged => new CanonicalUrlChangedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository_from'],
                $record->attributes['repository_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::VersionCreated => new VersionCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['metadata']['source']['reference'] ?? null,
                $record->attributes['metadata']['dist']['reference'] ?? null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::PackageAbandoned => new PackageAbandonedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['replacement_package'] ?? null,
                $record->attributes['reason'] ?? null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::PackageUnabandoned => new PackageUnabandonedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::PackageFrozen => new PackageFrozenDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['reason'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::PackageUnfrozen => new PackageUnfrozenDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::VersionDeleted => new VersionDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::VersionReferenceChangeBlocked => new VersionReferenceChangeBlockedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['ref_from'] ?? null,
                $record->attributes['ref_to'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditRecordType::VersionSoftDeleted => new VersionSoftDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['reason'],
                $record->attributes['reasonText'] ?? null,
                $this->internalReason($record->attributes['internalReasonText'] ?? null),
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditRecordType::VersionRecovered => new VersionRecoveredDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['previousReason'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditRecordType::UserCreated => new UserCreatedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                UserRegistrationMethod::from($record->attributes['method']),
                $this->buildActor('self'),
                $record->ip,
            ),
            AuditRecordType::TwoFaAuthenticationActivated => new GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::TwoFaAuthenticationDeactivated => new TwoFaDeactivatedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $record->attributes['reason'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::PasswordResetRequested, AuditRecordType::PasswordReset, AuditRecordType::PasswordChanged => new GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::UserVerified => new UserVerifiedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->obfuscateEmail($record->attributes['email'], $record->attributes['user']['id']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::UserDeleted => new UserDeletedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::UsernameChanged => new UsernameChangedDisplay(
                $record->datetime,
                $record->attributes['username_from'],
                $record->attributes['username_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::EmailChanged => new EmailChangedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->obfuscateEmail($record->attributes['email_from'], $record->attributes['user']['id'] ?? null),
                $this->obfuscateEmail($record->attributes['email_to'], $record->attributes['user']['id'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::GitHubLinkedWithUser => new GitHubLinkedWithUserDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $record->attributes['github_username'],
                $record->attributes['github_id'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::GitHubDisconnectedFromUser => new GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::FilterListEntryAdded => new FilterListEntryAddedDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? $record->attributes['entry']['category'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditRecordType::FilterListEntryDeleted => new FilterListEntryDeletedDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? $record->attributes['entry']['category'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditRecordType::SecurityAdvisoryCreated => new SecurityAdvisoryCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['advisoryId'],
                $record->attributes['cve'] ?? null,
                $record->attributes['title'],
                $record->attributes['source'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditRecordType::SecurityAdvisoryEdited => new SecurityAdvisoryEditedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['advisoryId'],
                $record->attributes['cve'] ?? null,
                $record->attributes['title'],
                $record->attributes['source'],
                $record->attributes['changes'] ?? [],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditRecordType::SecurityAdvisoryWithdrawn => new SecurityAdvisoryWithdrawnDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['advisoryId'],
                $record->attributes['cve'] ?? null,
                $record->attributes['title'],
                $record->attributes['source'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditRecordType::OrganizationCreated => new OrganizationCreatedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::FilterListEntryDisabled => new FilterListEntryDisabledDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? null,
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditRecordType::FilterListEntryEnabled => new FilterListEntryEnabledDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? null,
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditRecordType::FilterListEntryEdited => new FilterListEntryEditedDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                $record->attributes['previous']['version'] ?? $record->attributes['entry']['version'],
                $record->attributes['entry']['reason'] ?? null,
                $record->attributes['previous']['reason'] ?? $record->attributes['entry']['reason'] ?? null,
                $record->attributes['entry']['link'] ?? null,
                $record->attributes['previous']['link'] ?? $record->attributes['entry']['link'] ?? null,
                $record->attributes['entry']['internal_note'] ?? null,
                $record->attributes['previous']['internal_note'] ?? null,
                FilterLists::from($record->attributes['entry']['list']),
                FilterLists::from($record->attributes['previous']['list'] ?? $record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditRecordType::OrganizationNameChanged => new OrganizationNameChangedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['org_name_from'],
                $record->attributes['org_name_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::OrganizationSlugChanged => new OrganizationSlugChangedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['org_slug_from'],
                $record->attributes['org_slug_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::OrganizationTeamCreated => new OrganizationTeamCreatedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::OrganizationTeamRenamed => new OrganizationTeamRenamedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name_from'],
                $record->attributes['team_name_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::OrganizationTeamDeleted => new OrganizationTeamDeletedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::OrganizationTeamMemberAdded => new OrganizationTeamMemberAddedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::OrganizationTeamMemberRemoved => new OrganizationTeamMemberRemovedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::OrganizationMemberRemoved => new OrganizationMemberRemovedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditRecordType::OrganizationMemberLeft => new OrganizationMemberLeftDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
        };
    }

    /**
     * @param array{id: int, username: string}|string|null $actor
     */
    private function buildActor(array|string|null $actor): ActorDisplay
    {
        if ($actor === null) {
            return new ActorDisplay(null, 'unknown');
        }

        if (\is_string($actor)) {
            return new ActorDisplay(null, $actor);
        }

        return new ActorDisplay($actor['id'], $actor['username']);
    }

    /**
     * Admin-only deletion reasons may contain PII, so only auditors (who can also see IPs/emails) see them.
     */
    private function internalReason(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return $this->security->isGranted('ROLE_AUDITOR') ? $reason : null;
    }

    private function obfuscateEmail(string $email, ?int $userId = null): string
    {
        if ($this->security->isGranted('ROLE_AUDITOR')) {
            return $email;
        }

        $currentUser = $this->security->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $userId) {
            return $email;
        }

        return '**@**.**';
    }
}

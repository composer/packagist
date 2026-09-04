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

namespace App\Log\Display;

use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\Log\AuditLogEventType;
use App\Log\Display\Event\CanonicalUrlChangedDisplay;
use App\Log\Display\Event\EmailChangedDisplay;
use App\Log\Display\Event\FilterListEntryAddedDisplay;
use App\Log\Display\Event\FilterListEntryDeletedDisplay;
use App\Log\Display\Event\FilterListEntryDisabledDisplay;
use App\Log\Display\Event\FilterListEntryEditedDisplay;
use App\Log\Display\Event\FilterListEntryEnabledDisplay;
use App\Log\Display\Event\GenericUserDisplay;
use App\Log\Display\Event\GitHubLinkedWithUserDisplay;
use App\Log\Display\Event\MaintainerAddedDisplay;
use App\Log\Display\Event\MaintainerRemovedDisplay;
use App\Log\Display\Event\OrganizationCreatedDisplay;
use App\Log\Display\Event\OrganizationInvitationDisplay;
use App\Log\Display\Event\OrganizationMemberJoinedDisplay;
use App\Log\Display\Event\OrganizationMemberLeftDisplay;
use App\Log\Display\Event\OrganizationMemberRemovedDisplay;
use App\Log\Display\Event\OrganizationNameChangedDisplay;
use App\Log\Display\Event\OrganizationSlugChangedDisplay;
use App\Log\Display\Event\OrganizationTeamCreatedDisplay;
use App\Log\Display\Event\OrganizationTeamDeletedDisplay;
use App\Log\Display\Event\OrganizationTeamMemberAddedDisplay;
use App\Log\Display\Event\OrganizationTeamMemberRemovedDisplay;
use App\Log\Display\Event\OrganizationTeamRenamedDisplay;
use App\Log\Display\Event\PackageAbandonedDisplay;
use App\Log\Display\Event\PackageCreatedDisplay;
use App\Log\Display\Event\PackageDeletedDisplay;
use App\Log\Display\Event\PackageFrozenDisplay;
use App\Log\Display\Event\PackageTransferredDisplay;
use App\Log\Display\Event\PackageUnabandonedDisplay;
use App\Log\Display\Event\PackageUnfrozenDisplay;
use App\Log\Display\Event\SecurityAdvisoryCreatedDisplay;
use App\Log\Display\Event\SecurityAdvisoryEditedDisplay;
use App\Log\Display\Event\SecurityAdvisoryWithdrawnDisplay;
use App\Log\Display\Event\TwoFaDeactivatedDisplay;
use App\Log\Display\Event\UserCreatedDisplay;
use App\Log\Display\Event\UserDeletedDisplay;
use App\Log\Display\Event\UserFreezeDisplay;
use App\Log\Display\Event\UsernameChangedDisplay;
use App\Log\Display\Event\UserVerifiedDisplay;
use App\Log\Display\Event\VersionCreatedDisplay;
use App\Log\Display\Event\VersionDeletedDisplay;
use App\Log\Display\Event\VersionRecoveredDisplay;
use App\Log\Display\Event\VersionReferenceChangeBlockedDisplay;
use App\Log\Display\Event\VersionSoftDeletedDisplay;
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
     * @return array<LogDisplayInterface>
     */
    public function build(iterable $auditRecords, bool $revealEmails = false): array
    {
        $displays = [];
        foreach ($auditRecords as $record) {
            $displays[] = $this->buildSingle($record, $revealEmails);
        }

        return $displays;
    }

    /**
     * $revealEmails skips obfuscation for viewers already authorized to see them (e.g. the
     * organization-internal audit log, gated by ViewAuditLog), unlike the public transparency log.
     */
    public function buildSingle(AuditRecord $record, bool $revealEmails = false): LogDisplayInterface
    {
        return match ($record->type) {
            AuditLogEventType::MaintainerAdded => new MaintainerAddedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::MaintainerRemoved => new MaintainerRemovedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageTransferred => new PackageTransferredDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['previous_maintainers'],
                $record->attributes['current_maintainers'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageCreated => new PackageCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                // absent on ordinary submissions and on every record predating moderator submissions
                isset($record->attributes['user']) ? $this->buildActor($record->attributes['user']) : null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageDeleted => new PackageDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['reason'] ?? null,
                $this->internalReason($record->attributes['internalReason'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::CanonicalUrlChanged => new CanonicalUrlChangedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository_from'],
                $record->attributes['repository_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::VersionCreated => new VersionCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['metadata']['source']['reference'] ?? null,
                $record->attributes['metadata']['dist']['reference'] ?? null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageAbandoned => new PackageAbandonedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['replacement_package'] ?? null,
                $record->attributes['reason'] ?? null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageUnabandoned => new PackageUnabandonedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageFrozen => new PackageFrozenDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['reason'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageUnfrozen => new PackageUnfrozenDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::VersionDeleted => new VersionDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::VersionReferenceChangeBlocked => new VersionReferenceChangeBlockedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['ref_from'] ?? null,
                $record->attributes['ref_to'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::VersionSoftDeleted => new VersionSoftDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['reason'],
                $record->attributes['reasonText'] ?? null,
                $this->internalReason($record->attributes['internalReasonText'] ?? null),
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::VersionRecovered => new VersionRecoveredDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['previousReason'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::UserCreated => new UserCreatedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                UserRegistrationMethod::from($record->attributes['method']),
                $this->buildActor('self'),
                $record->ip,
            ),
            AuditLogEventType::TwoFaAuthenticationActivated => new GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::TwoFaAuthenticationDeactivated => new TwoFaDeactivatedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $record->attributes['reason'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PasswordResetRequested, AuditLogEventType::PasswordReset, AuditLogEventType::PasswordChanged => new GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UserVerified => new UserVerifiedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->obfuscateEmail($record->attributes['email'], $record->attributes['user']['id']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UserDeleted => new UserDeletedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UserFrozen => new UserFreezeDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $record->attributes['reason'] ?? null,
                $record->attributes['reasonText'] ?? null,
                $this->internalReason($record->attributes['internalReason'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UserUnfrozen => new UserFreezeDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                null,
                $record->attributes['reasonText'] ?? null,
                $this->internalReason($record->attributes['internalReason'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UsernameChanged => new UsernameChangedDisplay(
                $record->datetime,
                $record->attributes['username_from'],
                $record->attributes['username_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::EmailChanged => new EmailChangedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->obfuscateEmail($record->attributes['email_from'], $record->attributes['user']['id'] ?? null),
                $this->obfuscateEmail($record->attributes['email_to'], $record->attributes['user']['id'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::GitHubLinkedWithUser => new GitHubLinkedWithUserDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $record->attributes['github_username'],
                $record->attributes['github_id'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::GitHubDisconnectedFromUser => new GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::FilterListEntryAdded => new FilterListEntryAddedDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? $record->attributes['entry']['category'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditLogEventType::FilterListEntryDeleted => new FilterListEntryDeletedDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? $record->attributes['entry']['category'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditLogEventType::SecurityAdvisoryCreated => new SecurityAdvisoryCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['advisoryId'],
                $record->attributes['cve'] ?? null,
                $record->attributes['title'],
                $record->attributes['source'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::SecurityAdvisoryEdited => new SecurityAdvisoryEditedDisplay(
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
            AuditLogEventType::SecurityAdvisoryWithdrawn => new SecurityAdvisoryWithdrawnDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['advisoryId'],
                $record->attributes['cve'] ?? null,
                $record->attributes['title'],
                $record->attributes['source'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::OrganizationCreated => new OrganizationCreatedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::FilterListEntryDisabled => new FilterListEntryDisabledDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? null,
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditLogEventType::FilterListEntryEnabled => new FilterListEntryEnabledDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? null,
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditLogEventType::FilterListEntryEdited => new FilterListEntryEditedDisplay(
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
            AuditLogEventType::OrganizationNameChanged => new OrganizationNameChangedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['org_name_from'],
                $record->attributes['org_name_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationSlugChanged => new OrganizationSlugChangedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['org_slug_from'],
                $record->attributes['org_slug_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamCreated => new OrganizationTeamCreatedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamRenamed => new OrganizationTeamRenamedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name_from'],
                $record->attributes['team_name_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamDeleted => new OrganizationTeamDeletedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamMemberAdded => new OrganizationTeamMemberAddedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamMemberRemoved => new OrganizationTeamMemberRemovedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationMemberJoined => new OrganizationMemberJoinedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationMemberRemoved => new OrganizationMemberRemovedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationMemberLeft => new OrganizationMemberLeftDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationInvitationSent,
            AuditLogEventType::OrganizationInvitationResent,
            AuditLogEventType::OrganizationInvitationRevoked,
            AuditLogEventType::OrganizationInvitationDeclined,
            AuditLogEventType::OrganizationInvitationAccepted,
            AuditLogEventType::OrganizationInvitationExpired => new OrganizationInvitationDisplay(
                $record->type,
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->obfuscateEmail($record->attributes['email'], revealEmails: $revealEmails),
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

    private function obfuscateEmail(string $email, ?int $userId = null, bool $revealEmails = false): string
    {
        if ($revealEmails || $this->security->isGranted('ROLE_AUDITOR')) {
            return $email;
        }

        $currentUser = $this->security->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $userId) {
            return $email;
        }

        return '**@**.**';
    }
}

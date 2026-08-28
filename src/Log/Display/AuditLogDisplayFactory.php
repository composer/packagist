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
use Symfony\Bundle\SecurityBundle\Security;

class AuditLogDisplayFactory extends AbstractLogDisplayFactory
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
            AuditLogEventType::MaintainerAdded, AuditLogEventType::MaintainerRemoved => new Event\MaintainerChangeDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageTransferred => new Event\PackageTransferredDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['previous_maintainers'],
                $record->attributes['current_maintainers'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageCreated, AuditLogEventType::PackageUnabandoned, AuditLogEventType::PackageUnfrozen => new Event\PackageRepositoryDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                // absent on ordinary submissions and on every record predating moderator submissions
                isset($record->attributes['user']) ? $this->buildActor($record->attributes['user']) : null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageDeleted => new Event\PackageDeletedDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['reason'] ?? null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
                $this->internalReason($record->attributes['internalReason'] ?? null),
            ),
            AuditLogEventType::CanonicalUrlChanged => new Event\CanonicalUrlChangedDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository_from'],
                $record->attributes['repository_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::VersionCreated => new Event\VersionCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['metadata']['source']['reference'] ?? null,
                $record->attributes['metadata']['dist']['reference'] ?? null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageAbandoned => new Event\PackageAbandonedDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['replacement_package'] ?? null,
                $record->attributes['reason'] ?? null,
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PackageFrozen => new Event\PackageFrozenDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['reason'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::VersionDeleted => new Event\VersionDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::VersionReferenceChangeBlocked => new Event\VersionReferenceChangeBlockedDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['ref_from'] ?? null,
                $record->attributes['ref_to'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::VersionSoftDeleted => new Event\VersionSoftDeletedDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['reason'],
                $record->attributes['reasonText'] ?? null,
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
                $this->internalReason($record->attributes['internalReasonText'] ?? null),
            ),
            AuditLogEventType::VersionRecovered => new Event\VersionRecoveredDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['previousReason'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::UserCreated => new Event\UserCreatedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                UserRegistrationMethod::from($record->attributes['method']),
                $this->buildActor('self'),
                $record->ip,
            ),
            AuditLogEventType::TwoFactorAuthenticationActivated => new Event\GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::TwoFactorAuthenticationDeactivated => new Event\TwoFaDeactivatedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $record->attributes['reason'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::PasswordResetRequested, AuditLogEventType::PasswordReset, AuditLogEventType::PasswordChanged => new Event\GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UserVerified => new Event\UserVerifiedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->obfuscateEmail($record->attributes['email'], $record->attributes['user']['id']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UserDeleted => new Event\UserDeletedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UserFrozen => new Event\UserFreezeDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $record->attributes['reason'] ?? null,
                $record->attributes['reasonText'] ?? null,
                $this->internalReason($record->attributes['internalReason'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UserUnfrozen => new Event\UserFreezeDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                null,
                $record->attributes['reasonText'] ?? null,
                $this->internalReason($record->attributes['internalReason'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::UsernameChanged => new Event\UsernameChangedDisplay(
                $record->datetime,
                $record->attributes['username_from'],
                $record->attributes['username_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::EmailChanged => new Event\EmailChangedDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $this->obfuscateEmail($record->attributes['email_from'], $record->attributes['user']['id'] ?? null),
                $this->obfuscateEmail($record->attributes['email_to'], $record->attributes['user']['id'] ?? null),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::GitHubLinkedWithUser => new Event\GitHubLinkedWithUserDisplay(
                $record->datetime,
                $record->attributes['user']['username'],
                $record->attributes['github_username'],
                $record->attributes['github_id'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::GitHubDisconnectedFromUser => new Event\GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::FilterListEntryAdded => new Event\FilterListEntryAddedDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? $record->attributes['entry']['category'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditLogEventType::FilterListEntryDeleted => new Event\FilterListEntryDeletedDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? $record->attributes['entry']['category'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditLogEventType::SecurityAdvisoryCreated => new Event\SecurityAdvisoryCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['advisoryId'],
                $record->attributes['cve'] ?? null,
                $record->attributes['title'],
                $record->attributes['source'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::SecurityAdvisoryEdited => new Event\SecurityAdvisoryEditedDisplay(
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
            AuditLogEventType::SecurityAdvisoryWithdrawn => new Event\SecurityAdvisoryWithdrawnDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['advisoryId'],
                $record->attributes['cve'] ?? null,
                $record->attributes['title'],
                $record->attributes['source'],
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip,
            ),
            AuditLogEventType::OrganizationCreated => new Event\OrganizationCreatedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::FilterListEntryDisabled => new Event\FilterListEntryDisabledDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? null,
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditLogEventType::FilterListEntryEnabled => new Event\FilterListEntryEnabledDisplay(
                $record->datetime,
                $record->attributes['entry']['package_name'],
                $record->attributes['entry']['version'],
                FilterLists::from($record->attributes['entry']['list']),
                FilterSources::from($record->attributes['entry']['source']),
                $record->attributes['entry']['reason'] ?? null,
                $this->buildActor($record->attributes['actor'] ?? null),
                $record->ip
            ),
            AuditLogEventType::FilterListEntryEdited => new Event\FilterListEntryEditedDisplay(
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
            AuditLogEventType::OrganizationNameChanged => new Event\OrganizationNameChangedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['org_name_from'],
                $record->attributes['org_name_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationSlugChanged => new Event\OrganizationSlugChangedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['org_slug_from'],
                $record->attributes['org_slug_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamCreated => new Event\OrganizationTeamCreatedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamRenamed => new Event\OrganizationTeamRenamedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name_from'],
                $record->attributes['team_name_to'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamDeleted => new Event\OrganizationTeamDeletedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamMemberAdded => new Event\OrganizationTeamMemberAddedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationTeamMemberRemoved => new Event\OrganizationTeamMemberRemovedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $record->attributes['team_name'],
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationMemberJoined => new Event\OrganizationMemberJoinedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationMemberRemoved => new Event\OrganizationMemberRemovedDisplay(
                $record->datetime,
                OrganizationDisplay::fromRecord($record->attributes['organization']),
                $this->buildActor($record->attributes['user']),
                $this->buildActor($record->attributes['actor']),
                $record->ip,
            ),
            AuditLogEventType::OrganizationMemberLeft => new Event\OrganizationMemberLeftDisplay(
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
            AuditLogEventType::OrganizationInvitationExpired => new Event\OrganizationInvitationDisplay(
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

-- Organizations: membership invitations.
-- Read-model projections for the invitation aggregate (organization_invitation and its
-- companion organization_invitation_team) plus the org-level membership record
-- (organization_member) introduced with the invitation-acceptance flow. Column names match
-- the Doctrine entity mappings so a doctrine:schema:create produces the same schema.
--
-- The invitation aggregate is a separate ULID stream persisted in the shared organization_event
-- table; no new event table is needed. Pre-membership invitation events (sent/resent/revoked/
-- declined/expired) stay internal (event stream + these read models) and never reach audit_log.

CREATE TABLE organization_invitation (
    id BINARY(16) NOT NULL,
    orgId BINARY(16) NOT NULL,
    email VARCHAR(255) NOT NULL,
    emailCanonical VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL,
    -- SHA-256 hex of the single-use link token. Only the hash is stored; the raw token lives
    -- solely in the emailed link and is never persisted, here or in the event stream.
    tokenHash CHAR(64) NOT NULL,
    createdAt DATETIME NOT NULL,
    expiresAt DATETIME NOT NULL,
    lastSentAt DATETIME NOT NULL,
    resolvedAt DATETIME DEFAULT NULL,
    invitedByUserId INT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY org_invitation_org_idx (orgId),
    KEY org_invitation_pending_idx (orgId, emailCanonical, status),
    KEY org_invitation_expiry_idx (status, expiresAt),
    CONSTRAINT FK_organization_invitation_org FOREIGN KEY (orgId) REFERENCES organization (id) ON DELETE CASCADE,
    CONSTRAINT FK_organization_invitation_invited_by FOREIGN KEY (invitedByUserId) REFERENCES fos_user (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

-- Target teams the invitee joins on acceptance. A many-to-one companion to the invitation.
-- Intentionally has NO foreign key to organization_team: a team may be deleted after the
-- invitation is sent, and the historical target set must be preserved so acceptance can detect
-- "target team no longer exists" rather than silently losing the row to a cascade.
CREATE TABLE organization_invitation_team (
    invitationId BINARY(16) NOT NULL,
    teamId BINARY(16) NOT NULL,
    PRIMARY KEY (invitationId, teamId),
    CONSTRAINT FK_organization_invitation_team_invitation FOREIGN KEY (invitationId) REFERENCES organization_invitation (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

-- Org-level membership record. A member's team memberships (organization_team_member) still drive
-- access; this row carries the org-scoped facts that are not derivable from team rows (joinedAt).
CREATE TABLE organization_member (
    orgId BINARY(16) NOT NULL,
    userId INT NOT NULL,
    joinedAt DATETIME NOT NULL,
    PRIMARY KEY (orgId, userId),
    KEY org_member_user_idx (userId),
    CONSTRAINT FK_organization_member_org FOREIGN KEY (orgId) REFERENCES organization (id) ON DELETE CASCADE,
    CONSTRAINT FK_organization_member_user FOREIGN KEY (userId) REFERENCES fos_user (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

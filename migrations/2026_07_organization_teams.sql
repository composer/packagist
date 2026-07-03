-- Organizations: teams & member management.
-- Read-model projections for teams (organization_team) and their memberships
-- (organization_team_member), plus the bootstrapped owners team reference on the
-- organization row. Column names match the Doctrine entity mappings so a
-- doctrine:schema:create produces the same schema.
--
-- NOTE: the organization-created event payload gained ownersTeamId + creatorUserId
-- in this stage, so any pre-existing dev event data must be discarded:
--   DELETE FROM organization_event; DELETE FROM organization; DELETE FROM slug_reservation;

CREATE TABLE organization_team (
    teamId BINARY(16) NOT NULL,
    orgId BINARY(16) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    name VARCHAR(40) NOT NULL,
    nameLower VARCHAR(40) GENERATED ALWAYS AS (LOWER(name)) STORED,
    createdBy INT DEFAULT NULL,
    createdAt DATETIME NOT NULL,
    PRIMARY KEY (teamId),
    UNIQUE KEY org_team_name_uniq (orgId, nameLower),
    KEY org_team_org_idx (orgId),
    CONSTRAINT FK_organization_team_created_by FOREIGN KEY (createdBy) REFERENCES fos_user (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

CREATE TABLE organization_team_member (
    teamId BINARY(16) NOT NULL,
    userId INT NOT NULL,
    orgId BINARY(16) NOT NULL,
    addedBy INT DEFAULT NULL,
    addedAt DATETIME NOT NULL,
    PRIMARY KEY (teamId, userId),
    KEY org_team_member_org_user_idx (orgId, userId),
    KEY org_team_member_user_idx (userId),
    KEY org_team_member_team_idx (teamId),
    CONSTRAINT FK_organization_team_member_user FOREIGN KEY (userId) REFERENCES fos_user (id) ON DELETE CASCADE,
    CONSTRAINT FK_organization_team_member_added_by FOREIGN KEY (addedBy) REFERENCES fos_user (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

ALTER TABLE organization ADD ownersTeamId BINARY(16) DEFAULT NULL;

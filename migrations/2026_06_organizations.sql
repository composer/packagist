-- Organizations: foundation + creation.
-- Canonical event stream (organization_event) plus its read-model projections
-- (organization, slug_reservation). Column names match the Doctrine entity mappings
-- so a doctrine:schema:create produces the same schema.

CREATE TABLE organization (
    id BINARY(16) NOT NULL,
    slug VARCHAR(20) NOT NULL,
    displayName VARCHAR(60) NOT NULL,
    status VARCHAR(16) NOT NULL,
    createdAt DATETIME NOT NULL,
    createdBy INT DEFAULT NULL,
    deletedAt DATETIME DEFAULT NULL,
    deletedReason VARCHAR(32) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY org_slug_idx (slug),
    KEY org_created_by_idx (createdBy)
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

CREATE TABLE organization_event (
    id BINARY(16) NOT NULL,
    aggregateId BINARY(16) NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    type VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    actorUserId INT DEFAULT NULL,
    actorLabel VARCHAR(32) NOT NULL,
    actorRoleInOrg VARCHAR(32) DEFAULT NULL,
    createdAt DATETIME NOT NULL,
    ip VARBINARY(16) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY org_event_seq_idx (aggregateId, sequence),
    KEY org_event_actor_idx (actorUserId, createdAt)
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

CREATE TABLE slug_reservation (
    id BINARY(16) NOT NULL,
    slug VARCHAR(20) NOT NULL,
    orgId BINARY(16) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    reservedAt DATETIME NOT NULL,
    releasedAt DATETIME DEFAULT NULL,
    releasedBy INT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY slug_reservation_slug_idx (slug),
    KEY slug_reservation_org_idx (orgId)
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

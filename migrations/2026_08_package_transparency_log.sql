-- Public, per-package, append-only transparency log projected asynchronously from audit_log by
-- `bin/console packagist:project-transparency-log`.

CREATE TABLE package_transparency_log (
    id BINARY(16) NOT NULL,
    sourceAuditLogId BINARY(16) NOT NULL,
    leafIndex INT UNSIGNED NOT NULL,
    type VARCHAR(32) NOT NULL,
    attributes JSON NOT NULL,
    datetime DATETIME NOT NULL,
    actorId INT DEFAULT NULL,
    vendor VARCHAR(255) DEFAULT NULL,
    packageId INT DEFAULT NULL,
    userId INT DEFAULT NULL,
    organizationId BINARY(16) DEFAULT NULL,
    leafHash VARBINARY(32) DEFAULT NULL,
    INDEX package_idx (packageId),
    INDEX vendor_idx (vendor),
    UNIQUE INDEX source_package_uniq (sourceAuditLogId, packageId),
    UNIQUE INDEX leaf_index_uniq (leafIndex),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

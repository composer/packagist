-- Public, per-package, append-only transparency log, and the queue the projector reads from.
-- `bin/console packagist:project-transparency-log` publishes the queued records and dequeues them.
--
-- A queue row is written in the same transaction as its audit_log row, and deleted in the same
-- transaction as the entries projected from it. audit_log.id is a ULID assigned when the record is
-- built in PHP, not when its transaction commits, so a long-running transaction can commit a row
-- whose id is lower than rows committed before it. That row still has its queue row when it is
-- committed, so the projector picks it up then.
--
-- leafIndex is the order the projector inserted the entries into package_transparency_log, not the
-- order the events happened. A row committed to the audit_log after rows with newer timestamps were already projected is
-- appended at the end of the package_transparency_log.
--
-- packageId is NOT NULL because MySQL treats NULLs as distinct: (sourceAuditLogId, NULL) would not
-- collide in source_package_uniq, so the same event could be inserted twice.

CREATE TABLE package_transparency_log (
    id BINARY(16) NOT NULL,
    sourceAuditLogId BINARY(16) NOT NULL,
    leafIndex INT UNSIGNED NOT NULL,
    type VARCHAR(64) NOT NULL,
    attributes JSON NOT NULL,
    datetime DATETIME NOT NULL,
    actorId INT DEFAULT NULL,
    vendor VARCHAR(255) DEFAULT NULL,
    packageId INT NOT NULL,
    packageName VARCHAR(255) NOT NULL,
    userId INT DEFAULT NULL,
    organizationId BINARY(16) DEFAULT NULL,
    leafHash VARBINARY(32) DEFAULT NULL,
    INDEX package_leaf_idx (packageId, leafIndex),
    INDEX vendor_leaf_idx (vendor, leafIndex),
    INDEX user_leaf_idx (userId, leafIndex),
    INDEX type_leaf_idx (type, leafIndex),
    INDEX datetime_idx (datetime),
    UNIQUE INDEX source_package_uniq (sourceAuditLogId, packageId),
    UNIQUE INDEX leaf_index_uniq (leafIndex),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

-- One row per audit record still waiting to be projected. The projector reads it in auditLogId order
-- and deletes by primary key, so it needs no other columns or indexes.
CREATE TABLE package_transparency_log_queue (
    auditLogId BINARY(16) NOT NULL,
    PRIMARY KEY (auditLogId)
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

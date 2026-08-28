-- Security advisories are no longer deleted when withdrawn at the source (GitHub) or removed from
-- the FriendsOfPHP repository; instead they are kept and marked with a withdrawnAt timestamp so
-- users can still look up advisories they previously encountered (e.g. in composer audit output).

ALTER TABLE security_advisory ADD withdrawnAt DATETIME DEFAULT NULL AFTER severity;

CREATE INDEX withdrawn_at_idx ON security_advisory (withdrawnAt);

ALTER TABLE security_advisory DROP INDEX package_name_cve_idx;
ALTER TABLE security_advisory
    ADD COLUMN activeCve VARCHAR(255) GENERATED ALWAYS AS (IF(withdrawnAt IS NULL, cve, NULL)) VIRTUAL AFTER withdrawnAt;
ALTER TABLE security_advisory ADD UNIQUE INDEX package_name_cve_idx (packageName, activeCve);

-- Advisories that already lost every source row were effectively deleted under the old model
-- (the worker hard-deleted them). Nothing removes them any more, so flag the leftovers as
-- withdrawn to keep them out of the active composer audit / API listings.
UPDATE security_advisory sa
    LEFT JOIN security_advisory_source s ON s.securityAdvisory_id = sa.id
    SET sa.withdrawnAt = NOW()
    WHERE sa.withdrawnAt IS NULL AND s.securityAdvisory_id IS NULL;

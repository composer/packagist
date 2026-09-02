-- Security advisories are no longer deleted when withdrawn at the source (GitHub) or removed from
-- the FriendsOfPHP repository; instead they are kept and marked with a withdrawnAt timestamp so
-- users can still look up advisories they previously encountered (e.g. in composer audit output).
--
-- Stop the security advisory worker before applying this and start it again afterwards: these are
-- auto-committing DDL statements, and the uniqueness of (packageName, cve) is handed over from one
-- index to another below. The new index is created before the old one is dropped so there is never
-- a moment without protection, but a worker run in the middle can still leave the table with only
-- one of the two.

ALTER TABLE security_advisory ADD withdrawnAt DATETIME DEFAULT NULL AFTER severity;

CREATE INDEX withdrawn_at_idx ON security_advisory (withdrawnAt);

-- PHP treats a malformed CVE as none (SecurityAdvisory::getCve()); make the stored value agree so
-- the generated column below keys on the same thing.
UPDATE security_advisory SET cve = NULL WHERE cve IS NOT NULL AND cve NOT REGEXP '^CVE-[0-9]{4}-[0-9]{4,}$';

ALTER TABLE security_advisory
    ADD COLUMN activeCve VARCHAR(255) GENERATED ALWAYS AS (IF(withdrawnAt IS NULL, cve, NULL)) VIRTUAL AFTER withdrawnAt;
ALTER TABLE security_advisory ADD UNIQUE INDEX package_name_active_cve_idx (packageName, activeCve);
ALTER TABLE security_advisory DROP INDEX package_name_cve_idx;

-- Advisories that already lost every source row were effectively deleted under the old model
-- (the worker hard-deleted them). Nothing removes them any more, so flag the leftovers as
-- withdrawn to keep them out of the active composer audit / API listings. updatedAt is the last
-- time anything touched them, which is the closest thing to a withdrawal date they have.
UPDATE security_advisory sa
    LEFT JOIN security_advisory_source s ON s.securityAdvisory_id = sa.id
    SET sa.withdrawnAt = sa.updatedAt
    WHERE sa.withdrawnAt IS NULL AND s.securityAdvisory_id IS NULL;

-- Sources keep their row when they stop listing an advisory, so the fact that it once came from
-- them (and when it went away) is never lost, and an advisory withdrawn in one source but still
-- listed by another can be displayed as such.
ALTER TABLE security_advisory_source ADD withdrawnAt DATETIME DEFAULT NULL;

UPDATE security_advisory_source sas
    INNER JOIN security_advisory sa ON sa.id = sas.securityAdvisory_id
    SET sas.withdrawnAt = sa.withdrawnAt
    WHERE sa.withdrawnAt IS NOT NULL;

-- reportedAt is the main source's date; other sources' rows are filled in by their next run.
ALTER TABLE security_advisory_source ADD publishedAt DATETIME DEFAULT NULL;

UPDATE security_advisory_source sas
    INNER JOIN security_advisory sa ON sa.id = sas.securityAdvisory_id AND sa.source = sas.source
    SET sas.publishedAt = sa.reportedAt;

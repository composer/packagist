-- Security advisories are no longer deleted when withdrawn at the source (GitHub) or removed from
-- the FriendsOfPHP repository; instead they are kept and marked with a withdrawnAt timestamp so
-- users can still look up advisories they previously encountered (e.g. in composer audit output).

ALTER TABLE security_advisory ADD withdrawnAt DATETIME DEFAULT NULL AFTER severity;

CREATE INDEX withdrawn_at_idx ON security_advisory (withdrawnAt);

ALTER TABLE security_advisory DROP INDEX package_name_cve_idx;
ALTER TABLE security_advisory
    ADD COLUMN activeCve VARCHAR(255) GENERATED ALWAYS AS (IF(withdrawnAt IS NULL, cve, NULL)) VIRTUAL AFTER withdrawnAt;
ALTER TABLE security_advisory ADD UNIQUE INDEX package_name_cve_idx (packageName, activeCve);

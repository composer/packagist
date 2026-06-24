-- Admin-controlled account freeze, mirroring the package freeze (see src/Entity/UserFreezeReason.php).
-- NULL = not frozen. A frozen account cannot log in, reset its password, authenticate via API token,
-- or be used as a maintainer; only an admin can unfreeze it.
--
-- This also retires the old ROLE_SPAMMER, which was abused as a disabled flag: existing spammers are
-- migrated to a `spam` freeze, and the now-unused role is stripped from the roles JSON.

ALTER TABLE fos_user ADD frozen VARCHAR(255) DEFAULT NULL AFTER enabled;

UPDATE fos_user SET frozen = 'spam' WHERE JSON_CONTAINS(roles, '"ROLE_SPAMMER"');

UPDATE fos_user SET roles = JSON_REMOVE(roles, JSON_UNQUOTE(JSON_SEARCH(roles, 'one', 'ROLE_SPAMMER')))
    WHERE JSON_CONTAINS(roles, '"ROLE_SPAMMER"');

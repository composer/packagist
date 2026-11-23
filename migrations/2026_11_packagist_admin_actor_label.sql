-- Rename the 'admin' actor label to 'packagist-admin' in existing log entries.
-- It was written when a package was transferred, frozen or unfrozen from the CLI, where no user is
-- logged in. New entries use 'packagist-admin', which cannot be mistaken for an organization role.
--
-- Only the string label is matched: a user called "admin" is stored as an {id, username} object.
-- The search tables only index real users, so they need no update.
-- Safe to run again, in case entries were written by the old code after the first run.

UPDATE audit_log
SET attributes = JSON_SET(attributes, '$.actor', 'packagist-admin')
WHERE type IN ('package_transferred', 'package_frozen', 'package_unfrozen')
    AND attributes->>'$.actor' = 'admin';

UPDATE package_transparency_log
SET attributes = JSON_SET(attributes, '$.actor', 'packagist-admin')
WHERE type IN ('package_transferred', 'package_frozen', 'package_unfrozen')
    AND attributes->>'$.actor' = 'admin';

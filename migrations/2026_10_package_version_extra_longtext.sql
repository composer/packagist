-- package_version.extra was a native JSON column, and MySQL stores JSON objects with their keys
-- sorted by length, then bytes. Some extra data is order-sensitive (composer-patches applies
-- extra.patches in key order), so it is now plain text and keeps the order from composer.json.
-- See https://github.com/composer/packagist/issues/1923
--
-- Existing rows keep MySQL's key order: dev versions are fixed on their next commit, stable ones
-- are immutable and stay as they are.
--
-- Changing the column type needs ALGORITHM=COPY, which blocks writes for the whole table rebuild,
-- so run it with an online schema change tool or during a maintenance window.
ALTER TABLE package_version
    MODIFY extra LONGTEXT NOT NULL;

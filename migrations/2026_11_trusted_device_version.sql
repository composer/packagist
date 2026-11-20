-- Share the account session version with trusted-device cookies and stop wrapping it, so revoked
-- cookies cannot become valid again. Widen the column before deploying the code.
ALTER TABLE fos_user MODIFY sessionBuster INT UNSIGNED NOT NULL DEFAULT 0;

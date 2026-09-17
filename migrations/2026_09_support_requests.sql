-- Support requests: the admin task queue behind the public /contact workflows (package transfer,
-- vendor-name claim, account deletion) and the lost-2FA request raised from the two-factor login
-- prompt.
--
-- One table with a type discriminator rather than one table per type: the four types share their
-- entire lifecycle (open -> resolved/closed, internal notes, replies, admin alert mail), and what
-- only one type needs lives in the `attributes` JSON blob rather than in a nullable column per type,
-- so adding a workflow is a new value object rather than a migration. Same shape as audit_log.
-- The queue's search reaches into it with JSON_EXTRACT, which is already registered as a DQL
-- function; nothing filters on it often enough to want a generated column.
--
-- openMarker is a generated column holding 1 while the request is open and NULL otherwise. Combined
-- with support_request_open_uniq it makes "at most one open request per user and type" a database
-- invariant, so a resubmit -- a user hammering the lost-2FA link from the 2FA prompt -- can neither
-- create a duplicate row nor trigger a second admin notification. Same trick as
-- slug_reservation.activeSlug.
--
-- userId is ON DELETE CASCADE rather than the SET NULL used by other fos_user references: a request
-- without an account is moot, and cascading avoids leaving the deleted user's own free text and IP
-- behind. The decisions themselves survive in audit_log (two_fa_deactivated, package_transferred,
-- user_deleted).
--
-- Request bodies, admin replies and internal notes deliberately live here and never in audit_log,
-- which every logged-in user can read via /transparency-log.

CREATE TABLE support_request (
    id BINARY(16) NOT NULL,
    -- human reference (PKSR-xxxx-xxxx-xxxx), quoted in admin mail, user mail and admin routes
    publicId VARCHAR(20) NOT NULL,
    type VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL,
    -- free text the requester typed, common to every type
    description LONGTEXT DEFAULT NULL,
    -- type-specific payload, shaped by App\Support\Attributes\*:
    --   lost_2fa          {cancelTokenHash, approvableAt}
    --   package_transfer  {packageNames}
    --   vendor_claim      {vendorName}
    --   account_deletion  {packageDisposition, transferTo}
    attributes JSON NOT NULL,
    -- lost-2FA requests only, where it is a fraud signal. A column, not an attribute, because it is
    -- request metadata rather than payload and VARBINARY(16) keeps the ipaddress Doctrine type.
    ip VARBINARY(16) DEFAULT NULL,
    createdAt DATETIME NOT NULL,
    updatedAt DATETIME NOT NULL,
    resolvedAt DATETIME DEFAULT NULL,
    openMarker TINYINT(1) GENERATED ALWAYS AS (IF(status = 'open', 1, NULL)) STORED,
    userId INT NOT NULL,
    INDEX IDX_86A2876364B64DCC (userId),
    INDEX support_request_queue_idx (status, createdAt),
    INDEX support_request_type_idx (type),
    UNIQUE INDEX support_request_public_id_uniq (publicId),
    UNIQUE INDEX support_request_open_uniq (userId, type, openMarker),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- Internal admin notes and replies sent to the requester, on one thread, told apart by `internal`.
-- Internal notes must never be surfaced to the requester or copied into audit_log.
CREATE TABLE support_request_message (
    id BINARY(16) NOT NULL,
    createdAt DATETIME NOT NULL,
    internal TINYINT NOT NULL,
    contents LONGTEXT NOT NULL,
    requestId BINARY(16) NOT NULL,
    authorId INT DEFAULT NULL,
    INDEX IDX_BB5257F1A1637001 (requestId),
    INDEX IDX_BB5257F1A196F9FD (authorId),
    INDEX support_request_message_thread_idx (requestId, createdAt),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

ALTER TABLE support_request ADD CONSTRAINT FK_86A2876364B64DCC FOREIGN KEY (userId) REFERENCES fos_user (id) ON DELETE CASCADE;
ALTER TABLE support_request_message ADD CONSTRAINT FK_BB5257F1A1637001 FOREIGN KEY (requestId) REFERENCES support_request (id) ON DELETE CASCADE;
ALTER TABLE support_request_message ADD CONSTRAINT FK_BB5257F1A196F9FD FOREIGN KEY (authorId) REFERENCES fos_user (id) ON DELETE SET NULL;

-- Backfill userId on historical two-factor deactivations. AuditRecord::twoFactorAuthenticationDeactivated()
-- recorded only actorId until this change, so a userId-filtered query -- which is what the support
-- queue's risk panel runs to show an admin the account's prior 2FA history -- missed every one of
-- them. The affected user's id is already in the attributes blob, so this keys on that rather than
-- matching usernames, which drift on rename.
--
-- Additive and internal: /transparency-log excludes both 2FA types unconditionally, and its user and
-- actor filters resolve through audit_log_search, not this column.
UPDATE audit_log
SET userId = JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.user.id'))
WHERE type = 'two_fa_deactivated'
  AND userId IS NULL
  AND JSON_EXTRACT(attributes, '$.user.id') IS NOT NULL;

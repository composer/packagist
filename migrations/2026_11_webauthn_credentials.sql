-- Store multiple passkeys and security keys per user, without requiring attestation.
-- Keep revoked credential IDs reserved and use a random, stable WebAuthn user handle.
ALTER TABLE fos_user ADD webAuthnUserHandle VARCHAR(64) DEFAULT NULL;

UPDATE fos_user
    SET webAuthnUserHandle = LOWER(HEX(RANDOM_BYTES(32)))
    WHERE webAuthnUserHandle IS NULL;

ALTER TABLE fos_user MODIFY COLUMN webAuthnUserHandle VARCHAR(64) NOT NULL,
    ADD UNIQUE INDEX user_webauthn_handle_idx (webAuthnUserHandle);

CREATE TABLE webauthn_credential (
    id VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    publicKeyCredentialId LONGTEXT NOT NULL,
    type VARCHAR(255) NOT NULL,
    transports JSON NOT NULL,
    attestationType VARCHAR(255) NOT NULL,
    trustPath JSON NOT NULL,
    aaguid TINYTEXT NOT NULL,
    credentialPublicKey LONGTEXT NOT NULL,
    userHandle VARCHAR(255) NOT NULL,
    counter INT UNSIGNED NOT NULL,
    otherUI JSON DEFAULT NULL,
    backupEligible TINYINT DEFAULT NULL,
    backupStatus TINYINT DEFAULT NULL,
    uvInitialized TINYINT DEFAULT NULL,
    createdAt DATETIME NOT NULL,
    lastUsedAt DATETIME DEFAULT NULL,
    revokedAt DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX webauthn_user_idx (user_id),
    CONSTRAINT webauthn_user_fk FOREIGN KEY (user_id) REFERENCES fos_user (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

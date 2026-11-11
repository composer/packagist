-- Retain the model verified through FIDO attestation at enrollment
-- Existing passkeys remain ordinary second factors
ALTER TABLE webauthn_credential ADD credentialType VARCHAR(32) NOT NULL DEFAULT 'regular',
    ADD authenticatorDescription LONGTEXT DEFAULT NULL;

-- Share verified metadata between app servers and look up individual models by AAGUID
-- The importer replaces entries and feed state together in one transaction
CREATE TABLE fido_metadata_feed (
    sequenceNumber INT NOT NULL,
    nextUpdate DATETIME NOT NULL,
    importedAt DATETIME NOT NULL,
    PRIMARY KEY (sequenceNumber)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE fido_metadata_entry (
    aaguid VARCHAR(36) NOT NULL,
    statement JSON NOT NULL,
    statusReports JSON NOT NULL,
    PRIMARY KEY (aaguid)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

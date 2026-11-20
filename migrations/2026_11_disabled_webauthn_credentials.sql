-- Retain credentials rejected by current FIDO metadata as enrolled factors, so disabling the last
-- usable key cannot permit password-only login. Run before deploying the code.
ALTER TABLE webauthn_credential ADD disabledAt DATETIME DEFAULT NULL;

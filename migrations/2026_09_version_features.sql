-- Composer's feature system (composer/composer#12354) adds two composer.json keys that we store
-- verbatim per version and dump back into the package metadata: `features`, the optional dependency
-- groups a package offers, and `require-features`, the features it asks for from its own
-- dependencies (honoured transitively by the solver, so it has to survive the round-trip).
--
-- Both are nullable: NULL means the version declared neither, which is the case for every existing
-- row, so no backfill is needed.

ALTER TABLE package_version
    ADD features JSON DEFAULT NULL AFTER phpExt,
    ADD requireFeatures JSON DEFAULT NULL AFTER features;

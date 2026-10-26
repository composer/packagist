-- Supply chain alerts (typosquats, obfuscated code, telemetry, protestware) that Socket reports for
-- Composer package versions. They are only shown on package pages: unlike the malware filter list
-- they never reach the Composer metadata, so they cannot block installs.
--
-- packagist:socket-supply-chain-alerts replaces the whole table on every successful run, so nothing
-- else references these rows. To remove the feature, revert its commit and DROP TABLE this table.
CREATE TABLE socket_supply_chain_alert (
    id INT AUTO_INCREMENT NOT NULL,
    packageName VARCHAR(191) NOT NULL,
    version VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL,
    link VARCHAR(2048) DEFAULT NULL,
    reportedAt DATETIME DEFAULT NULL,
    UNIQUE INDEX socket_supply_chain_alert_uniq (packageName, version, type),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

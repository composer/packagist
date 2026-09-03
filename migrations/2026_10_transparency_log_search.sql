-- Search index for the public transparency log: the User and Actor filters and the profile link.
--
-- One row for each person and role (user or actor) that an entry names.
-- `name` is the username at the time of the event. `userId` finds an account also after a rename.
-- `primaryRow` is 1 on one row per account and entry, so a search by account finds each entry once.
-- `datetime` and `leafIndex` are copies from the entry, so the indexes are in page order.
--
-- Backfill older entries with `bin/console packagist:backfill-transparency-log-search`.
CREATE TABLE package_transparency_log_search (
    type VARCHAR(16) NOT NULL,
    name VARCHAR(255) NOT NULL,
    leafIndex INT UNSIGNED NOT NULL,
    userId INT NOT NULL,
    datetime DATETIME NOT NULL,
    primaryRow TINYINT NOT NULL,
    INDEX type_name_datetime_idx (type, name, datetime, leafIndex),
    INDEX user_id_primary_datetime_idx (userId, primaryRow, datetime, leafIndex),
    PRIMARY KEY (type, name, leafIndex)
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

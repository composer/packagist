-- The transparency log User filter now goes through package_transparency_log_search, and nothing
-- else reads package_transparency_log.userId, so its index is never used. The column itself stays
-- part of each projected entry.
ALTER TABLE package_transparency_log DROP INDEX user_datetime_idx;

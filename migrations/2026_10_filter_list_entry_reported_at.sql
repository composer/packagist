-- When the source reported a filter list entry, so the malware reports of a version can be listed in
-- the order they came in. NULL when the source does not say (Aikido's feed has no timestamps).
-- Manual entries are reported by the admin creating them, so they take their creation time.
ALTER TABLE filter_list_entry
    ADD reportedAt DATETIME DEFAULT NULL;

UPDATE filter_list_entry SET reportedAt = createdAt WHERE source = 'packagist';

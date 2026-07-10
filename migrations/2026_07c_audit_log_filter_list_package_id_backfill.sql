-- Backfill audit_log.packageId for filter list entries

UPDATE audit_log a
    JOIN package p
        ON p.name = JSON_UNQUOTE(JSON_EXTRACT(a.attributes, '$.entry.package_name'))
    SET a.packageId = p.id
    WHERE a.type IN (
            'filter_list_entry_added',
            'filter_list_entry_deleted',
            'filter_list_entry_disabled',
            'filter_list_entry_enabled',
            'filter_list_entry_edited'
        )
      AND a.packageId IS NULL;

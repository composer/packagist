-- Socket's threat feed links each malware entry to the flagged file, and those URLs include the
-- file's path inside the package, so deeply nested files run past the 255 chars VARCHAR(255) held.
-- 2048 fits every URL browsers and the feed realistically produce.
ALTER TABLE filter_list_entry
    MODIFY link VARCHAR(2048) DEFAULT NULL;

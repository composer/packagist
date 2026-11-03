-- Default branch commit the readme was last read from, so updates only re-read the readme (an
-- extra request per crawl, often a GitHub API call) when the default branch moved. NULL means it
-- has to be read on the next update.
ALTER TABLE package
    ADD COLUMN readmeReference VARCHAR(64) DEFAULT NULL,
    ALGORITHM=INSTANT;

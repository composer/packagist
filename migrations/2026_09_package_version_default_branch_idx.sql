-- getDefaultBranchRequireFor() - the "Latest version requires x" annotation on the dependents
-- listing - joins package_version on (package_id, defaultBranch), and nothing indexed defaultBranch:
-- package_id and pkg_ver_idx both get the optimizer to a package's versions, but it then has to do a
-- clustered-index lookup per version row to read the flag, against rows carrying this table's JSON
-- columns. The .json listing asks for 100 packages at a time, so that is a hundred packages' entire
-- version history per request; measured at 11s on illuminate/support.
--
-- package_id leads because it is the selective half. defaultBranch is a two-value column and worth
-- nothing on its own - is_devel_idx is the standing example of that - but as the second part it
-- turns the lookup into a single seek per package.
--
-- INPLACE/NONE so it does not lock the table while the crawler keeps writing versions. It is still a
-- secondary index build over a large table, so run it off-peak: the box has been at its IOPS ceiling.
ALTER TABLE package_version
    ADD INDEX package_default_branch_idx (package_id, defaultBranch),
    ALGORITHM=INPLACE, LOCK=NONE;

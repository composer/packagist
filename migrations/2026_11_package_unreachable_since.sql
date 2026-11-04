-- A package whose repository no VCS driver can be established for (typically a self-hosted git
-- server that does not answer) used to be frozen as gone on the first failure, even when the server
-- was only briefly down. Such failures are now retried weekly and only freeze the package once they
-- have persisted for 4 weeks, which needs the time of the first failure.
--
-- Purely additive, run before deploying the code.
ALTER TABLE package ADD unreachableSince DATETIME DEFAULT NULL, ALGORITHM=INSTANT;

-- Add dedupe columns to connection_log. Existing rows keep NULL.
-- Run once; safe to re-run only if columns are missing (will error if already exist).

ALTER TABLE connection_log
  ADD COLUMN sourceSystemId INT NULL DEFAULT NULL,
  ADD COLUMN targetSystemId INT NULL DEFAULT NULL,
  ADD COLUMN dedupeKey VARCHAR(128) NULL DEFAULT NULL;

CREATE UNIQUE INDEX connection_log_dedupe_key_unique ON connection_log (dedupeKey);

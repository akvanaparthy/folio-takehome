-- Scheduled publishing: documents may have a future publish_at timestamp.
-- NULL means "published immediately" (backward-compatible default).
-- Stored as UTC text in 'YYYY-MM-DD HH:MM:SS' format.
ALTER TABLE documents ADD COLUMN publish_at TEXT;

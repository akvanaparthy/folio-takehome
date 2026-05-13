-- Human-readable IDs: each document gets a slug like welcome-packet-3kx7.
-- Nullable column + unique index; application code always sets a slug on insert.
ALTER TABLE documents ADD COLUMN slug TEXT;
CREATE UNIQUE INDEX idx_documents_slug ON documents(slug);

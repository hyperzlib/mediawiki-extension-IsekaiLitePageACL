CREATE TABLE isekai_lpacl_pending_reindex (
  root_page_id INTEGER NOT NULL,
  root_namespace INTEGER NOT NULL,
  root_title BYTEA NOT NULL,
  reason BYTEA NOT NULL,
  requested_at TIMESTAMPTZ NOT NULL,
  PRIMARY KEY (root_page_id, reason)
);
CREATE INDEX isekai_lpacl_pending_reindex_title ON isekai_lpacl_pending_reindex (root_namespace, root_title);

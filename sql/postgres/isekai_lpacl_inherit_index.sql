CREATE TABLE isekai_lpacl_inherit_index (
  page_id INTEGER NOT NULL PRIMARY KEY,
  source_page_id INTEGER NULL,
  chain_hash BYTEA NOT NULL,
  chain_page_ids BYTEA NULL,
  indexed_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX isekai_lpacl_inherit_index_source ON isekai_lpacl_inherit_index (source_page_id);

CREATE TABLE isekai_lpacl_page (
  page_id INTEGER NOT NULL PRIMARY KEY,
  owner_actor_id BIGINT NOT NULL,
  inherit SMALLINT NOT NULL DEFAULT 0,
  acl_version INTEGER NOT NULL DEFAULT 1,
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX isekai_lpacl_page_owner_actor_id ON isekai_lpacl_page (owner_actor_id);

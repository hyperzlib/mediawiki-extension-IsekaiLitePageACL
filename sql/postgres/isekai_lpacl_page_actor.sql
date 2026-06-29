CREATE TABLE isekai_lpacl_page_actor (
  page_id INTEGER NOT NULL,
  actor_id BIGINT NOT NULL,
  roles TEXT[] NOT NULL DEFAULT '{}',
  permissions TEXT[] NOT NULL DEFAULT '{}',
  granted_by_actor_id BIGINT NOT NULL,
  granted_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL,
  PRIMARY KEY (page_id, actor_id)
);
CREATE INDEX isekai_lpacl_page_actor_actor_id ON isekai_lpacl_page_actor (actor_id);

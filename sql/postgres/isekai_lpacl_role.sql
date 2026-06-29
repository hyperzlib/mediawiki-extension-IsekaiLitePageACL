CREATE SEQUENCE isekai_lpacl_role_role_id_seq;
CREATE TABLE isekai_lpacl_role (
  role_id BIGINT NOT NULL PRIMARY KEY DEFAULT nextval('isekai_lpacl_role_role_id_seq'),
  role_key BYTEA NOT NULL UNIQUE,
  role_description BYTEA NULL,
  role_permissions TEXT[] NOT NULL DEFAULT '{}',
  role_enabled SMALLINT NOT NULL DEFAULT 1,
  created_by_actor_id BIGINT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX isekai_lpacl_role_enabled ON isekai_lpacl_role (role_enabled);

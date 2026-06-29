CREATE TABLE isekai_lpacl_role_permission (
  role_id BIGINT NOT NULL,
  permission BYTEA NOT NULL,
  PRIMARY KEY (role_id, permission)
);

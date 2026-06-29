CREATE TABLE isekai_lpacl_participant (
  page_id INTEGER NOT NULL,
  actor_id BIGINT NOT NULL,
  participant_type BYTEA NOT NULL,
  first_seen TIMESTAMPTZ NOT NULL,
  last_seen TIMESTAMPTZ NOT NULL,
  PRIMARY KEY (page_id, actor_id, participant_type)
);
CREATE INDEX isekai_lpacl_participant_actor_type ON isekai_lpacl_participant (actor_id, participant_type);

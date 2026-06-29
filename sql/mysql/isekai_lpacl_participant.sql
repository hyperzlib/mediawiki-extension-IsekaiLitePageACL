CREATE TABLE /*_*/isekai_lpacl_participant (
  `page_id` INT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `participant_type` VARBINARY(32) NOT NULL,
  `first_seen` BINARY(14) NOT NULL,
  `last_seen` BINARY(14) NOT NULL,
  PRIMARY KEY (`page_id`, `actor_id`, `participant_type`)
) /*$wgDBTableOptions*/;
ALTER TABLE /*_*/isekai_lpacl_participant ADD INDEX (`actor_id`, `participant_type`);

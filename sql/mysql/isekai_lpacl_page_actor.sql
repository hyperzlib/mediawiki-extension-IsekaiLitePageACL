CREATE TABLE /*_*/isekai_lpacl_page_actor (
  `page_id` INT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `roles` JSON NOT NULL,
  `permissions` JSON NOT NULL,
  `granted_by_actor_id` BIGINT UNSIGNED NOT NULL,
  `granted_at` BINARY(14) NOT NULL,
  `updated_at` BINARY(14) NOT NULL,
  PRIMARY KEY (`page_id`, `actor_id`)
) /*$wgDBTableOptions*/;
ALTER TABLE /*_*/isekai_lpacl_page_actor ADD INDEX (`actor_id`);

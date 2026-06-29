CREATE TABLE /*_*/isekai_lpacl_page (
  `page_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `owner_actor_id` BIGINT UNSIGNED NOT NULL,
  `inherit` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `acl_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` BINARY(14) NOT NULL,
  `updated_at` BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;
ALTER TABLE /*_*/isekai_lpacl_page ADD INDEX (`owner_actor_id`);

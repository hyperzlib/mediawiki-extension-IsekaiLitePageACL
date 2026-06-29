CREATE TABLE /*_*/isekai_lpacl_role (
  `role_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `role_key` VARBINARY(128) NOT NULL UNIQUE,
  `role_description` BLOB NULL,
  `role_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_actor_id` BIGINT UNSIGNED NOT NULL,
  `created_at` BINARY(14) NOT NULL,
  `updated_at` BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;
ALTER TABLE /*_*/isekai_lpacl_role ADD INDEX (`role_enabled`);

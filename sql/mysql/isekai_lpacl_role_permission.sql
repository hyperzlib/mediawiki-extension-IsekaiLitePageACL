CREATE TABLE /*_*/isekai_lpacl_role_permission (
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission` VARBINARY(128) NOT NULL,
  PRIMARY KEY (`role_id`, `permission`)
) /*$wgDBTableOptions*/;

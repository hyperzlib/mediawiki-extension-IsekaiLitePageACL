CREATE TABLE /*_*/isekai_lpacl_pending_reindex (
  `root_page_id` INT UNSIGNED NOT NULL,
  `root_namespace` INT NOT NULL,
  `root_title` VARBINARY(255) NOT NULL,
  `reason` VARBINARY(64) NOT NULL,
  `requested_at` BINARY(14) NOT NULL,
  PRIMARY KEY (`root_page_id`, `reason`)
) /*$wgDBTableOptions*/;
ALTER TABLE /*_*/isekai_lpacl_pending_reindex ADD INDEX (`root_namespace`, `root_title`);

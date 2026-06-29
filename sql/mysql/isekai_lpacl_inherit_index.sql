CREATE TABLE /*_*/isekai_lpacl_inherit_index (
  `page_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `source_page_id` INT UNSIGNED NULL,
  `chain_hash` VARBINARY(64) NOT NULL,
  `chain_page_ids` BLOB NULL,
  `indexed_at` BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;
ALTER TABLE /*_*/isekai_lpacl_inherit_index ADD INDEX (`source_page_id`);

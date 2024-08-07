-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db_patches/abstractSchemaChanges/patch-add-ce_invitation_lists.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/ce_invitation_lists (
  ceil_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  ceil_name VARBINARY(255) NOT NULL,
  ceil_event_id BIGINT UNSIGNED DEFAULT NULL,
  ceil_status INT NOT NULL,
  ceil_user_id INT NOT NULL,
  ceil_wiki VARBINARY(64) NOT NULL,
  ceil_created_at BINARY(14) NOT NULL,
  INDEX ce_invitation_lists_event_id (ceil_event_id),
  INDEX ce_invitation_lists_wiki (ceil_wiki),
  INDEX ce_invitation_lists_user_wiki (ceil_user_id, ceil_wiki),
  PRIMARY KEY(ceil_id)
) /*$wgDBTableOptions*/;

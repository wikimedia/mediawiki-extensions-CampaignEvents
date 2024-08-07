-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db_patches/abstractSchemaChanges/patch-add-ce_invitation_list_users.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/ce_invitation_list_users (
  ceilu_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  ceilu_user_id INT NOT NULL,
  ceilu_ceil_id BIGINT UNSIGNED NOT NULL,
  ceilu_score INT NOT NULL,
  INDEX ce_invitation_list_users_ceil_id (ceilu_ceil_id),
  INDEX ce_invitation_list_users_ceilu_user_id (ceilu_user_id),
  PRIMARY KEY(ceilu_id)
) /*$wgDBTableOptions*/;

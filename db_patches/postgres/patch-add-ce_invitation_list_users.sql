-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db_patches/abstractSchemaChanges/patch-add-ce_invitation_list_users.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE ce_invitation_list_users (
  ceilu_id BIGSERIAL NOT NULL,
  ceilu_user_id INT NOT NULL,
  ceilu_ceil_id BIGINT NOT NULL,
  ceilu_score INT NOT NULL,
  PRIMARY KEY(ceilu_id)
);

CREATE INDEX ce_invitation_list_users_ceil_id ON ce_invitation_list_users (ceilu_ceil_id);

CREATE INDEX ce_invitation_list_users_ceilu_user_id ON ce_invitation_list_users (ceilu_user_id);
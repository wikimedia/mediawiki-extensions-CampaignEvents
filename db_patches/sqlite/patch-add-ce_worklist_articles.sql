-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db_patches/abstractSchemaChanges/patch-add-ce_worklist_articles.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/ce_worklist_articles (
  cewa_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  cewa_page_id INTEGER UNSIGNED NOT NULL,
  cewa_page_title BLOB NOT NULL, cewa_ceil_id BIGINT UNSIGNED NOT NULL
);

CREATE INDEX ce_worklist_articles_ceil_id ON /*_*/ce_worklist_articles (cewa_ceil_id);

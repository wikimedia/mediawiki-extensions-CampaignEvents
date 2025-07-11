-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db_patches/abstractSchemaChanges/patch-add-cea_country_code.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TEMPORARY TABLE /*_*/__temp__ce_address AS
SELECT
  cea_id,
  cea_full_address,
  cea_country
FROM /*_*/ce_address;
DROP TABLE /*_*/ce_address;


CREATE TABLE /*_*/ce_address (
    cea_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cea_full_address BLOB NOT NULL, cea_country BLOB DEFAULT NULL,
    cea_country_code BLOB DEFAULT NULL
  );
INSERT INTO /*_*/ce_address (
    cea_id, cea_full_address, cea_country
  )
SELECT
  cea_id,
  cea_full_address,
  cea_country
FROM
  /*_*/__temp__ce_address;
DROP TABLE /*_*/__temp__ce_address;

CREATE INDEX cea_country_code ON /*_*/ce_address (cea_country_code);

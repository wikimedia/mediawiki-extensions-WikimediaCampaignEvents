-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: db_patches/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/wikimedia_campaign_events_grant (
  wceg_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  wceg_event_id BIGINT UNSIGNED NOT NULL,
  wceg_grant_id TINYBLOB NOT NULL,
  wceg_grant_agreement_at BINARY(14) NOT NULL,
  UNIQUE INDEX wceg_event_id (wceg_event_id),
  PRIMARY KEY(wceg_id)
) /*$wgDBTableOptions*/;

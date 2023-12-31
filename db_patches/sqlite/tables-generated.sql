-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: db_patches/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/wikimedia_campaign_events_grant (
  wceg_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  wceg_event_id BIGINT UNSIGNED NOT NULL,
  wceg_grant_id BLOB NOT NULL, wceg_grant_agreement_at BLOB NOT NULL
);

CREATE UNIQUE INDEX wceg_event_id ON /*_*/wikimedia_campaign_events_grant (wceg_event_id);

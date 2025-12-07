<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

class GrantsStore {
	public const SERVICE_NAME = 'WikimediaCampaignEventsGrantsStore';

	public function __construct(
		private readonly CampaignsDatabaseHelper $dbHelper,
	) {
	}

	/**
	 * @param string $grantID
	 * @param int $eventID
	 * @param string $grantAgreementAt
	 * @return void
	 */
	public function updateGrantID( string $grantID, int $eventID, string $grantAgreementAt ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'wikimedia_campaign_events_grant' )
			->row( [
				'wceg_event_id' => $eventID,
				'wceg_grant_id' => $grantID,
				'wceg_grant_agreement_at' => $dbw->timestamp( $grantAgreementAt )
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( 'wceg_event_id' )
			->set( [
				'wceg_grant_id' => $grantID,
				'wceg_grant_agreement_at' => $dbw->timestamp( $grantAgreementAt )
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param int $eventID
	 * @return void
	 */
	public function deleteGrantID( int $eventID ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'wikimedia_campaign_events_grant' )
			->where( [ 'wceg_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param int $eventID
	 * @return string|null
	 */
	public function getGrantID( int $eventID ): ?string {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$grantID = $dbr->newSelectQueryBuilder()
			->select( 'wceg_grant_id' )
			->from( 'wikimedia_campaign_events_grant' )
			->where( [ 'wceg_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->fetchField();

		return $grantID ?: null;
	}
}

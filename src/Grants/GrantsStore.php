<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants;

use IDBAccessObject;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

class GrantsStore implements IDBAccessObject {
	public const SERVICE_NAME = 'WikimediaCampaignEventsGrantsStore';

	/** @var CampaignsDatabaseHelper */
	private $dbHelper;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 */
	public function __construct( CampaignsDatabaseHelper $dbHelper ) {
		$this->dbHelper = $dbHelper;
	}

	/**
	 * @param string $grantID
	 * @param int $eventID
	 * @param string $grantAgreementAt
	 * @return void
	 */
	public function updateGrantID( string $grantID, int $eventID, string $grantAgreementAt ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->upsert(
			'wikimedia_campaign_events_grant',
			[
				'wceg_event_id' => $eventID,
				'wceg_grant_id' => $grantID,
				'wceg_grant_agreement_at' => $dbw->timestamp( $grantAgreementAt )
			],
			'wceg_event_id',
			[
				'wceg_grant_id' => $grantID,
				'wceg_grant_agreement_at' => $dbw->timestamp( $grantAgreementAt )
			]
		);
	}

	/**
	 * @param int $eventID
	 * @return void
	 */
	public function deleteGrantID( int $eventID ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		$dbw->delete( 'wikimedia_campaign_events_grant', [ 'wceg_event_id' => $eventID ] );
	}

	/**
	 * @param int $eventID
	 * @return string|null
	 */
	public function getGrantID( int $eventID ): ?string {
		$dbr = $this->dbHelper->getDBConnection( DB_REPLICA );
		$grantID = $dbr->selectField(
			'wikimedia_campaign_events_grant',
			'wceg_grant_id',
			[ 'wceg_event_id' => $eventID ]
		);

		if ( !$grantID ) {
			return null;
		}

		return $grantID;
	}
}

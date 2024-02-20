<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Tests\Integration\Grants;

use Generator;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Extension\WikimediaCampaignEvents\WikimediaCampaignEventsServices;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore
 * @group Database
 */
class GrantsStoreTest extends MediaWikiIntegrationTestCase {
	private GrantsStore $grantsStore;

	protected function setUp(): void {
		parent::setUp();
		$this->grantsStore = WikimediaCampaignEventsServices::getGrantsStore();
	}

	public function addDBData() {
		$dbw = $this->getDb();
		$grantAgreementAt = $dbw->timestamp( wfTimestampNow() );
		$rows = [
			[
				'wceg_event_id' => 1,
				'wceg_grant_id' => '1111-1111',
				'wceg_grant_agreement_at' => $grantAgreementAt,
			],
			[
				'wceg_event_id' => 2,
				'wceg_grant_id' => '2222-2222',
				'wceg_grant_agreement_at' => $grantAgreementAt,
			]
		];
		$dbw->insert( 'wikimedia_campaign_events_grant', $rows, __METHOD__ );
	}

	/**
	 * @dataProvider provideUpdateGrantID
	 */
	public function testUpdateGrantID( int $eventID ) {
		$grantID = '1111-1112';
		$this->grantsStore->updateGrantID( $grantID, $eventID, wfTimestampNow() );

		$storedGrantID = $this->grantsStore->getGrantID( $eventID );
		$this->assertSame( $grantID, $storedGrantID );
	}

	public static function provideUpdateGrantID(): Generator {
		yield 'insert grant ID' => [ 100 ];
		yield 'update grant ID' => [ 1 ];
	}

	public function testDeleteGrantID() {
		$eventID = 2;
		$this->grantsStore->deleteGrantID( $eventID );
		$newGrantID = $this->grantsStore->getGrantID( $eventID );
		$this->assertNull( $newGrantID );
	}
}

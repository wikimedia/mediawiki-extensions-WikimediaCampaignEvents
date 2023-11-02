<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Tests\Unit\Grants;

use EmptyBagOStuff;
use Generator;
use HashBagOStuff;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\FluxxClient;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup
 * @covers ::__construct
 */
class GrantIDLookupTest extends MediaWikiUnitTestCase {
	private function getLookup(
		StatusValue $fluxxResponse
	): GrantIDLookup {
		$fluxxClient = $this->createMock( FluxxClient::class );
		$fluxxClient->method( 'makePostRequest' )->willReturn( $fluxxResponse );

		return new GrantIDLookup(
			$fluxxClient,
			new EmptyBagOStuff()
		);
	}

	/**
	 * @param string $grantID
	 * @param StatusValue $responseStatus
	 * @param StatusValue $expected
	 * @covers ::doLookup
	 * @covers ::getGrantData
	 * @covers ::requestGrantData
	 * @covers ::getColsParam
	 * @covers ::getFiltersParam
	 * @dataProvider provideDoLookup
	 */
	public function testDoLookup( string $grantID, StatusValue $responseStatus, StatusValue $expected ) {
		$lookup = $this->getLookup( $responseStatus );
		$actual = $lookup->doLookup( $grantID );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideUnexpectedResponses(): Generator {
		$requestErrorMsg = 'some-request-error';
		yield 'Request error' => [
			'123-123',
			StatusValue::newFatal( $requestErrorMsg ),
			StatusValue::newFatal( $requestErrorMsg ),
		];

		yield 'Empty response' => [
			'123-123',
			StatusValue::newGood( [] ),
			StatusValue::newFatal( 'wikimediacampaignevents-grant-id-invalid-error-message' ),
		];

		yield 'Response lacks expected fields' => [
			'123-123',
			StatusValue::newGood( [ 'records' => [ 'grant_request' => [ 'base_request_id' => '999-999' ] ] ] ),
			StatusValue::newFatal( 'wikimediacampaignevents-grant-id-invalid-error-message' ),
		];
	}

	public static function provideDoLookup(): Generator {
		yield from self::provideUnexpectedResponses();

		yield 'Successful' => [
			'123-123',
			self::getGoodResponseStatus( '123-123', '20000101000000' ),
			StatusValue::newGood(),
		];
	}

	/**
	 * @param string $grantID
	 * @param StatusValue $responseStatus
	 * @param StatusValue $expected
	 * @covers ::getAgreementAt
	 * @covers ::getGrantData
	 * @covers ::requestGrantData
	 * @covers ::getColsParam
	 * @covers ::getFiltersParam
	 * @dataProvider provideGetAgreementAt
	 */
	public function testGetAgreementAt( string $grantID, StatusValue $responseStatus, StatusValue $expected ) {
		$lookup = $this->getLookup( $responseStatus );
		$actual = $lookup->getAgreementAt( $grantID );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideGetAgreementAt(): Generator {
		yield from self::provideUnexpectedResponses();

		$timestamp = '20230101000000';
		yield 'Successful' => [
			'123-123',
			self::getGoodResponseStatus( '123-123', $timestamp ),
			StatusValue::newGood( $timestamp ),
		];
	}

	/**
	 * @covers ::getGrantData
	 */
	public function testCaching() {
		$firstGrantID = '123-123';
		$secondGrantID = '200-200';

		$fluxxClient = $this->createMock( FluxxClient::class );
		// The `exactly( 2 )` constraint below and the $seenIDs map are really the important part of this test.
		$seenIDs = [
			$firstGrantID => false,
			$secondGrantID => false,
		];
		$fluxxClient->expects( $this->exactly( 2 ) )
			->method( 'makePostRequest' )
			->willReturnCallback( function ( string $endpoint, array $data ) use ( &$seenIDs ): StatusValue {
				$this->assertArrayHasKey( 'filter', $data );
				$filterData = json_decode( $data['filter'], true, JSON_THROW_ON_ERROR );
				$this->assertArrayHasKey( 'conditions', $filterData );
				$grantID = null;
				foreach ( $filterData['conditions'] as $cond ) {
					if ( $cond[0] === 'base_request_id' && $cond[1] === 'eq' ) {
						$grantID = $cond[2];
					}
				}
				$this->assertNotNull( $grantID, 'Cannot extract grant ID from request params' );
				$this->assertFalse( $seenIDs[$grantID], "Duplicate request for ID $grantID" );
				$seenIDs[$grantID] = true;
				return self::getGoodResponseStatus( $grantID, '20230606060606' );
			} );
		$lookup = new GrantIDLookup( $fluxxClient, new HashBagOStuff() );

		// Warm up the cache
		$lookupStatus = $lookup->doLookup( $firstGrantID );
		$this->assertStatusGood( $lookupStatus, 'Precondition: first grant ID is valid' );
		// These should be read from cache
		$lookup->getAgreementAt( $firstGrantID );
		$lookup->doLookup( $firstGrantID );

		// This should be a cache miss
		$otherGrantStatus = $lookup->getAgreementAt( $secondGrantID );
		$this->assertStatusGood( $otherGrantStatus, 'Precondition: second grant ID is valid' );
		// But these two must be cache hits
		$lookup->getAgreementAt( $secondGrantID );
		$lookup->doLookup( $secondGrantID );
		// And the previous one should still be a cache hit
		$lookup->doLookup( $firstGrantID );
	}

	private static function getGoodResponseStatus( string $grantID, string $timestamp ): StatusValue {
		return StatusValue::newGood( [ 'records' => [ 'grant_request' => [
			[
				'base_request_id' => $grantID,
				'grant_agreement_at' => $timestamp
			]
		] ] ] );
	}
}

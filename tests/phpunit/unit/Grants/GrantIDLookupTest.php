<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Tests\Unit\Grants;

use Exception;
use Generator;
use HashBagOStuff;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\FluxxRequestException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\InvalidGrantIDException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\FluxxClient;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
use MediaWikiUnitTestCase;
use StatusValue;
use WANObjectCache;

/**
 * @covers \MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup
 */
class GrantIDLookupTest extends MediaWikiUnitTestCase {
	/**
	 * @param array|null $fluxxResponse Null indicates that the request should throw an exception
	 * @return GrantIDLookup
	 */
	private function getLookup(
		?array $fluxxResponse
	): GrantIDLookup {
		$fluxxClient = $this->createMock( FluxxClient::class );
		if ( $fluxxResponse !== null ) {
			$fluxxClient->method( 'makePostRequest' )->willReturn( $fluxxResponse );
		} else {
			$fluxxClient->method( 'makePostRequest' )->willThrowException( new FluxxRequestException() );
		}

		return new GrantIDLookup(
			$fluxxClient,
			WANObjectCache::newEmpty()
		);
	}

	/**
	 * @param string $grantID
	 * @param array|null $fluxxResponse
	 * @param StatusValue|Exception $expected
	 * @dataProvider provideDoLookup
	 */
	public function testDoLookup( string $grantID, ?array $fluxxResponse, $expected ) {
		$lookup = $this->getLookup( $fluxxResponse );
		if ( $expected instanceof Exception ) {
			$this->expectExceptionObject( $expected );
		}
		$actual = $lookup->doLookup( $grantID );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideUnexpectedResponses() {
		yield 'Request error' => [
			'123-123',
			null,
			new FluxxRequestException(),
		];

		yield 'Empty response' => [
			'123-123',
			[],
			new InvalidGrantIDException(),
		];

		yield 'Response lacks expected fields' => [
			'123-123',
			[ 'records' => [ 'grant_request' => [ 'base_request_id' => '999-999' ] ] ],
			new InvalidGrantIDException(),
		];
	}

	public static function provideDoLookup() {
		yield from self::provideUnexpectedResponses();

		yield 'Successful' => [
			'123-123',
			self::getValidResponse( '123-123', '20000101000000' ),
			StatusValue::newGood(),
		];
	}

	/**
	 * @param string $grantID
	 * @param array|null $fluxxResponse
	 * @param StatusValue|Exception $expected
	 * @dataProvider provideGetAgreementAt
	 */
	public function testGetAgreementAt( string $grantID, ?array $fluxxResponse, $expected ) {
		$lookup = $this->getLookup( $fluxxResponse );
		if ( $expected instanceof Exception ) {
			$this->expectExceptionObject( $expected );
		}
		$actual = $lookup->getAgreementAt( $grantID );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideGetAgreementAt(): Generator {
		yield from self::provideUnexpectedResponses();

		$timestamp = '20230101000000';
		yield 'Successful' => [
			'123-123',
			self::getValidResponse( '123-123', $timestamp ),
			$timestamp,
		];
	}

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
			->willReturnCallback( function ( string $endpoint, array $data ) use ( &$seenIDs ): array {
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
				return self::getValidResponse( $grantID, '20230606060606' );
			} );
		$lookup = new GrantIDLookup( $fluxxClient, new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ) );

		// Warm up the cache
		$lookupStatus = $lookup->doLookup( $firstGrantID );
		$this->assertStatusGood( $lookupStatus, 'Precondition: first grant ID is valid' );
		// These should be read from cache
		$lookup->getAgreementAt( $firstGrantID );
		$lookup->doLookup( $firstGrantID );

		// This should be a cache miss
		$otherGrantTimestamp = $lookup->getAgreementAt( $secondGrantID );
		$this->assertIsString( $otherGrantTimestamp, 'Precondition: second grant ID is valid' );
		// But these two must be cache hits
		$lookup->getAgreementAt( $secondGrantID );
		$lookup->doLookup( $secondGrantID );
		// And the previous one should still be a cache hit
		$lookup->doLookup( $firstGrantID );
	}

	private static function getValidResponse( string $grantID, string $timestamp ): array {
		return [
			'records' => [
				'grant_request' => [
					[
						'base_request_id' => $grantID,
						'grant_agreement_at' => $timestamp
					]
				]
			]
		];
	}
}

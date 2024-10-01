<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Tests\Unit\WikiProject;

use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\WikiProjectFullLookup;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\WikiProjectIDLookup;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Status\Status;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use WANObjectCache;
use Wikimedia\ObjectCache\EmptyBagOStuff;

/**
 * @coversDefaultClass \MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\WikiProjectFullLookup
 */
class WikiProjectFullLookupTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::getWikiProjects
	 * @dataProvider provideWikiProjectsForPagination
	 */
	public function testGetWikiProjects__pagination(
		array $allIDs,
		int $limit,
		?string $lastID,
		int $direction,
		array $expectedIDs
	) {
		$languageCode = 'qqx';

		$wikiProjectIDLookup = $this->createMock( WikiProjectIDLookup::class );
		$wikiProjectIDLookup->method( 'getWikiProjectIDs' )->willReturn( $allIDs );

		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->method( 'create' )->willReturnCallback( function ( string $url ) use ( $languageCode ) {
			$wdWikiProjectInfo = [
				'labels' => [ $languageCode => [ 'value' => 'Test' ] ],
				'sitelinks' => [ WikiMap::getCurrentWikiId() => [ 'url' => 'https://example.org' ] ]
			];

			// Parse the query string we just built to figure out what IDs would be requested...
			$queryString = parse_url( $url, PHP_URL_QUERY );
			parse_str( $queryString, $queryParams );
			$requestedIDs = explode( '|', $queryParams['ids'] );
			$httpRequest = $this->createMock( MWHttpRequest::class );
			$httpRequest->method( 'execute' )->willReturn( Status::newGood() );
			$httpRequest->method( 'getContent' )->willReturn( json_encode( [
				'entities' => array_fill_keys( $requestedIDs, $wdWikiProjectInfo ),
			] ) );
			return $httpRequest;
		} );

		$lookup = new WikiProjectFullLookup(
			$wikiProjectIDLookup,
			new WANObjectCache( [ 'cache' => new EmptyBagOStuff() ] ),
			$httpRequestFactory
		);
		$actualData = $lookup->getWikiProjects( $languageCode, $limit, $lastID, $direction );
		$this->assertSame( $expectedIDs, array_keys( $actualData ) );
	}

	public static function provideWikiProjectsForPagination(): array {
		$fwd = WikiProjectFullLookup::DIR_FORWARDS;
		$bwd = WikiProjectFullLookup::DIR_BACKWARDS;
		$allIDs = self::getQIDRange( 1, 100 );

		return [
			'Low limit, no last ID, forwards' => [ $allIDs, 20, null, $fwd, self::getQIDRange( 1, 20 ) ],
			'Low limit, no last ID, backwards' => [ $allIDs, 20, null, $bwd, self::getQIDRange( 81, 100 ) ],
			'Low limit, last ID not in array, forwards' => [ $allIDs, 20, 'Q999', $fwd, self::getQIDRange( 1, 20 ) ],
			'Low limit, last ID not in array, backwards' => [ $allIDs, 20, 'Q999', $bwd, self::getQIDRange( 81, 100 ) ],
			'Low limit, last ID center, forwards' => [ $allIDs, 20, 'Q50', $fwd, self::getQIDRange( 51, 70 ) ],
			'Low limit, last ID center, backwards' => [ $allIDs, 20, 'Q50', $bwd, self::getQIDRange( 30, 49 ) ],
			'Low limit, last ID start edge, forwards' => [ $allIDs, 20, 'Q5', $fwd, self::getQIDRange( 6, 25 ) ],
			'Low limit, last ID start edge, backwards' => [ $allIDs, 20, 'Q5', $bwd, self::getQIDRange( 1, 4 ) ],
			'Low limit, last ID end edge, forwards' => [ $allIDs, 20, 'Q95', $fwd, self::getQIDRange( 96, 100 ) ],
			'Low limit, last ID end edge, backwards' => [ $allIDs, 20, 'Q95', $bwd, self::getQIDRange( 75, 94 ) ],

			'High limit, no last ID, forwards' => [ $allIDs, 200, null, $fwd, $allIDs ],
			'High limit, no last ID, backwards' => [ $allIDs, 200, null, $bwd, $allIDs ],
			'High limit, last ID not in array, forwards' => [ $allIDs, 200, 'Q999', $fwd, $allIDs ],
			'High limit, last ID not in array, backwards' => [ $allIDs, 200, 'Q999', $bwd, $allIDs ],
			'High limit, last ID center, forwards' => [ $allIDs, 200, 'Q50', $fwd, self::getQIDRange( 51, 100 ) ],
			'High limit, last ID center, backwards' => [ $allIDs, 200, 'Q50', $bwd, self::getQIDRange( 1, 49 ) ],
			'High limit, last ID start edge, forwards' => [ $allIDs, 200, 'Q5', $fwd, self::getQIDRange( 6, 100 ) ],
			'High limit, last ID start edge, backwards' => [ $allIDs, 200, 'Q5', $bwd, self::getQIDRange( 1, 4 ) ],
			'High limit, last ID end edge, forwards' => [ $allIDs, 200, 'Q95', $fwd, self::getQIDRange( 96, 100 ) ],
			'High limit, last ID end edge, backwards' => [ $allIDs, 200, 'Q95', $bwd, self::getQIDRange( 1, 94 ) ],
		];
	}

	/**
	 * Returns a range of Q-entities with the numeric parts from $start to $end (e.g., Q1 to Q100).
	 */
	private static function getQIDRange( int $start, int $end ): array {
		$numRange = range( $start, $end );
		return array_map( static fn ( int $num ) => "Q$num", $numRange );
	}
}

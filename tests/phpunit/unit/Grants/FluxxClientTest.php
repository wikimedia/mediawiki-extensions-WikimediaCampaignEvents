<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Tests\Unit\Grants;

use BagOStuff;
use EmptyBagOStuff;
use HashBagOStuff;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\FluxxRequestException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\FluxxClient;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use Psr\Log\NullLogger;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\WikimediaCampaignEvents\Grants\FluxxClient
 */
class FluxxClientTest extends MediaWikiUnitTestCase {
	private const FLUXX_BASE_URL = 'https://fluxx-base.example.org/';
	private const FLUXX_OAUTH_URL = 'https://fluxx-oauth.example.org/';

	private function getClient(
		?HttpRequestFactory $requestFactory,
		array $configOverrides = [],
		BagOStuff $cache = null
	): FluxxClient {
		return new FluxxClient(
			$requestFactory ?? $this->createMock( HttpRequestFactory::class ),
			new ServiceOptions(
				FluxxClient::CONSTRUCTOR_OPTIONS,
				$configOverrides + [
					FluxxClient::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_OAUTH_URL => self::FLUXX_OAUTH_URL,
					FluxxClient::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_BASE_URL => self::FLUXX_BASE_URL,
					FluxxClient::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_ID => 'abcdefgh',
					FluxxClient::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_SECRET => 'a1b2c3d4e5',
					MainConfigNames::CopyUploadProxy => null,
				]
			),
			$cache ?? new EmptyBagOStuff(),
			new NullLogger()
		);
	}

	/**
	 * @dataProvider provideMissingConfig
	 */
	public function testMissingConfig( array $configOverrides ) {
		$client = $this->getClient( null, $configOverrides );
		$this->expectException( FluxxRequestException::class );
		$this->expectExceptionMessage( 'Authentication error' );
		$status = $client->makePostRequest( 'endpoint' );
	}

	public static function provideMissingConfig(): array {
		return [
			'Empty client ID' => [
				[ FluxxClient::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_ID => null ]
			],
			'Empty client secret' => [
				[ FluxxClient::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_SECRET => null ]
			],
		];
	}

	/**
	 * @param array $tokenReqData
	 * @param array|null $mainReqData If null, it means that we are expecting the token request to fail, and the
	 * main request should not be executed at all.
	 * @param array|null $expected Null means we're expecting an exception.
	 * @dataProvider provideMakePostRequest
	 */
	public function testMakePostRequest(
		array $tokenReqData,
		?array $mainReqData,
		?array $expected
	) {
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory
			->method( 'create' )
			->willReturnCallback( function ( string $url ) use ( $tokenReqData, $mainReqData ): MWHttpRequest {
				if ( str_contains( $url, self::FLUXX_OAUTH_URL ) ) {
					return $this->mockHttpRequest( ...$tokenReqData );
				}
				$this->assertNotNull(
					$mainReqData,
					'Expected token request to fail without the main request being made'
				);
				return $this->mockHttpRequest( ...$mainReqData );
			} );
		$client = $this->getClient( $httpRequestFactory );
		if ( $expected === null ) {
			$this->expectException( FluxxRequestException::class );
		}
		$actual = $client->makePostRequest( 'some-endpoint' );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideMakePostRequest() {
		yield 'Token request generic fail' => [
			[ StatusValue::newFatal( 'some-token-error' ) ],
			null,
			null,
		];

		yield 'Token response has no Content-Type' => [
			[ StatusValue::newGood(), null, null ],
			null,
			null,
		];

		yield 'Token response is not JSON' => [
			[ StatusValue::newGood(), null, 'definitely-not-json' ],
			null,
			null,
		];

		yield 'Token response is JSON but it is invalid' => [
			[ StatusValue::newGood(), '{[' ],
			null,
			null,
		];

		yield 'Token response does not contain expected data' => [
			[ StatusValue::newGood(), json_encode( [] ) ],
			null,
			null,
		];

		$validTokenResponse = json_encode( [
			'access_token' => 'abcdef',
			'expires_in' => 1000
		] );
		yield 'Valid token, generic request error' => [
			[ StatusValue::newGood(), $validTokenResponse ],
			[ StatusValue::newFatal( 'some-request-error' ), null ],
			null,
		];

		yield 'Valid token, main response has no Content-Type' => [
			[ StatusValue::newGood(), $validTokenResponse ],
			[ StatusValue::newGood(), null, null ],
			null,
		];

		yield 'Valid token, main response is not JSON' => [
			[ StatusValue::newGood(), $validTokenResponse ],
			[ StatusValue::newGood(), null, 'definitely-not-json' ],
			null,
		];

		yield 'Valid token, main response is JSON but it is invalid' => [
			[ StatusValue::newGood(), $validTokenResponse ],
			[ StatusValue::newGood(), '{' ],
			null,
		];

		yield 'Successful' => [
			[ StatusValue::newGood(), $validTokenResponse ],
			[ StatusValue::newGood(), json_encode( [] ) ],
			[],
		];
	}

	public function testTokenCache() {
		$tokenRequestSent = false;
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory
			->method( 'create' )
			->willReturnCallback( function ( string $url ) use ( &$tokenRequestSent ) {
				if ( str_contains( $url, self::FLUXX_OAUTH_URL ) ) {
					$this->assertFalse( $tokenRequestSent, 'Token request was sent twice' );
					$tokenRequestSent = true;
				}
				return $this->mockHttpRequest(
					StatusValue::newGood(),
					json_encode( [
						'access_token' => 'abcdef',
						'expires_in' => 1000
					] )
				);
			} );
		$client = $this->getClient( $httpRequestFactory, [], new HashBagOStuff() );

		// Warm-up
		$client->makePostRequest( 'endpoint1' );
		// These should be cache hits
		$client->makePostRequest( 'endpoint2' );
		$client->makePostRequest( 'endpoint3' );
	}

	private function mockHttpRequest(
		StatusValue $status,
		$responseContent = null,
		$contentType = 'application/json'
	): MWHttpRequest {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )->willReturn( $status );
		$request->method( 'getResponseHeader' )
			->with( 'Content-Type' )
			->willReturn( $contentType );
		$request->method( 'getContent' )->willReturn( $responseContent );

		return $request;
	}
}

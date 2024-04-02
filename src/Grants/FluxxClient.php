<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants;

use JsonException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\AuthenticationException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\FluxxRequestException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MWHttpRequest;
use Psr\Log\LoggerInterface;
use WANObjectCache;

/**
 * This class implements an interface to the Fluxx API
 * @see https://wmf.fluxx.io/api/rest/v2/doc (login needed)
 */
class FluxxClient {
	public const SERVICE_NAME = 'WikimediaCampaignEventsFluxxClient';

	public const CONSTRUCTOR_OPTIONS = [
		self::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_OAUTH_URL,
		self::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_BASE_URL,
		self::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_ID,
		self::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_SECRET,
		MainConfigNames::CopyUploadProxy,
	];
	public const WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_BASE_URL = 'WikimediaCampaignEventsFluxxBaseUrl';
	public const WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_OAUTH_URL = 'WikimediaCampaignEventsFluxxOauthUrl';
	public const WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_ID = 'WikimediaCampaignEventsFluxxClientID';
	public const WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_SECRET = 'WikimediaCampaignEventsFluxxClientSecret';

	private HttpRequestFactory $httpRequestFactory;

	protected string $fluxxBaseUrl;
	private string $fluxxOauthUrl;
	private string $fluxxClientID;
	private string $fluxxClientSecret;
	private ?string $requestProxy;
	protected WANObjectCache $cache;
	private LoggerInterface $logger;

	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		ServiceOptions $options,
		WANObjectCache $cache,
		LoggerInterface $logger
	) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $cache;
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->fluxxOauthUrl = $options->get( self::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_OAUTH_URL );
		$this->fluxxBaseUrl = $options->get( self::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_BASE_URL );
		$this->fluxxClientID = $options->get( self::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_ID ) ?? '';
		$this->fluxxClientSecret = $options->get( self::WIKIMEDIA_CAMPAIGN_EVENTS_FLUXX_CLIENT_SECRET ) ?? '';
		$this->requestProxy = $options->get( MainConfigNames::CopyUploadProxy ) ?: null;
		$this->logger = $logger;
	}

	/**
	 * @return string
	 * @throws AuthenticationException
	 */
	private function getToken(): string {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'WikimediaCampaignEvents-FluxxToken' ),
			WANObjectCache::TTL_HOUR,
			function ( $oldValue, int &$ttl ) {
				[ 'token' => $token, 'expiry' => $ttl ] = $this->requestToken();
				return $token;
			},
			[ 'pcTTL' => WANObjectCache::TTL_PROC_LONG ]
		);
	}

	/**
	 * @param string $endpoint
	 * @param array $postData
	 * @return array The (decoded) response we got from Fluxx.
	 * @throws FluxxRequestException
	 */
	public function makePostRequest( string $endpoint, array $postData = [] ): array {
		$headers = [
			'Content-Type' => 'application/json',
		];
		try {
			$headers['Authorization'] = 'Bearer ' . $this->getToken();
		} catch ( AuthenticationException $exception ) {
			throw new FluxxRequestException( 'Authentication error' );
		}

		$url = $this->fluxxBaseUrl . $endpoint;
		return $this->makePostRequestInternal( $url, $postData, $headers );
	}

	/**
	 * @param string $url
	 * @param array $postData
	 * @param array $headers
	 * @return array The (decoded) response we got from Fluxx.
	 * @throws FluxxRequestException
	 */
	private function makePostRequestInternal( string $url, array $postData, array $headers ): array {
		$options = [
			'method' => 'POST',
			'timeout' => 5,
			'postData' => json_encode( $postData )
		];
		if ( $this->requestProxy ) {
			$options['proxy'] = $this->requestProxy;
		}

		$req = $this->httpRequestFactory->create(
			$url,
			$options,
			__METHOD__
		);

		foreach ( $headers as $header => $value ) {
			$req->setHeader( $header, $value );
		}
		$status = $req->execute();

		if ( !$status->isGood() ) {
			$this->logger->error(
				'Error in Fluxx api call: {error_status}',
				[ 'error_status' => $status->__toString() ]
			);
			throw new FluxxRequestException( 'Error in Fluxx API call' );
		}

		$parsedResponse = $this->parseResponseJSON( $req );
		if ( $parsedResponse === null ) {
			$this->logger->error(
				'Error in Fluxx api call: response is not valid JSON',
				[
					'response_status' => $req->getStatus(),
					'response_content_type' => $req->getResponseHeader( 'Content-Type' ),
					'response_content' => $req->getContent()
				]
			);
			throw new FluxxRequestException( 'Invalid Fluxx response' );
		}

		return $parsedResponse;
	}

	private function parseResponseJSON( MWHttpRequest $request ): ?array {
		$contentTypeHeader = $request->getResponseHeader( 'Content-Type' );
		if ( !$contentTypeHeader ) {
			return null;
		}
		$contentType = strtolower( explode( ';', $contentTypeHeader )[0] );
		if ( $contentType !== 'application/json' ) {
			return null;
		}

		try {
			$parsedResponse = json_decode( $request->getContent(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $_ ) {
			return null;
		}

		return is_array( $parsedResponse ) ? $parsedResponse : null;
	}

	/**
	 * @return array
	 * @phan-return array{token:string,expiry:int}
	 * @throws AuthenticationException
	 */
	private function requestToken(): array {
		// Fail fast if we're missing the necessary configuration.
		if ( $this->fluxxClientID === '' || $this->fluxxClientSecret === '' ) {
			$this->logger->error( 'Missing configuration for the Fluxx API' );
			throw new AuthenticationException( 'Fluxx client ID and secret not configured' );
		}

		$data = [
			'grant_type' => 'client_credentials',
			'client_id' => $this->fluxxClientID,
			'client_secret' => $this->fluxxClientSecret,
		];
		$headers = [
			'Content-Type' => 'application/json'
		];

		try {
			$responseData = $this->makePostRequestInternal( $this->fluxxOauthUrl, $data, $headers );
		} catch ( FluxxRequestException $_ ) {
			throw new AuthenticationException( 'Authentication error' );
		}

		if ( !isset( $responseData['access_token'] ) || !isset( $responseData['expires_in'] ) ) {
			throw new AuthenticationException( 'Response does not contain token' );
		}

		return [ 'token' => $responseData['access_token'], 'expiry' => $responseData['expires_in'] ];
	}
}

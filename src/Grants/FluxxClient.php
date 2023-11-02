<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants;

use BagOStuff;
use JsonException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\AuthenticationException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MWHttpRequest;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;

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

	/** @var HttpRequestFactory */
	private HttpRequestFactory $httpRequestFactory;

	protected string $fluxxBaseUrl;
	private string $fluxxOauthUrl;
	private string $fluxxClientID;
	private string $fluxxClientSecret;
	private ?string $requestProxy;
	protected BagOStuff $cache;
	private LoggerInterface $logger;

	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		ServiceOptions $options,
		BagOStuff $cache,
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
			$this->cache->makeKey( 'WikimediaCampaignEvents', 'FluxxToken' ),
			ExpirationAwareness::TTL_PROC_LONG,
			function ( int &$ttl ) {
				[ 'token' => $token, 'expiry' => $ttl ] = $this->requestToken();
				return $token;
			} );
	}

	/**
	 * @param string $endpoint
	 * @param array $postData
	 * @return StatusValue Fatal with errors, or a good Status whose value is the (decoded) response we got from Fluxx.
	 */
	public function makePostRequest( string $endpoint, array $postData = [] ): StatusValue {
		$headers = [
			'Content-Type' => 'application/json',
		];
		try {
			$headers['Authorization'] = 'Bearer ' . $this->getToken();
		} catch ( AuthenticationException $exception ) {
			return StatusValue::newFatal( 'wikimediacampaignevents-grant-id-api-fails-error-message' );
		}

		$url = $this->fluxxBaseUrl . $endpoint;
		return $this->makePostRequestInternal( $url, $postData, $headers );
	}

	/**
	 * @param string $url
	 * @param array $postData
	 * @param array $headers
	 * @return StatusValue Fatal with errors, or a good Status whose value is the (decoded) response we got from Fluxx.
	 */
	private function makePostRequestInternal( string $url, array $postData, array $headers ): StatusValue {
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
			return StatusValue::newFatal( 'wikimediacampaignevents-grant-id-api-fails-error-message' );
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
			return StatusValue::newFatal( 'wikimediacampaignevents-grant-id-api-fails-error-message' );
		}

		return StatusValue::newGood( $parsedResponse );
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

		$responseStatus = $this->makePostRequestInternal( $this->fluxxOauthUrl, $data, $headers );

		if ( !$responseStatus->isGood() ) {
			throw new AuthenticationException( 'Response status is not good' );
		}

		$responseData = $responseStatus->getValue();

		if ( !isset( $responseData['access_token'] ) || !isset( $responseData['expires_in'] ) ) {
			throw new AuthenticationException( 'Response does not contain token' );
		}

		return [ 'token' => $responseData['access_token'], 'expiry' => $responseData['expires_in'] ];
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants;

use BagOStuff;
use LogicException;
use StatusValue;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;

/**
 * This class is responsible for looking up information about grant IDs (e.g., whether they exist, when they were
 * granted, etc.).
 */
class GrantIDLookup {
	public const SERVICE_NAME = 'WikimediaCampaignEventsGrantIDLookup';

	private const ENDPOINT = 'grant_request/list';
	private const GRANTS_FILTER_PERIOD_MONTHS = 24;

	private FluxxClient $fluxxClient;
	private BagOStuff $cache;

	public function __construct(
		FluxxClient $fluxxClient,
		BagOStuff $cache
	) {
		$this->fluxxClient = $fluxxClient;
		$this->cache = $cache;
	}

	/**
	 * @param string $grantID
	 * @return StatusValue Good if the ID is valid, fatal with errors otherwise.
	 */
	public function doLookup( string $grantID ): StatusValue {
		$status = $this->getGrantData( $grantID );
		if ( $status->isGood() ) {
			return StatusValue::newGood();
		}
		return $status;
	}

	/**
	 * @param string $grantID
	 * @return StatusValue If the ID is valid, a good Status whose value is the agreement_at timestamp. A fatal
	 * Status with errors otherwise.
	 */
	public function getAgreementAt( string $grantID ): StatusValue {
		$status = $this->getGrantData( $grantID );
		if ( $status->isGood() ) {
			$grantAgreementAt = $status->getValue()['grant_agreement_at'];
			return StatusValue::newGood( $grantAgreementAt );
		}
		return $status;
	}

	private function getGrantData( string $grantID ): StatusValue {
		$grantStatus = null;
		$cachedData = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'WikimediaCampaignEvents', 'GrantData', $grantID ),
			ExpirationAwareness::TTL_PROC_LONG,
			function () use ( $grantID, &$grantStatus )  {
				$grantStatus = $this->requestGrantData( $grantID );
				if ( $grantStatus->isGood() ) {
					return $grantStatus->getValue();
				}
				// TODO Cache failures due to invalid grant ID, but NOT network issues
				return false;
			} );
		if ( $cachedData !== false ) {
			return StatusValue::newGood( $cachedData );
		}
		if ( $grantStatus !== null ) {
			return $grantStatus;
		}
		throw new LogicException( '$grantStatus should not be null' );
	}

	private function requestGrantData( string $grantID ): StatusValue {
		$cols = $this->getColsParam();
		$filters = $this->getFiltersParam( $grantID );
		$postData = [
			'cols' => json_encode( $cols ),
			'filter' => json_encode( $filters ),
		];

		$response = $this->fluxxClient->makePostRequest( self::ENDPOINT, $postData );

		if ( !$response->isGood() ) {
			return $response;
		}

		$responseData = $response->getValue();
		if (
			isset( $responseData[ 'records' ][ 'grant_request' ][ 0 ][ 'base_request_id' ] ) &&
			$responseData[ 'records' ][ 'grant_request' ][ 0 ][ 'base_request_id' ] === $grantID
		) {
			return StatusValue::newGood( [
				'grant_agreement_at' => wfTimestamp(
					TS_MW,
					$responseData[ 'records' ][ 'grant_request' ][ 0 ][ 'grant_agreement_at' ]
				)
			] );
		}

		return StatusValue::newFatal( 'wikimediacampaignevents-grant-id-invalid-error-message' );
	}

	/**
	 * @return array
	 */
	private function getColsParam(): array {
		return [
			"granted",
			"request_received_at",
			"base_request_id",
			"grant_agreement_at",
		];
	}

	/**
	 * @param string $grantID
	 * @return array
	 */
	private function getFiltersParam( string $grantID ): array {
		return [
			"group_type" => "and",
			"conditions" => [
				[ "base_request_id", "eq", $grantID ],
				[ "granted", "eq", true ],
				[ "grant_agreement_at", "last-n-months", self::GRANTS_FILTER_PERIOD_MONTHS ],
			],
		];
	}
}

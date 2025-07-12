<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants;

use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\FluxxRequestException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\InvalidGrantIDException;
use StatusValue;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * This class is responsible for looking up information about grant IDs (e.g., whether they exist, when they were
 * granted, etc.).
 */
class GrantIDLookup {
	public const SERVICE_NAME = 'WikimediaCampaignEventsGrantIDLookup';

	private const ENDPOINT = 'grant_request/list';
	private const GRANTS_FILTER_PERIOD_DAYS = 730;

	private FluxxClient $fluxxClient;
	private WANObjectCache $cache;

	public function __construct(
		FluxxClient $fluxxClient,
		WANObjectCache $cache
	) {
		$this->fluxxClient = $fluxxClient;
		$this->cache = $cache;
	}

	/**
	 * @param string $grantID
	 * @return StatusValue Always good
	 * @throws InvalidGrantIDException
	 * @throws FluxxRequestException
	 */
	public function doLookup( string $grantID ): StatusValue {
		$this->getGrantData( $grantID );
		return StatusValue::newGood();
	}

	/**
	 * @param string $grantID
	 * @return string The agreement_at timestamp
	 * @throws InvalidGrantIDException
	 * @throws FluxxRequestException
	 */
	public function getAgreementAt( string $grantID ): string {
		return $this->getGrantData( $grantID )['grant_agreement_at'];
	}

	/**
	 * @param string $grantID
	 * @return array
	 * @throws FluxxRequestException
	 * @throws InvalidGrantIDException
	 */
	private function getGrantData( string $grantID ): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'WikimediaCampaignEvents-GrantData', $grantID ),
			WANObjectCache::TTL_HOUR,
			function () use ( $grantID )  {
				// TODO Cache failures due to invalid grant ID, but NOT network issues
				return $this->requestGrantData( $grantID );
			},
			[ 'pcTTL' => WANObjectCache::TTL_PROC_LONG ]
		);
	}

	/**
	 * @param string $grantID
	 * @return array
	 * @throws FluxxRequestException
	 * @throws InvalidGrantIDException
	 */
	private function requestGrantData( string $grantID ): array {
		$cols = $this->getColsParam();
		$filters = $this->getFiltersParam( $grantID );
		$postData = [
			'cols' => json_encode( $cols ),
			'filter' => json_encode( $filters ),
		];

		$responseData = $this->fluxxClient->makePostRequest( self::ENDPOINT, $postData );
		$grant = $responseData[ 'records' ][ 'grant_request' ][ 0 ] ?? null;

		if ( $grant !== null && $grant[ 'base_request_id' ] === $grantID ) {
			return [
				'grant_agreement_at' => wfTimestamp(
					TS_MW,
					$grant[ 'grant_agreement_at' ]
				)
			];
		}

		throw new InvalidGrantIDException();
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
				// last-n-months does not include the current month T367465
				[ "grant_agreement_at", "last-n-days", self::GRANTS_FILTER_PERIOD_DAYS ],
			],
		];
	}
}

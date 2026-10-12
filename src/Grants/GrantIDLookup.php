<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants;

use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\FluxxRequestException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\InvalidGrantIDException;
use StatusValue;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * This class is responsible for looking up information about grant IDs (e.g., whether they exist, when they were
 * granted, etc.).
 */
class GrantIDLookup {
	public const SERVICE_NAME = 'WikimediaCampaignEventsGrantIDLookup';

	private const ENDPOINT = 'grant_request/list';
	/** Maximum age of the grant, 4 years per T434691 */
	private const GRANTS_FILTER_PERIOD_DAYS = 4 * 365;

	/** See T436836#12286416 */
	private const PARTNER_GRANT_STATES = [
		'supported_by_partner',
		'payment_by_partner',
		'administered_by_partner',
		'completed_by_partner',
	];

	/** See T440298#12420267 */
	private const MULTIYEAR_GRANT_STATES = [
		'revision_required_for_year_2_proposal_back_to_draft',
		'revision_required_for_year_3',
		'multiyear_proposal_review',
		'multiyear_subsequent_year_amendment_processing',
		'hold_pending_requirements',
		'pending_amendment_signatures',
		'multiyear_midterm_report_certification',
	];

	public function __construct(
		private readonly FluxxClient $fluxxClient,
		private readonly WANObjectCache $cache,
	) {
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
					TS::MW,
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
			'state',
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
				// last-n-months does not include the current month T367465
				[ "request_received_at", "last-n-days", self::GRANTS_FILTER_PERIOD_DAYS ],
				[
					'group_type' => 'or',
					'conditions' => [
						[ "granted", "eq", true ],
						[
							'state',
							'eq',
							array_merge( self::PARTNER_GRANT_STATES, self::MULTIYEAR_GRANT_STATES ),
						],
					],
				],
			],
		];
	}
}

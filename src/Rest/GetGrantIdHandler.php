<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;

class GetGrantIdHandler extends SimpleHandler {
	use EventIDParamTrait;

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly PermissionChecker $permissionChecker,
		private readonly GrantsStore $grantsStore,
	) {
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		if ( !$this->permissionChecker->userCanEditRegistration( $this->getAuthority(), $registration ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-get-permission-denied' ),
				403
			);
		}

		$grantID = $this->grantsStore->getGrantID( $eventID );
		if ( $grantID === null ) {
			return $this->getResponseFactory()->createHttpError( 404 );
		}
		return $this->getResponseFactory()->createJson( [
			'grant_id' => $grantID
		] );
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}

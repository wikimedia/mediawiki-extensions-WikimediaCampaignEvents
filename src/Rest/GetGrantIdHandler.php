<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;

class GetGrantIdHandler extends SimpleHandler {
	use EventIDParamTrait;

	/** @var IEventLookup */
	private IEventLookup $eventLookup;
	private PermissionChecker $permissionChecker;
	private GrantsStore $grantsStore;

	/**
	 * @param IEventLookup $eventLookup
	 * @param PermissionChecker $permissionChecker
	 * @param GrantsStore $grantsStore
	 */
	public function __construct(
		IEventLookup $eventLookup,
		PermissionChecker $permissionChecker,
		GrantsStore $grantsStore
	) {
		$this->eventLookup = $eventLookup;
		$this->permissionChecker = $permissionChecker;
		$this->grantsStore = $grantsStore;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$performer = new MWAuthorityProxy( $this->getAuthority() );
		if ( !$this->permissionChecker->userCanEditRegistration( $performer, $eventID ) ) {
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

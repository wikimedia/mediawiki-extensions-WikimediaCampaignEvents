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
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;

class DeleteGrantIdHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;

	private IEventLookup $eventLookup;
	private PermissionChecker $permissionChecker;
	private GrantsStore $grantsStore;

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
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$performer = new MWAuthorityProxy( $this->getAuthority() );
		if ( !$this->permissionChecker->userCanEditRegistration( $performer, $registration ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-edit-permission-denied' ),
				403
			);
		}
		$this->grantsStore->deleteGrantID( $eventID );
		return $this->getResponseFactory()->createNoContent();
	}

	/**
	 * @return array[]
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		return $this->getTokenParamDefinition();
	}
}

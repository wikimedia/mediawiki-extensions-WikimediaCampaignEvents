<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\FluxxRequestException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\InvalidGrantIDException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use RuntimeException;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class UpdateGrantIdHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;

	private IEventLookup $eventLookup;
	private PermissionChecker $permissionChecker;
	private GrantsStore $grantsStore;
	private GrantIDLookup $grantIDLookup;

	/**
	 * @param IEventLookup $eventLookup
	 * @param GrantIDLookup $grantIDLookup
	 * @param PermissionChecker $permissionChecker
	 * @param GrantsStore $grantsStore
	 */
	public function __construct(
		IEventLookup $eventLookup,
		GrantIDLookup $grantIDLookup,
		PermissionChecker $permissionChecker,
		GrantsStore $grantsStore
	) {
		$this->eventLookup = $eventLookup;
		$this->grantIDLookup = $grantIDLookup;
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
		$this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$body = $this->getValidatedBody();
		$grantID = $body['grant_id'];
		if ( !$grantID ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-edit-empty' ),
				400
			);
		}

		$performer = new MWAuthorityProxy( $this->getAuthority() );
		if ( !$this->permissionChecker->userCanEditRegistration( $performer, $eventID ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-edit-permission-denied' ),
				403
			);
		}

		$this->tryUpdateGrantId( $grantID, $eventID );

		return $this->getResponseFactory()->createNoContent();
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ): BodyValidator {
		if ( $contentType !== 'application/json' ) {
			return new UnsupportedContentTypeBodyValidator( $contentType );
		}

		return new JsonBodyValidator( [
				'grant_id' => [
					ParamValidator::PARAM_REQUIRED => true,
					ParamValidator::PARAM_TYPE => 'string',
					static::PARAM_SOURCE => 'body'
				],
			] + $this->getTokenParamDefinition() );
	}

	/**
	 * @param string $grantID
	 * @param int $eventID
	 */
	private function tryUpdateGrantId( string $grantID, int $eventID ): void {
		// TODO Avoid duplicating EventRegistrationFormHandler.
		$pattern = "/^\d+-\d+$/";
		if ( !preg_match( $pattern, $grantID ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-edit-invalid' ),
				400
			);
		}

		try {
			$this->grantIDLookup->doLookup( $grantID );
		} catch ( InvalidGrantIDException $_ ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-edit-invalid' ),
				400
			);
		} catch ( FluxxRequestException $_ ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-edit-api-error' ),
				503
			);
		}

		$previousGrantID = $this->grantsStore->getGrantID( $eventID );
		if ( $grantID !== $previousGrantID ) {
			try {
				$grantAgreementAt = $this->grantIDLookup->getAgreementAt( $grantID );
			} catch ( FluxxRequestException | InvalidGrantIDException $e ) {
				throw new RuntimeException( "Could not retrieve agreement_at: $e" );
			}
			$this->grantsStore->updateGrantID( $grantID, $eventID, $grantAgreementAt );
		}
	}
}

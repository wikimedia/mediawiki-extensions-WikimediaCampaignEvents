<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
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
use MediaWiki\Rest\Validator\Validator;
use RuntimeException;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class UpdateGrantIdHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly GrantIDLookup $grantIDLookup,
		private readonly PermissionChecker $permissionChecker,
		private readonly GrantsStore $grantsStore,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	protected function run( int $eventID ): Response {
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$body = $this->getValidatedBody();
		$grantID = $body['grant_id'] ?? null;
		if ( !$grantID ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-edit-empty' ),
				400
			);
		}

		if ( !$this->permissionChecker->userCanEditRegistration( $this->getAuthority(), $registration ) ) {
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
	public function getBodyParamSettings(): array {
		return [
				'grant_id' => [
					ParamValidator::PARAM_REQUIRED => true,
					ParamValidator::PARAM_TYPE => 'string',
					static::PARAM_SOURCE => 'body'
				],
			] + $this->getTokenParamDefinition();
	}

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
		} catch ( InvalidGrantIDException ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'wikimediacampaignevents-rest-grant-id-edit-invalid' ),
				400
			);
		} catch ( FluxxRequestException ) {
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

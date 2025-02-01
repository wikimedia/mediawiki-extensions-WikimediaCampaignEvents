<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormLoadHook;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormSubmitHook;
use MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\FluxxRequestException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception\InvalidGrantIDException;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use RuntimeException;
use StatusValue;

class EventRegistrationFormHandler implements
	CampaignEventsRegistrationFormLoadHook,
	CampaignEventsRegistrationFormSubmitHook
{
	private GrantsStore $grantsStore;
	private GrantIDLookup $grantIDLookup;

	public function __construct(
		GrantsStore $grantsStore,
		GrantIDLookup $grantIDLookup
	) {
		$this->grantsStore = $grantsStore;
		$this->grantIDLookup = $grantIDLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsRegistrationFormLoad( array &$formFields, ?int $eventID ): void {
		$currentGrantID = $eventID ? $this->grantsStore->getGrantID( $eventID ) : '';
		$formFields['GrantID'] = [
			'type' => 'text',
			'label-message' => 'wikimediacampaignevents-grant-id-input-label',
			'default' => $currentGrantID,
			'placeholder-message' => 'wikimediacampaignevents-grant-id-input-placeholder',
			'help-message' => 'wikimediacampaignevents-grant-id-input-help-message',
			'section' => AbstractEventRegistrationSpecialPage::DETAILS_SECTION,
			'filter-callback' => static fn ( $grantID ) => trim( (string)$grantID ),
			'validation-callback' => function ( $grantID, $alldata ) use ( $currentGrantID ) {
				if ( $grantID === '' || $grantID === $currentGrantID ) {
					// Note that if a grant ID was once valid, we don't need to validate it again: it can only
					// become "invalid" if it was granted too long ago, but that must have not been the case when the
					// ID was first stored, so that's fine.
					return StatusValue::newGood();
				}

				$pattern = "/^\d+-\d+$/";
				if ( !preg_match( $pattern, $grantID ) ) {
					return StatusValue::newFatal( 'wikimediacampaignevents-grant-id-invalid-error-message' );
				}

				try {
					return $this->grantIDLookup->doLookup( $grantID );
				} catch ( InvalidGrantIDException $_ ) {
					return StatusValue::newFatal( 'wikimediacampaignevents-grant-id-invalid-error-message' );
				} catch ( FluxxRequestException $_ ) {
					return StatusValue::newFatal( 'wikimediacampaignevents-grant-id-api-fails-error-message' );
				}
			},
		];
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsRegistrationFormSubmit( array $formFields, int $eventID ): void {
		$grantID = $formFields['GrantID'];
		$previousGrantID = $this->grantsStore->getGrantID( $eventID );
		if ( $grantID && $grantID !== $previousGrantID ) {
			try {
				$grantAgreementAt = $this->grantIDLookup->getAgreementAt( $grantID );
			} catch ( FluxxRequestException | InvalidGrantIDException $e ) {
				throw new RuntimeException( "Could not retrieve agreement_at: $e" );
			}
			$this->grantsStore->updateGrantID( $grantID, $eventID, $grantAgreementAt );
		} elseif ( !$grantID && $previousGrantID ) {
			$this->grantsStore->deleteGrantID( $eventID );
		}
	}
}

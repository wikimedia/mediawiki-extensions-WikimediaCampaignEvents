<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormLoadHook;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormSubmitHook;
use MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use RuntimeException;
use StatusValue;

class EventRegistrationFormHandler implements
	CampaignEventsRegistrationFormLoadHook,
	CampaignEventsRegistrationFormSubmitHook
{
	/** @var GrantsStore */
	private $grantsStore;
	/** @var GrantIDLookup */
	private $grantIDLookup;

	/**
	 * @param GrantsStore $grantsStore
	 * @param GrantIDLookup $grantIDLookup
	 */
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
	public function onCampaignEventsRegistrationFormLoad( array &$formFields, ?int $eventID ) {
		$formFields['GrantID'] = [
			'type' => 'text',
			'label-message' => 'wikimediacampaignevents-grant-id-input-label',
			'default' => $eventID ? $this->grantsStore->getGrantID( $eventID ) : '',
			'placeholder-message' => 'wikimediacampaignevents-grant-id-input-placeholder',
			'help-message' => 'wikimediacampaignevents-grant-id-input-help-message',
			'section' => AbstractEventRegistrationSpecialPage::DETAILS_SECTION,
			'validation-callback' => function ( $grantID, $alldata ) {
				if ( $grantID === '' ) {
					return StatusValue::newGood();
				}

				$pattern = "/^\d+-\d+$/";
				if ( preg_match( $pattern, $grantID ) ) {
					return $this->grantIDLookup->doLookup( $grantID );
				}

				return StatusValue::newFatal( 'wikimediacampaignevents-grant-id-invalid-error-message' );
			},
		];

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsRegistrationFormSubmit( array $formFields, int $eventID ): bool {
		$grantID = $formFields['GrantID'];
		if ( $grantID ) {
			$grantAgreementAtStatus = $this->grantIDLookup->getAgreementAt( $grantID );
			if ( !$grantAgreementAtStatus->isGood() ) {
				throw new RuntimeException( "Could not retrieve agreement_at: $grantAgreementAtStatus" );
			}
			$this->grantsStore->updateGrantID( $grantID, $eventID, $grantAgreementAtStatus->getValue() );
		} else {
			$this->grantsStore->deleteGrantID( $eventID );
		}
		return true;
	}
}

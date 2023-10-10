<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormLoadHook;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormSubmitHook;
use MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use StatusValue;

class EventRegistrationFormHandler implements
	CampaignEventsRegistrationFormLoadHook,
	CampaignEventsRegistrationFormSubmitHook
{
	/** @var GrantsStore */
	private $grantsStore;

	/**
	 * @param GrantsStore $grantsStore
	 */
	public function __construct(
		GrantsStore $grantsStore
	) {
		$this->grantsStore = $grantsStore;
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
			'validation-callback' => static function ( $grantID, $alldata ) {
				$pattern = "/^\d+-\d+$/";
				if ( preg_match( $pattern, $grantID ) || $grantID === '' ) {
					return StatusValue::newGood();
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
		if ( $formFields['GrantID'] ) {
			// TODO - Get the real grant_agreement_at to send here for the third parameter
			$this->grantsStore->updateGrantID( $formFields['GrantID'], $eventID, wfTimestampNow() );
		} else {
			$this->grantsStore->deleteGrantID( $eventID );
		}
		return true;
	}
}

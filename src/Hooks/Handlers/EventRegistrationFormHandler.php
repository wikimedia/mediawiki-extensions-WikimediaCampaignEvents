<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormLoadHook;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormSubmitHook;
use MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage;
use StatusValue;

class EventRegistrationFormHandler implements
	CampaignEventsRegistrationFormLoadHook,
	CampaignEventsRegistrationFormSubmitHook
{

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsRegistrationFormLoad( array &$formFields, ?int $eventID ) {
		// TDB get the grandID if it exists and set as the default value
		$formFields['GrantID'] = [
			'type' => 'text',
			'label-message' => 'wikimediacampaignevents-grant-id-input-label',
			'default' => '',
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
		// TODO implementation
		return true;
	}
}

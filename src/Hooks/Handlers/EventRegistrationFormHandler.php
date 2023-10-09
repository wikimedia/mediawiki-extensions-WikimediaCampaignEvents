<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsRegistrationFormLoadHook;
use MediaWiki\Extension\CampaignEvents\Special\AbstractEventRegistrationSpecialPage;

class EventRegistrationFormHandler implements CampaignEventsRegistrationFormLoadHook {

	/**
	 * @param array &$formFields
	 * @param int|null $eventID
	 * @return true
	 */
	public function onCampaignEventsRegistrationFormLoad( array &$formFields, ?int $eventID ) {
		// TDB get the grandID if it exists and set as the default value
		$formFields['GrantID'] = [
			'type' => 'text',
			'label-message' => 'wikimediacampaignevents-grant-id-input-label',
			'default' => '',
			'placeholder-message' => 'wikimediacampaignevents-grant-id-input-placeholder',
			'help-message' => 'wikimediacampaignevents-grant-id-input-help-message',
			'section' => AbstractEventRegistrationSpecialPage::DETAILS_SECTION
		];

		return true;
	}
}

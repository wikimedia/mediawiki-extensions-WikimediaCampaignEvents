<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\FrontendModules\EventDetailsModule;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsGetEventDetailsHook;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Output\OutputPage;
use OOUI\Tag;

class EventDetailsHandler implements CampaignEventsGetEventDetailsHook {
	public function __construct(
		private readonly GrantsStore $grantsStore,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetEventDetails(
		Tag $infoColumn,
		Tag $organizersColumn,
		int $eventID,
		bool $isOrganizer,
		OutputPage $outputPage,
		bool $isLocalWiki
	): void {
		if ( !$isOrganizer || !$isLocalWiki ) {
			return;
		}
		$grantID = $this->grantsStore->getGrantID( $eventID );
		if ( $grantID ) {
			$outputPage->addModuleStyles( 'oojs-ui.styles.icons-editing-advanced' );

			$grantIDElement = EventDetailsModule::makeSection(
				'templateAdd',
				$grantID,
				$outputPage->msg( 'wikimediacampaignevents-grant-id-event-details-label' )->text()
			);

			$organizersColumn->appendContent( $grantIDElement );
		}
	}
}

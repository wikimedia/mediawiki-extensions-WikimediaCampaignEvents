<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\FrontendModules\EventDetailsModule;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsGetEventDetailsHook;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use OOUI\Tag;
use OutputPage;

class EventDetailsHandler implements CampaignEventsGetEventDetailsHook {
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
	public function onCampaignEventsGetEventDetails(
		Tag $infoColumn,
		Tag $organizersColumn,
		int $eventID,
		bool $isOrganizer,
		OutputPage $outputPage
	): bool {
		if ( !$isOrganizer ) {
			return true;
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

		return true;
	}
}

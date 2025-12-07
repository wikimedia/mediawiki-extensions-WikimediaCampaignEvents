<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\SpecialPageFactory;

/**
 * FY25 WE2.1.1 Hook handler
 *
 * Modifies Special:HomePage links to include source tracking.
 *
 * Read more https://phabricator.wikimedia.org/T402496
 */
class BeforePageDisplayHandler implements BeforePageDisplayHook {

	private const EXPERIMENT_PAGES = [
		'Language_Experiment_2025',
		'RANCANGAN_TES_ACARA',
		'نضم_لينا!'
	];

	private const EXPECTED_BANNER = 'fy25-we211-banner1';

	public function __construct(
		private readonly SpecialPageFactory $specialPageFactory,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// Check if conditions are met to load the FY25 WE2.1.1 module
		if ( $this->shouldLoadModule( $out ) ) {
			$out->addModules( 'ext.campaignEvents.fy25-we211' );
			$out->addJsConfigVars(
				'wgSpecialHomepageLocalName', $this->specialPageFactory->getLocalNameFor( 'Homepage' )
			);
		}
	}

	/**
	 * Check if the module should be loaded for this page.
	 *
	 * @param OutputPage $out The OutputPage object
	 * @return bool True if the module should be loaded, false otherwise
	 */
	private function shouldLoadModule( OutputPage $out ): bool {
		$title = $out->getTitle();
		if ( $title->getNamespace() !== NS_EVENT ) {
			return false;
		}

		if ( !in_array( $title->getDBkey(), self::EXPERIMENT_PAGES, true ) ) {
			return false;
		}

		if ( $out->getContext()->getActionName() !== 'view' ) {
			return false;
		}

		if ( $out->getRequest()->getVal( 'banner' ) !== self::EXPECTED_BANNER ) {
			return false;
		}

		return true;
	}
}

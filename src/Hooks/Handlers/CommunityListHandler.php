<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsGetCommunityListHook;
use MediaWiki\Extension\CampaignEvents\Special\SpecialAllEvents;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWikiProjectsException;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\WikiProjectFullLookup;
use MediaWiki\Html\TemplateParser;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\Tag;
use OutputPage;

class CommunityListHandler implements CampaignEventsGetCommunityListHook {
	private TemplateParser $templateParser;
	private string $activeTab;
	private WikiProjectFullLookup $wikiProjectLookup;

	public function __construct( WikiProjectFullLookup $wikiProjectLookup ) {
		$this->wikiProjectLookup = $wikiProjectLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetCommunityList(
		OutputPage $outputPage,
		string &$eventsContent

	): void {
		if ( $outputPage->getConfig()->get( 'WikimediaCampaignEventsEnableCommunityList' ) ) {
			$this->templateParser = new TemplateParser( __DIR__ . '/../../../templates' );
			$outputPage->addModuleStyles( 'codex-styles' );
			$outputPage->setPageTitleMsg( $outputPage->msg( 'wikimediacampaignevents-communitylist-title' ) );
			$this->activeTab = $outputPage->getRequest()->getVal( 'tab', 'form-tabs-0' );
			$communityContent = $this->getCommunityListContent( $outputPage );
			$eventsContent = $this->getLayout(
				[
					[
						'content' => $eventsContent,
						'label' => $outputPage->msg(
							'wikimediacampaignevents-communitylist-events-tab-heading'
						)->text()
					],
					[
						'content' => ( new Tag( 'p' ) )
								->appendContent( $outputPage->msg(
									'wikimediacampaignevents-communitylist-header-text' )->text()
								) . $communityContent,
						'label' => $outputPage->msg(
							'wikimediacampaignevents-communitylist-communities-tab-heading' )->text()
					]
				]
			);
		}
	}

	/**
	 * @param OutputPage $outputPage
	 * @return string
	 */
	private function getCommunityListContent( OutputPage $outputPage ): string {
		try {
			$wikiProjects = $this->wikiProjectLookup->getWikiProjects( $outputPage->getLanguage()->getCode(), 10 );
		} catch ( CannotQueryWikiProjectsException $cannotQueryWikiProjectsException ) {
			// Todo:display error to user
			$wikiProjects = [];
		}
		$cards = [];
		if ( count( $wikiProjects ) === 0 ) {
			return $this->templateParser->processTemplate(
				'Message',
				[
					'Classes' => 'ext-campaignevents-community-list-empty-state',
					'IconClass' => 'page',
					'Title' => $outputPage->msg( 'wikimediacampaignevents-communitylist-no-events-title' )->text(),
					'Text' => $outputPage->msg( 'wikimediacampaignevents-communitylist-no-events-text' )->text()
				]
			);
		} else {
			foreach ( $wikiProjects as $wikiProject ) {
				$properties = [
					'Classes' => 'ext-campaignevents-community-list-wikiproject',
					'Title' => $wikiProject['label'],
					'Description' => $wikiProject['description'],
					'Url' => $wikiProject['sitelink'],
				];
				$cards[] = $this->templateParser->processTemplate(
					'Card',
					$properties
				);
			}
			return implode( '', $cards );
		}
	}

	private function getLayout( array $tabs ): string {
		$data = [
			'url' => SpecialPage::getTitleFor( SpecialAllEvents::PAGE_NAME )->getLocalURL(),
			'pageTitle' => 'Special:' . SpecialAllEvents::PAGE_NAME,
		];
		foreach ( $tabs as $i => $tab ) {
			$active = $this->activeTab === "form-tabs-$i";
			$data['tabs'][] =
				[
					'id' => $i,
					'content' => $tab['content'],
					'label' => $tab['label'],
					'active' => wfBoolToStr( $active ),
					'hidden' => wfBoolToStr( !$active ),
				];

		}
		return $this->templateParser->processTemplate( 'TabLayout', $data );
	}
}

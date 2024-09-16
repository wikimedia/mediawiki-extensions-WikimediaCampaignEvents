<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use LogicException;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsGetCommunityListHook;
use MediaWiki\Extension\CampaignEvents\Special\SpecialAllEvents;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWDQSException;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWikibaseException;
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
			$hasWikiProjects = $this->wikiProjectLookup->hasWikiProjects();
		} catch ( CannotQueryWDQSException $cannotQueryWikiProjectsException ) {
			return $this->getErrorTemplate( $outputPage, $cannotQueryWikiProjectsException );
		}

		if ( !$hasWikiProjects ) {
			return $this->getEmptyStateContent( $outputPage );
		}

		try {
			$wikiProjects = $this->wikiProjectLookup->getWikiProjects( $outputPage->getLanguage()->getCode(), 10 );
		} catch ( CannotQueryWDQSException | CannotQueryWikibaseException $cannotQueryWikiProjectsException ) {
			return $this->getErrorTemplate( $outputPage, $cannotQueryWikiProjectsException );
		}

		return $this->getWikiProjectsHTML( $wikiProjects );
	}

	private function getEmptyStateContent( OutputPage $outputPage ): string {
		return $this->templateParser->processTemplate(
			'Message',
			[
				'Classes' => 'ext-campaignevents-community-list-empty-state',
				'IconClass' => 'page',
				'Type' => 'notice',
				'Title' => $outputPage->msg( 'wikimediacampaignevents-communitylist-no-events-title' )->text(),
				'Text' => $outputPage->msg( 'wikimediacampaignevents-communitylist-no-events-text' )->parse()
			]
		);
	}

	private function getWikiProjectsHTML( array $wikiProjects ): string {
		$cards = [];
		foreach ( $wikiProjects as $qid => $wikiProject ) {
			if ( $wikiProject === null ) {
				continue;
			}
			$properties = [
				'Classes' => 'ext-campaignevents-community-list-wikiproject',
				'Title' => $wikiProject['label'] !== '' ? $wikiProject['label'] : $qid,
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

	/**
	 * @param OutputPage $outputPage
	 * @return string
	 */
	public function getErrorTemplate( OutputPage $outputPage, CannotQueryWikiProjectsException $exception ): string {
		if ( $exception instanceof CannotQueryWikibaseException ) {
			$messageKey = 'wikimediacampaignevents-communitylist-wikidata-api-error-text';
		} elseif ( $exception instanceof CannotQueryWDQSException ) {
			$messageKey = 'wikimediacampaignevents-communitylist-wdqs-api-error-text';
		} else {
			throw new LogicException( 'Unexpected exception type: ' . get_class( $exception ) );
		}

		return $this->templateParser->processTemplate(
			'Message',
			[
				'Type' => 'error',
				'Classes' => 'ext-campaignevents-community-list-api-error',
				'Title' => $outputPage->msg( 'wikimediacampaignevents-communitylist-api-error-title' )->text(),
				'Text' => $outputPage->msg( $messageKey )->parse()
			]
		);
	}
}

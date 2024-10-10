<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use LogicException;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsGetAllEventsContentHook;
use MediaWiki\Extension\CampaignEvents\Special\SpecialAllEvents;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWDQSException;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWikibaseException;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWikiProjectsException;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\WikiProjectFullLookup;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Navigation\PagerNavigationBuilder;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\Tag;
use OutputPage;

class CollaborationListHandler implements CampaignEventsGetAllEventsContentHook {
	private TemplateParser $templateParser;
	private string $activeTab;
	private WikiProjectFullLookup $wikiProjectLookup;

	public function __construct( WikiProjectFullLookup $wikiProjectLookup ) {
		$this->wikiProjectLookup = $wikiProjectLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetAllEventsContent(
		OutputPage $outputPage,
		string &$eventsContent
	): void {
		if ( $outputPage->getConfig()->get( 'WikimediaCampaignEventsEnableCommunityList' ) ) {
			$this->templateParser = new TemplateParser( __DIR__ . '/../../../templates' );
			$outputPage->addModuleStyles( 'codex-styles' );
			$outputPage->setPageTitleMsg( $outputPage->msg( 'wikimediacampaignevents-collaboration-list-title' ) );
			$this->activeTab = $outputPage->getRequest()->getVal( 'tab', 'form-tabs-0' );
			$collaborationListContent = $this->getCollaborationListContent( $outputPage );
			$eventsContent = $this->getLayout(
				[
					[
						'content' => $eventsContent,
						'label' => $outputPage->msg(
							'wikimediacampaignevents-collaboration-list-events-tab-heading'
						)->text()
					],
					[
						'content' => ( new Tag( 'p' ) )
								->appendContent( $outputPage->msg(
									'wikimediacampaignevents-collaboration-list-header-text' )->text()
								) . $collaborationListContent,
						'label' => $outputPage->msg(
							'wikimediacampaignevents-collaboration-list-communities-tab-heading' )->text()
					]
				]
			);
		}
	}

	/**
	 * @param OutputPage $outputPage
	 * @return string
	 */
	private function getCollaborationListContent( OutputPage $outputPage ): string {
		try {
			$hasWikiProjects = $this->wikiProjectLookup->hasWikiProjects();
		} catch ( CannotQueryWDQSException $cannotQueryWikiProjectsException ) {
			return $this->getErrorTemplate( $outputPage, $cannotQueryWikiProjectsException );
		}

		if ( !$hasWikiProjects ) {
			return $this->getEmptyStateContent( $outputPage );
		}

		$request = $outputPage->getRequest();
		// Don't allow a limit > 50, as that would require at least two Wikidata API calls. The code supports that,
		// but it might be too slow.
		$limit = min( $request->getInt( 'limit', 20 ), 50 );
		$offset = $request->getVal( 'offset' );
		$direction = $request->getInt( 'dir' );
		// Make sure it's a recognised value, and go forwards by default.
		if (
			$direction !== WikiProjectFullLookup::DIR_FORWARDS &&
			$direction !== WikiProjectFullLookup::DIR_BACKWARDS
		) {
			$direction = WikiProjectFullLookup::DIR_FORWARDS;
		}

		try {
			$wikiProjects = $this->wikiProjectLookup->getWikiProjects(
				$outputPage->getLanguage()->getCode(),
				$limit,
				$offset,
				$direction
			);
		} catch ( CannotQueryWDQSException $cannotQueryWikiProjectsException ) {
			return $this->getErrorTemplate( $outputPage, $cannotQueryWikiProjectsException );
		}

		$navBuilder = $this->getNavigationBuilder( $outputPage, $offset, $limit, $direction, $wikiProjects );

		return $navBuilder->getHtml() . $this->getWikiProjectsHTML( $wikiProjects );
	}

	private function getEmptyStateContent( OutputPage $outputPage ): string {
		return $this->templateParser->processTemplate(
			'Message',
			[
				'Classes' => 'ext-campaignevents-collaboration-list-empty-state',
				'IconClass' => 'page',
				'Type' => 'notice',
				'Title' => $outputPage->msg( 'wikimediacampaignevents-collaboration-list-no-events-title' )->text(),
				'Text' => $outputPage->msg( 'wikimediacampaignevents-collaboration-list-no-events-text' )->parse()
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
				'Classes' => 'ext-campaignevents-collaboration-list-wikiproject',
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
			$messageKey = 'wikimediacampaignevents-collaboration-list-wikidata-api-error-text';
		} elseif ( $exception instanceof CannotQueryWDQSException ) {
			$messageKey = 'wikimediacampaignevents-collaboration-list-wdqs-api-error-text';
		} else {
			throw new LogicException( 'Unexpected exception type: ' . get_class( $exception ) );
		}

		return $this->templateParser->processTemplate(
			'Message',
			[
				'Type' => 'error',
				'Classes' => 'ext-campaignevents-collaboration-list-api-error',
				'Title' => $outputPage->msg( 'wikimediacampaignevents-collaboration-list-api-error-title' )->text(),
				'Text' => $outputPage->msg( $messageKey )->parse()
			]
		);
	}

	private function getNavigationBuilder(
		IContextSource $context,
		?string $offset,
		int $limit,
		int $direction,
		array $wikiProjects
	): PagerNavigationBuilder {
		// Figure out what pagination links we need to show. For the previous page, there's no other way but to run a
		// query in the opposite direction. For the next page, we could in theory query $limit + 1 entities and see if
		// we get all $limit + 1 of them. However, that would prevent us from allowing the standard limit of 50
		// elements in the UI, because we would need to query 51 items from Wikidata, which can only be done in two
		// Wikidata API calls instead of just one (which is not ideal for performance).
		$hasMoreWikiProjectsInOppositeDirection = false;
		if ( $offset !== null && $this->wikiProjectLookup->isKnownEntity( $offset ) ) {
			$hasMoreWikiProjectsInOppositeDirection = $this->wikiProjectLookup->hasWikiProjectsAfter(
				$offset,
				WikiProjectFullLookup::invertDirection( $direction )
			);
		}
		if ( $direction === WikiProjectFullLookup::DIR_FORWARDS ) {
			$isLastPage = !$this->wikiProjectLookup->hasWikiProjectsAfter(
				array_key_last( $wikiProjects ),
				$direction
			);
			$isFirstPage = !$hasMoreWikiProjectsInOppositeDirection;
		} else {
			$isFirstPage = !$this->wikiProjectLookup->hasWikiProjectsAfter(
				array_key_first( $wikiProjects ),
				$direction
			);
			$isLastPage = !$hasMoreWikiProjectsInOppositeDirection;
		}

		$navBuilder = new PagerNavigationBuilder( $context );
		$navBuilder
			->setPage( $context->getTitle() )
			->setLinkQuery( [
				'tab' => $this->activeTab,
				'dir' => null,
				'offset' => null,
				'limit' => null,
			] )
			->setFirstMsg( 'page_first' )
			->setLastMsg( 'page_last' )
			->setLimits( [ 10, 20, 50 ] )
			->setCurrentLimit( $limit );

		// TODO: Remove string casts when I5c6b8ad8075d295666a6a04d8c95398dfd9f4060 is merged.
		if ( !$isFirstPage ) {
			$navBuilder->setPrevLinkQuery( [
				'offset' => (string)array_key_first( $wikiProjects ),
				'dir' => (string)WikiProjectFullLookup::DIR_BACKWARDS,
				'limit' => (string)$limit,
			] );
			$navBuilder->setFirstLinkQuery( [
				'dir' => (string)WikiProjectFullLookup::DIR_FORWARDS,
				'limit' => (string)$limit,
			] );
		}
		if ( !$isLastPage ) {
			$navBuilder->setNextLinkQuery( [
				'offset' => (string)array_key_last( $wikiProjects ),
				'dir' => (string)WikiProjectFullLookup::DIR_FORWARDS,
				'limit' => (string)$limit,
			] );
			$navBuilder->setLastLinkQuery( [
				'dir' => (string)WikiProjectFullLookup::DIR_BACKWARDS,
				'limit' => (string)$limit,
			] );
		}
		return $navBuilder;
	}
}

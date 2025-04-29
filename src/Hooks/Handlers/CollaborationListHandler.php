<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use LogicException;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsGetAllEventsTabsHook;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWDQSException;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWikibaseException;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\CannotQueryWikiProjectsException;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\WikiProjectFullLookup;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Navigation\PagerNavigationBuilder;
use MediaWiki\Output\OutputPage;
use MessageLocalizer;
use OOUI\Tag;

class CollaborationListHandler implements CampaignEventsGetAllEventsTabsHook {
	private const COMMUNITIES_TAB = 'communities';
	private TemplateParser $templateParser;
	private string $activeTab;
	private WikiProjectFullLookup $wikiProjectLookup;

	public function __construct( WikiProjectFullLookup $wikiProjectLookup ) {
		$this->templateParser = new TemplateParser( __DIR__ . '/../../../templates' );
		$this->wikiProjectLookup = $wikiProjectLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetAllEventsTabs(
		OutputPage $outputPage,
		array &$tabs,
		string $activeTab
	): void {
		$this->activeTab = $activeTab;
		$outputPage->addModuleStyles( [
			'oojs-ui.styles.icons-editing-core',
		] );
		$outputPage->setPageTitleMsg( $outputPage->msg( 'wikimediacampaignevents-collaboration-list-title' ) );
		$collaborationListContent = $this->getCollaborationListContent( $outputPage );
		$tabs[self::COMMUNITIES_TAB] = [
			'content' => ( new Tag( 'p' ) )
				->appendContent( $outputPage->msg(
					'wikimediacampaignevents-collaboration-list-header-text' )->text()
				) . $collaborationListContent,
			'label' => $outputPage->msg( 'wikimediacampaignevents-collaboration-list-communities-tab-heading' )->text()
		];
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
		} catch ( CannotQueryWDQSException | CannotQueryWikibaseException $cannotQueryWikiProjectsException ) {
			return $this->getErrorTemplate( $outputPage, $cannotQueryWikiProjectsException );
		}

		$navBuilder = $this->getNavigationBuilder( $outputPage, $offset, $limit, $direction, $wikiProjects );

		return $navBuilder->getHtml() . $this->getWikiProjectsHTML( $outputPage, $wikiProjects );
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

	private function getWikiProjectsHTML( MessageLocalizer $msgLocalizer, array $wikiProjects ): string {
		$cards = [];
		foreach ( $wikiProjects as $qid => $wikiProject ) {
			if ( $wikiProject === null ) {
				continue;
			}
			$editURL = 'https://wikidata.org/wiki/' . $qid;
			$linkedLabel = Html::element(
				'a',
				[ 'href' => $wikiProject['sitelink'] ],
				$wikiProject['label'] !== '' ? $wikiProject['label'] : $qid
			);

			$editLabel = $msgLocalizer->msg( 'wikimediacampaignevents-collaboration-list-wikidata-edit-label' )->text();
			$editButton = $this->templateParser->processTemplate( 'Button', [
				'Href' => $editURL,
				'Label' => $editLabel,
				'Title' => $editLabel,
				'Classes' => 'wikimediacampaignevents-collaboration-list-edit-button'
			] );
			$properties = [
				'Classes' => 'ext-campaignevents-collaboration-list-wikiproject',
				'Title' => $linkedLabel . $editButton,
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

		if ( !$isFirstPage ) {
			$navBuilder->setPrevLinkQuery( [
				'offset' => array_key_first( $wikiProjects ),
				'dir' => WikiProjectFullLookup::DIR_BACKWARDS,
				'limit' => $limit,
			] );
			$navBuilder->setFirstLinkQuery( [
				'dir' => WikiProjectFullLookup::DIR_FORWARDS,
				'limit' => $limit,
			] );
		}
		if ( !$isLastPage ) {
			$navBuilder->setNextLinkQuery( [
				'offset' => array_key_last( $wikiProjects ),
				'dir' => WikiProjectFullLookup::DIR_FORWARDS,
				'limit' => $limit,
			] );
			$navBuilder->setLastLinkQuery( [
				'dir' => WikiProjectFullLookup::DIR_BACKWARDS,
				'limit' => $limit,
			] );
		}
		return $navBuilder;
	}
}

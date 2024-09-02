<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsGetCommunityListHook;
use MediaWiki\Html\TemplateParser;
use OOUI\Tag;
use OutputPage;

class CommunityListHandler implements CampaignEventsGetCommunityListHook {
	private TemplateParser $templateParser;
	private string $activeTab;

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
		return $this->templateParser->processTemplate(
			'Message',
			[
				'Classes' => 'ext-campaignevents-community-list-empty-state',
				'IconClass' => 'page',
				'Title' => $outputPage->msg( 'wikimediacampaignevents-communitylist-no-events-title' )->text(),
				'Text' => $outputPage->msg( 'wikimediacampaignevents-communitylist-no-events-text' )->text()
			]
		);
	}

	/**
	 * @param array $tabs
	 * @return string
	 */
	private function getLayout( array $tabs ) {
		$data = [];
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

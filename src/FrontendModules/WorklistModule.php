<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\WorklistPageEventIngress;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPagerFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\Tag;

readonly class WorklistModule {

	public function __construct(
		private WorklistPagesPagerFactory $worklistPagesPagerFactory,
		private WikiLookup $wikiLookup,
		private LinkRenderer $linkRenderer,
		private OutputPage $output,
		private ExistingEventRegistration $event,
	) {
	}

	public function createContent(): Tag {
		$this->output->addModuleStyles( 'codex-styles' );
		// Expose the worklist page title so the frontend can target the worklist pages REST
		// endpoint (PATCH /worklist/{title}/pages). When the worklist page lives on another wiki,
		// also expose that wiki's rest.php URL so the client can target it via mw.ForeignRest; it is
		// null for a local worklist page.
		$worklistWikiId = $this->event->getPage()->getWikiId();
		$worklistWikiRestUrl = null;
		if ( $worklistWikiId !== WikiAwareEntity::LOCAL ) {
			$foreignWiki = WikiMap::getWiki( $worklistWikiId );
			if ( $foreignWiki ) {
				// Prefer the foreign wiki's own RestPath (from $wgConf), so this works on farms
				// where wikis share a domain but differ by path. Fall back to the local RestPath
				// when it can't be resolved (no $wgConf, or RestPath not overridden per-wiki),
				// which matches the previous behaviour. See T312568.
				$restPath = $this->wikiLookup->getRestPath( $worklistWikiId )
					?? $this->output->getConfig()->get( MainConfigNames::RestPath );
				$worklistWikiRestUrl = $foreignWiki->getCanonicalServer() . $restPath;
			}
		}
		$this->output->addJsConfigVars( [
			'wgCampaignEventsWorklistPagePrefixedText' =>
				$this->event->getPage()->getPrefixedText() . '/' . WorklistPageEventIngress::WORKLIST_SUBPAGE,
			'wgCampaignEventsWorklistWikiRestUrl' => $worklistWikiRestUrl,
		] );

		$pager = $this->worklistPagesPagerFactory->newPager(
			$this->output->getContext(),
			$this->linkRenderer,
			$this->event
		);
		// Keep the Worklist tab active when interacting with the pager.
		$pager->setExtraQuery( [ 'tab' => 'WorklistPanel' ] );

		$container = new Tag( 'div' );
		$container->appendContent( new HtmlSnippet( $pager->getFullOutput()->getContentHolderText() ) );
		return $container;
	}
}

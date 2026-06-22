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
use MediaWiki\Title\Title;
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
		// Expose the worklist page details for the frontend:
		// - the prefixed title (always), used to build the REST path PATCH /worklist/{title}/pages;
		// - the page URL, for the view-page link;
		// - for a foreign worklist page, that wiki's rest.php URL so the client uses mw.ForeignRest
		//   (null for a local page).
		$eventPage = $this->event->getPage();
		$eventWikiId = $eventPage->getWikiId();
		$eventPagePrefixedText = $eventPage->getPrefixedText()
			. '/' . WorklistPageEventIngress::WORKLIST_SUBPAGE;
		$worklistPageUrl = '';
		$worklistWikiRestUrl = null;
		if ( $eventWikiId === WikiAwareEntity::LOCAL ) {
			$eventTitle = Title::newFromPageIdentity( $eventPage->getPageIdentity() );
			$worklistTitle = $eventTitle->getSubpage(
				WorklistPageEventIngress::WORKLIST_SUBPAGE
			);
			if ( $worklistTitle ) {
				$worklistPageUrl = $worklistTitle->getLocalURL();
			}
		} else {
			$foreignWiki = WikiMap::getWiki( $eventWikiId );
			if ( $foreignWiki ) {
				// Prefer the foreign wiki's own RestPath (from $wgConf), so this works on farms
				// where wikis share a domain but differ by path. Fall back to the local RestPath
				// when it can't be resolved (no $wgConf, or RestPath not overridden per-wiki),
				// which matches the previous behaviour. See T312568.
				$restPath = $this->wikiLookup->getRestPath( $eventWikiId )
					?? $this->output->getConfig()->get( MainConfigNames::RestPath );
				$worklistWikiRestUrl = $foreignWiki->getCanonicalServer() . $restPath;
			}
		}
		$this->output->addJsConfigVars( [
			'wgCampaignEventsWorklistPagePrefixedText' => $eventPagePrefixedText,
			'wgCampaignEventsWorklistPageUrl' => $worklistPageUrl,
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
		$container->addClasses( [ 'ext-campaignevents-worklist-table' ] );
		$container->appendContent( new HtmlSnippet( $pager->getFullOutput()->getContentHolderText() ) );
		return $container;
	}
}

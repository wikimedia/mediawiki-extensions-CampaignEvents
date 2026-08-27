<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\WorklistPageEventIngress;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPagerFactory;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\Tag;

readonly class WorklistModule {

	/**
	 * Request parameter selecting how the worklist is presented. Where
	 * CampaignEventsEnableWorklistCardView is enabled the card view is the default and this
	 * selects the table view instead; where it is not, the table view is all there is.
	 */
	public const VIEW_PARAM = 'worklistview';
	public const VIEW_CARDS = 'cards';
	public const VIEW_TABLE = 'table';

	/** Placeholder cards drawn while the app loads; roughly one screenful. */
	private const SKELETON_CARDS = 6;

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
		// - the page URL, for the link to the worklist page below the list;
		// - the page history URL, for the history control (both empty for a foreign worklist page);
		// - for a foreign worklist page, that wiki's rest.php URL so the client uses mw.ForeignRest
		//   (null for a local page).
		$eventPage = $this->event->getPage();
		$eventWikiId = $eventPage->getWikiId();
		$eventPagePrefixedText = $eventPage->getPrefixedText()
			. '/' . WorklistPageEventIngress::WORKLIST_SUBPAGE;
		$worklistPageUrl = '';
		$worklistPageHistoryUrl = '';
		$worklistWikiRestUrl = null;
		if ( $eventWikiId === WikiAwareEntity::LOCAL ) {
			$eventTitle = Title::newFromPageIdentity( $eventPage->getPageIdentity() );
			$worklistTitle = $eventTitle->getSubpage(
				WorklistPageEventIngress::WORKLIST_SUBPAGE
			);
			if ( $worklistTitle ) {
				$worklistPageUrl = $worklistTitle->getLocalURL();
				$worklistPageHistoryUrl = $worklistTitle->getLocalURL( [ 'action' => 'history' ] );
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
			'wgCampaignEventsWorklistEventId' => $this->event->getID(),
			'wgCampaignEventsWorklistPagePrefixedText' => $eventPagePrefixedText,
			// Empty for an event on another wiki, where the subpage cannot be resolved locally; the
			// frontend hides the history control in that case.
			'wgCampaignEventsWorklistPageUrl' => $worklistPageUrl,
			'wgCampaignEventsWorklistPageHistoryUrl' => $worklistPageHistoryUrl,
			'wgCampaignEventsWorklistWikiRestUrl' => $worklistWikiRestUrl,
		] );

		$container = new Tag( 'div' );
		if ( $this->getRequestedView() === self::VIEW_TABLE ) {
			$container->addClasses( [ 'ext-campaignevents-worklist-table' ] );
			$container->appendContent( new HtmlSnippet( $this->renderTableView() ) );
		} else {
			$container->addClasses( [ 'ext-campaignevents-worklist' ] );
			$container->appendContent( new HtmlSnippet( $this->renderCardView() ) );
		}
		return $container;
	}

	/**
	 * Which presentation the request asks for. Anything unrecognised falls back to the default
	 * rather than erroring: this is a URL parameter a reader may well have typed by hand.
	 */
	private function getRequestedView(): string {
		if ( !$this->output->getConfig()->get( 'CampaignEventsEnableWorklistCardView' ) ) {
			// Where the feature is off there is only the table view, whatever the URL asks for.
			return self::VIEW_TABLE;
		}
		return $this->output->getRequest()->getVal( self::VIEW_PARAM ) === self::VIEW_TABLE
			? self::VIEW_TABLE
			: self::VIEW_CARDS;
	}

	/**
	 * Query parameters that every link the pager generates has to keep, so that paging does not
	 * drop the reader back onto another tab or into the other presentation.
	 *
	 * @return array<string,string>
	 */
	private function getPagerExtraQuery(): array {
		return [
			'tab' => 'WorklistPanel',
			self::VIEW_PARAM => $this->getRequestedView(),
		];
	}

	/**
	 * The card view is rendered by the frontend, which reads the worklist a page at a time so that
	 * paging needs no reload. What the server emits is the element the app mounts on, holding
	 * placeholder cards so that the tab is not blank while the first request is in flight, and a
	 * pointer to the table view for readers without JavaScript.
	 */
	private function renderCardView(): string {
		$this->output->addModules( 'ext.campaignEvents.specialPages' );

		return Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-worklist-app' ],
			$this->getSkeleton()
		);
	}

	/**
	 * Placeholder cards shown until the app has loaded the worklist. Hidden from assistive
	 * technology, which is told the list is loading instead.
	 */
	private function getSkeleton(): string {
		$cards = '';
		for ( $i = 0; $i < self::SKELETON_CARDS; $i++ ) {
			$cards .= Html::element( 'div', [ 'class' => 'ext-campaignevents-worklist-skeleton-card' ] );
		}
		return Html::rawElement(
			'div',
			[
				'class' => 'ext-campaignevents-worklist-skeleton',
				'role' => 'status',
				'aria-label' => $this->output->msg(
					'campaignevents-event-details-worklist-loading'
				)->text(),
			],
			Html::element( 'div', [ 'class' => 'ext-campaignevents-worklist-skeleton-toolbar' ] )
				. Html::rawElement(
					'div',
					[ 'class' => 'ext-campaignevents-worklist-cards', 'aria-hidden' => 'true' ],
					$cards
				)
		);
	}

	private function renderTableView(): string {
		$pager = $this->worklistPagesPagerFactory->newPager(
			$this->output->getContext(),
			$this->linkRenderer,
			$this->event
		);
		$pager->setExtraQuery( $this->getPagerExtraQuery() );
		return $pager->getFullOutput()->getContentHolderText();
	}
}

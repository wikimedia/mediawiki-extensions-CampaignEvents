<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\WorklistPageEventIngress;
use MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPagerFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use OOUI\HtmlSnippet;
use OOUI\Tag;

readonly class WorklistModule {

	public function __construct(
		private WorklistPagesPagerFactory $worklistPagesPagerFactory,
		private LinkRenderer $linkRenderer,
		private OutputPage $output,
		private ExistingEventRegistration $event,
	) {
	}

	public function createContent(): Tag {
		$this->output->addModuleStyles( 'codex-styles' );
		// Expose the worklist page title so the frontend can target the worklist pages REST
		// endpoint (PATCH /worklist/{title}/pages).
		$this->output->addJsConfigVars( [
			'wgCampaignEventsWorklistPagePrefixedText' =>
				$this->event->getPage()->getPrefixedText() . '/' . WorklistPageEventIngress::WORKLIST_SUBPAGE,
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

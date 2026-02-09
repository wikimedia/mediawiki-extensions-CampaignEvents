<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsPagerFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use OOUI\HtmlSnippet;
use OOUI\Tag;

readonly class EventContributionEditorsModule {

	public function __construct(
		private EventContributionsPagerFactory $eventContributionsPagerFactory,
		private LinkRenderer $linkRenderer,
		private OutputPage $output,
		private ExistingEventRegistration $event,
	) {
	}

	public function createContent(): Tag {
		$container = new Tag();
		$this->output->addModuleStyles( 'codex-styles' );
		$container->addClasses( [ 'ext-campaignevents-editors-container' ] );

		$pager = $this->eventContributionsPagerFactory->newEditorsPager(
			$this->output->getContext(),
			$this->linkRenderer,
			$this->event
		);

		// Keep the Contributions tab active when interacting with the pager
		$pager->setExtraQuery( [ 'tab' => 'ContributionsPanel' ] );

		$tableContainer = new Tag( 'div' );
		$tableContainer->addClasses( [ 'ext-campaignevents-editors-table' ] );

		$tableHtml = $pager->getFullOutput()->getContentHolderText();
		$tableContainer->appendContent( new HtmlSnippet( $tableHtml ) );

		$container->appendContent( $tableContainer );
		return $container;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPagerFactory;
use SpecialPage;

class SpecialMyEvents extends SpecialPage {
	/** @var EventsPagerFactory */
	private $eventsPagerFactory;

	/**
	 * @param EventsPagerFactory $eventsPagerFactory
	 */
	public function __construct( EventsPagerFactory $eventsPagerFactory ) {
		parent::__construct( 'MyEvents' );
		$this->eventsPagerFactory = $eventsPagerFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ): void {
		$this->requireLogin();
		$this->setHeaders();
		$this->addHelpLink( 'Extension:CampaignEvents' );
		$this->showFormAndEvents();
	}

	private function showFormAndEvents(): void {
		$request = $this->getRequest();
		$searchedVal = $request->getVal( 'wpSearch', '' );

		$pager = $this->eventsPagerFactory->newPager(
			$this->getContext(),
			$this->getLinkRenderer(),
			$searchedVal
		);

		$formDescriptor = [
			'Search' => [
				'type' => 'text',
				'label-message' => 'campaignevents-eventslist-label-search',
				'default' => $searchedVal,
			],
			'Limit' => [
				// NOTE: This has to be called 'limit' because the pager expects that name.
				'name' => 'limit',
				'type' => 'select',
				'label-message' => 'campaignevents-eventslist-label-events-per-page',
				'options' => $pager->getLimitSelectList(),
				'default' => $pager->getLimit(),
			],
		];
		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'campaignevents-eventslist-filter-legend' )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );
		$this->getOutput()->addParserOutputContent( $pager->getFullOutput() );
	}
}

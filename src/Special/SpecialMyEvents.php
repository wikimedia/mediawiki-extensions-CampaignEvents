<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPager;
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
		$this->getOutput()->addModuleStyles( [ 'ext.campaignEvents.specialmyevents.styles' ] );
		$this->getOutput()->addModules( [ 'ext.campaignEvents.specialmyevents' ] );
		$this->showFormAndEvents();
	}

	private function showFormAndEvents(): void {
		$request = $this->getRequest();
		$searchedVal = $request->getVal( 'wpSearch', '' );
		$status = $request->getVal( 'wpStatus', EventsPager::STATUS_ANY );

		$pager = $this->eventsPagerFactory->newPager(
			$this->getContext(),
			$this->getLinkRenderer(),
			$searchedVal,
			$status
		);

		$formDescriptor = [
			'Search' => [
				'type' => 'text',
				'label-message' => 'campaignevents-myevents-label-search',
				'default' => $searchedVal,
			],
			'Status' => [
				'type' => 'select',
				'label-message' => 'campaignevents-myevents-label-status',
				'options-messages' => [
					'campaignevents-myevents-field-status-any' => EventsPager::STATUS_ANY,
					'campaignevents-myevents-field-status-open' => EventsPager::STATUS_OPEN,
					'campaignevents-myevents-field-status-closed' => EventsPager::STATUS_CLOSED
				],
				'default' => $status,
				'cssclass' => 'ext-campaignevents-myevents-filter-field'
			],
			'Limit' => [
				// NOTE: This has to be called 'limit' because the pager expects that name.
				'name' => 'limit',
				'type' => 'select',
				'label-message' => 'campaignevents-myevents-label-events-per-page',
				'options' => $pager->getLimitSelectList(),
				'default' => $pager->getLimit(),
				'cssclass' => 'ext-campaignevents-myevents-filter-field'
			],
		];
		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'campaignevents-myevents-filter-legend' )
			->setMethod( 'get' )
			->setId( 'ext-campaignevents-myevents-form' )
			->prepareForm()
			->displayForm( false );
		$this->getOutput()->addParserOutputContent( $pager->getFullOutput() );
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPagerFactory;
use MediaWiki\Extension\CampaignEvents\Pager\EventsTablePager;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialMyEvents extends SpecialPage {
	public const PAGE_NAME = 'MyEvents';

	private EventsPagerFactory $eventsPagerFactory;
	private CampaignsCentralUserLookup $centralUserLookup;

	/**
	 * @param EventsPagerFactory $eventsPagerFactory
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		EventsPagerFactory $eventsPagerFactory,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventsPagerFactory = $eventsPagerFactory;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ): void {
		$this->requireNamedUser();
		$this->setHeaders();
		$this->addHelpLink( 'Extension:CampaignEvents' );
		$this->getOutput()->addModuleStyles( [ 'ext.campaignEvents.specialeventslist.styles' ] );
		$this->getOutput()->addModules( [ 'ext.campaignEvents.specialPages' ] );
		$this->showFormAndEvents();
	}

	private function showFormAndEvents(): void {
		$request = $this->getRequest();
		$searchedVal = $request->getVal( 'wpSearch', '' );
		$status = $request->getVal( 'wpStatus', EventsTablePager::STATUS_ANY );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->getAuthority() ) );
		} catch ( UserNotGlobalException $_ ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-myevents-need-central-account' )->escaped()
			) );
			return;
		}
		$pager = $this->eventsPagerFactory->newTablePager(
			$this->getContext(),
			$this->getLinkRenderer(),
			$searchedVal,
			$status,
			$centralUser
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
					'campaignevents-myevents-field-status-any' => EventsTablePager::STATUS_ANY,
					'campaignevents-myevents-field-status-open' => EventsTablePager::STATUS_OPEN,
					'campaignevents-myevents-field-status-closed' => EventsTablePager::STATUS_CLOSED
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
		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'campaignevents-myevents-filter-legend' )
			->setMethod( 'get' )
			->setId( 'ext-campaignevents-myevents-form' );
		$curSort = $this->getRequest()->getVal( 'sort' );
		if ( $curSort ) {
			$form->addHiddenField( 'sort', $curSort );
		}
		$form->prepareForm()
			->displayForm( false );
		$this->getOutput()->addParserOutputContent( $pager->getFullOutput() );
		$this->getOutput()->addModules( $pager->getModules() );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}
}

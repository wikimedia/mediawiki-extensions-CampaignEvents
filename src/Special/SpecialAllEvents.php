<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPagerFactory;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampException;

class SpecialAllEvents extends SpecialPage {
	public const PAGE_NAME = 'AllEvents';
	private EventsPagerFactory $eventsPagerFactory;

	/**
	 * @param EventsPagerFactory $eventsPagerFactory
	 */
	public function __construct(
		EventsPagerFactory $eventsPagerFactory
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventsPagerFactory = $eventsPagerFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->addHelpLink( 'Extension:CampaignEvents' );
		$this->getOutput()->addModuleStyles( [
			'ext.campaignEvents.specialPages.styles',
			'oojs-ui.styles.icons-location',
			'oojs-ui.styles.icons-interactions',
			'oojs-ui.styles.icons-user',
			'oojs-ui.styles.icons-editing-advanced'
		] );
		$this->getOutput()->addModules( [ 'ext.campaignEvents.specialPages' ] );
		$this->showFormAndEvents();
	}

	private function showFormAndEvents(): void {
		$request = $this->getRequest();
		$searchedVal = $request->getVal( 'wpSearch', '' );
		$meetingType = $request->getIntOrNull( 'wpMeetingType' );

		$rawStartTime = $request->getVal( 'wpStartDate', (string)time() );
		$startTime = $rawStartTime === '' ? '' : $this->formatDate( $rawStartTime, 'Y-m-d 00:00:00' );
		$rawEndTime = $request->getVal( 'wpEndDate', '' );
		$endTime = $rawEndTime === '' ? '' : $this->formatDate( $rawEndTime, 'Y-m-d 23:59:59' );

		$pager = $this->eventsPagerFactory->newListPager(
			$searchedVal,
			$meetingType,
			$startTime,
			$endTime
		);

		$formDescriptor = [
			'Search' => [
				'type' => 'text',
				'label-message' => 'campaignevents-allevents-label-search',
				'default' => $searchedVal,
				'cssclass' => 'ext-campaignevents-allevents-search-field'
			],
			'MeetingType' => [
				'type' => 'select',
				'label-message' => 'campaignevents-allevents-label-meeting-type',
				'options-messages' => [
					'campaignevents-eventslist-location-all-events' => null,
					'campaignevents-eventslist-location-online' => EventRegistration::MEETING_TYPE_ONLINE,
					'campaignevents-eventslist-location-in-person' => EventRegistration::MEETING_TYPE_IN_PERSON,
					'campaignevents-eventslist-location-online-and-in-person' =>
						EventRegistration::MEETING_TYPE_ONLINE_AND_IN_PERSON
				],
				'default' => $meetingType,
				'cssclass' => 'ext-campaignevents-allevents-meetingtype-field'
			],
			'StartDate' => [
				'type' => 'datetime',
				'label-message' => 'campaignevents-allevents-label-start-date',
				'icon' => 'calendar',
				'cssclass' => 'ext-campaignevents-allevents-calendar-start-field mw-htmlform-autoinfuse-lazy',
				'default' => $this->formatDate( $startTime, 'Y-m-d\TH:i:s.000\Z' ),
			],
			'EndDate' => [
				'type' => 'datetime',
				'label-message' => 'campaignevents-allevents-label-end-date',
				'icon' => 'calendar',
				'cssclass' => 'ext-campaignevents-allevents-calendar-end-field mw-htmlform-autoinfuse-lazy',
				'default' => '',
			],
			'Limit' => [
				// NOTE: This has to be called 'limit' because the pager expects that name.
				'name' => 'limit',
				'type' => 'select',
				'label-message' => 'campaignevents-allevents-label-events-per-page',
				'default' => $pager->getLimit(),
				'options' => $pager->getLimitSelectList(),
				'cssclass' => 'ext-campaignevents-allevents-filter-field'
			],
		];
		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'campaignevents-allevents-filter-legend' )
			->setSubmitTextMsg( 'campaignevents-allevents-label-submit' )
			->setMethod( 'get' )
			->setId( 'ext-campaignevents-allevents-form' )
			->setSubmitCallback( fn () => true )
			->showAlways();
		$navigation = $pager->getNavigationBar();
		$this->getOutput()->addHTML( $navigation . $pager->getBody() . $navigation );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}

	private function formatDate( string $date, string $format = 'Y-m-d' ): string {
		try {
			$date = ( new ConvertibleTimestamp( $date ) )->format( $format );
		} catch ( TimestampException $exception ) {
			return '';
		}
		return $date;
	}
}

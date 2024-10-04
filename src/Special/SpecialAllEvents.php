<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPagerFactory;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampException;

class SpecialAllEvents extends SpecialPage {
	public const PAGE_NAME = 'AllEvents';
	private EventsPagerFactory $eventsPagerFactory;

	private CampaignEventsHookRunner $hookRunner;

	/**
	 * @param EventsPagerFactory $eventsPagerFactory
	 * @param CampaignEventsHookRunner $hookRunner
	 */
	public function __construct(
		EventsPagerFactory $eventsPagerFactory, CampaignEventsHookRunner $hookRunner
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventsPagerFactory = $eventsPagerFactory;
		$this->hookRunner = $hookRunner;
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
		$eventsContent = $this->getFormAndEvents();
		$this->hookRunner->onCampaignEventsGetAllEventsContent(
			$this->getOutput(),
			$eventsContent
		);
		$this->getOutput()->addHTML( $eventsContent );
	}

	public function getFormAndEvents(): string {
		$request = $this->getRequest();
		$searchedVal = $request->getVal( 'wpSearch', '' );
		$meetingType = $request->getIntOrNull( 'wpMeetingType' );

		$rawStartTime = $request->getVal( 'wpStartDate', (string)time() );
		$startTime = $rawStartTime === '' ? '' : $this->formatDate( $rawStartTime, 'Y-m-d 00:00:00' );
		$rawEndTime = $request->getVal( 'wpEndDate', '' );
		$endTime = $rawEndTime === '' ? '' : $this->formatDate( $rawEndTime, 'Y-m-d 23:59:59' );

		$showOngoing = true;
		// Use a form identifier to tell whether the form has already been submitted or not, otherwise we can't
		// distinguish between form not submitted and form submitted but checkbox unchecked. This is important because
		// the checkbox is checked by default.
		// Note that we can't do all this in a submit callback because the pager needs to be instantiated before the
		// HTMLForm, due to the "limit" field.
		$formIdentifier = 'campaignevents-allevents';
		if ( $request->getVal( 'wpFormIdentifier' ) === $formIdentifier ) {
			$showOngoing = $request->getCheck( 'wpShowOngoing' );
		}

		$pager = $this->eventsPagerFactory->newListPager(
			$searchedVal,
			$meetingType,
			$startTime,
			$endTime,
			$showOngoing
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
			'ShowOngoing' => [
				'type' => 'toggle',
				'label-message' => 'campaignevents-allevents-label-show-ongoing',
				'cssclass' => 'ext-campaignevents-allevents-show-ongoing-field',
				'default' => true,
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
		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'campaignevents-allevents-filter-legend' )
			->setSubmitTextMsg( 'campaignevents-allevents-label-submit' )
			->setMethod( 'get' )
			->setId( 'ext-campaignevents-allevents-form' )
			->setSubmitCallback( fn () => true )
			->setFormIdentifier( $formIdentifier, true )
			->prepareForm();

		$navigation = $pager->getNavigationBar();

		$result = $form->tryAuthorizedSubmit();

		return $form->getHTML( $result ) . $navigation . $pager->getBody() . $navigation;
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

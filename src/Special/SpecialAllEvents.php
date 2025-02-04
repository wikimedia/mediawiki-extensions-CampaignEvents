<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Pager\EventsListPager;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPagerFactory;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Html\TemplateParser;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\Message\MessageValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampException;

class SpecialAllEvents extends SpecialPage {
	public const PAGE_NAME = 'AllEvents';

	private EventsPagerFactory $eventsPagerFactory;
	private CampaignEventsHookRunner $hookRunner;
	private WikiLookup $wikiLookup;
	private ITopicRegistry $topicRegistry;
	private TemplateParser $templateParser;

	public function __construct(
		EventsPagerFactory $eventsPagerFactory,
		CampaignEventsHookRunner $hookRunner,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventsPagerFactory = $eventsPagerFactory;
		$this->hookRunner = $hookRunner;
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
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
			'oojs-ui.styles.icons-wikimedia',
			'oojs-ui.styles.icons-user',
			'oojs-ui.styles.icons-editing-advanced',
			'oojs-ui.styles.icons-content',
			'codex-styles',
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
		$filterWiki = $request->getArray( 'wpFilterWikis', [] );
		$filterTopics = $request->getArray( 'wpFilterTopics', [] );
		$meetingType = $request->getIntOrNull( 'wpMeetingType' );
		$rawStartTime = $request->getVal( 'wpStartDate', (string)time() );
		$startTime = $rawStartTime === '' ? null : $this->formatDate( $rawStartTime, 'Y-m-d 00:00:00' );
		$rawEndTime = $request->getVal( 'wpEndDate', '' );
		$endTime = $rawEndTime === '' ? null : $this->formatDate( $rawEndTime, 'Y-m-d 23:59:59' );

		$separateOngoingEvents = $this->getConfig()->get( 'CampaignEventsSeparateOngoingEvents' );
		if ( $separateOngoingEvents ) {
			$showOngoing = false;
		} else {
			$showOngoing = true;
			// Use a form identifier to tell whether the form has already been submitted or not, otherwise we can't
			// distinguish between form not submitted and form submitted but checkbox unchecked. This is important
			// because the checkbox is checked by default.
			$formIdentifier = 'campaignevents-allevents';
			if ( $request->getVal( 'wpFormIdentifier' ) === $formIdentifier ) {
				$showOngoing = $request->getCheck( 'wpShowOngoing' );
			}
		}

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
				'default' => $startTime !== null ? $this->formatDate( $startTime, 'Y-m-d\TH:i:s.000\Z' ) : '',
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
			'FilterWikis' => [
				'type' => 'multiselect',
				'dropdown' => true,
				'label-message' => 'campaignevents-allevents-label-filter-wikis',
				'options' => $this->wikiLookup->getListForSelect(),
				'placeholder-message' => 'campaignevents-allevents-placeholder-add-wikis',
				'max' => 10,
				'cssclass' => 'ext-campaignevents-allevents-wikis-field',
			],
		];
		if ( $separateOngoingEvents ) {
			unset( $formDescriptor['ShowOngoing'] );
		}
		$availableTopics = $this->topicRegistry->getTopicsForSelect();
		if ( $availableTopics ) {
			$formDescriptor['FilterTopics'] = [
				'type' => 'multiselect',
				'dropdown' => true,
				'label-message' => 'campaignevents-allevents-label-filter-topics',
				'options-messages' => $availableTopics,
				'placeholder-message' => 'campaignevents-allevents-placeholder-topics',
				'max' => 20,
			];
		}
		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'campaignevents-allevents-filter-legend' )
			->setSubmitTextMsg( 'campaignevents-allevents-label-submit' )
			->setMethod( 'get' )
			->setId( 'ext-campaignevents-allevents-form' )
			->setSubmitCallback( static fn () => true );
		if ( !$separateOngoingEvents && isset( $formIdentifier ) ) {
			$form->setFormIdentifier( $formIdentifier, true );
		}
		$result = $form->prepareForm()->tryAuthorizedSubmit();

		$upcomingPager = $this->eventsPagerFactory->newListPager(
			$searchedVal,
			$meetingType,
			$startTime,
			$endTime,
			$showOngoing,
			$filterWiki,
			$filterTopics
		);
		$upcomingEventsNavigation = $upcomingPager->getNavigationBar();

		if ( $separateOngoingEvents && $startTime !== null ) {
			$ongoingPager = $this->eventsPagerFactory->newOngoingListPager(
				$searchedVal,
				$meetingType,
				$startTime,
				$filterWiki,
				$filterTopics
			);
			// TODO: Remove this awful hack when we find a way to have separate paging (T386019).
			$ongoingPager->mLimit = 5000;
			$content = $this->getAccordionTemplate(
				$ongoingPager,
				new MessageValue( 'campaignevents-allevents-label-ongoing-events-title' ),
				new MessageValue( 'campaignevents-allevents-label-ongoing-events-description' ),
				'ext-campaignevents-allevents-ongoing-events',
				false
			);
			$content .= $this->getAccordionTemplate(
				$upcomingPager,
				new MessageValue( 'campaignevents-allevents-label-upcoming-events-title' ),
				new MessageValue( 'campaignevents-allevents-label-upcoming-events-description' ),
				'ext-campaignevents-allevents-upcoming-events',
				true
			);

			return $form->getHTML( $result ) . $content;
		}
		return $form->getHTML( $result )
			. $upcomingEventsNavigation
			. $upcomingPager->getBody()
			. $upcomingEventsNavigation;
	}

	public function getAccordionTemplate(
		EventsListPager $pager,
		MessageSpecifier $title,
		MessageSpecifier $description,
		string $cssClass,
		bool $isOpen
	): string {
		$navigation = $pager->getNavigationBar();
		$data = [
			'title' => $this->msg( $title )->text(),
			'description' => $this->msg( $description )->text(),
			'content' => $pager->getBody() . $navigation,
			'isopen' => $isOpen,
			'cssclass' => $cssClass,
		];

		return $this->templateParser->processTemplate( 'Accordion', $data );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}

	private function formatDate( string $date, string $format ): ?string {
		try {
			return ( new ConvertibleTimestamp( $date ) )->format( $format );
		} catch ( TimestampException $exception ) {
			return null;
		}
	}
}

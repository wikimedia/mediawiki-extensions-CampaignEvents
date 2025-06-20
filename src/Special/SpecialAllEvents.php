<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Pager\EventsListPager;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPagerFactory;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Html\TemplateParser;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\IncludableSpecialPage;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\Message\MessageValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampException;

class SpecialAllEvents extends IncludableSpecialPage {
	public const PAGE_NAME = 'AllEvents';

	private const ONGOING_SECTION = 'ongoing';
	private const UPCOMING_SECTION = 'upcoming';
	private const TAB_ID_PREFIX = 'ext-campaignevents-allevents-tab-';

	private EventsPagerFactory $eventsPagerFactory;
	private CampaignEventsHookRunner $hookRunner;
	private WikiLookup $wikiLookup;
	private ITopicRegistry $topicRegistry;
	private TemplateParser $templateParser;
	private EventTypesRegistry $eventTypesRegistry;

	public function __construct(
		EventsPagerFactory $eventsPagerFactory,
		CampaignEventsHookRunner $hookRunner,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		EventTypesRegistry $eventTypesRegistry
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventsPagerFactory = $eventsPagerFactory;
		$this->hookRunner = $hookRunner;
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
		$this->eventTypesRegistry = $eventTypesRegistry;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->addHelpLink( 'Extension:CampaignEvents' );
		$this->getOutput()->addModuleStyles( [
			'ext.campaignEvents.specialPages.styles',
			'codex-styles',
		] );

		if ( !$this->including() ) {
			// Not needed when transcluding, so skip it for performance. See also T392856.
			$this->getOutput()->addModules( [ 'ext.campaignEvents.specialPages' ] );
		}

		$pageTabs = [
			'events' => [
				'content' => $this->getFormAndEvents(),
				'label' => $this->getOutput()->msg(
					'campaignevents-allevents-tab-events-heading'
				)->text()
			],
		];
		$submittedTab = $this->getOutput()->getRequest()->getRawVal( 'tab' );
		$activeTab = $submittedTab ? str_replace( self::TAB_ID_PREFIX, '', $submittedTab ) : 'events';
		$this->hookRunner->onCampaignEventsGetAllEventsTabs(
			$this,
			$pageTabs,
			$activeTab
		);

		if ( count( $pageTabs ) > 1 ) {
			$pageContent = $this->getLayout( $pageTabs, $activeTab );
		} else {
			$pageContent = $pageTabs['events']['content'];
		}
		if ( $this->including() ) {
			$pageContent = $pageTabs[$activeTab]['content'] ?? $pageTabs['events']['content'];
			$pageContent .= $this->getLinkRenderer()->makeKnownLink(
				$this->getPageTitle(),
				$this->msg( 'campaignevents-allevents-transclusion-more-link' ),
				[],
				$this->getQueryParametersForTransclusionLink()
			);
		}
		$this->getOutput()->addHTML( $pageContent );
	}

	/** @return array<string,mixed> */
	private function getQueryParametersForTransclusionLink(): array {
		$values = $this->getRequest()->getQueryValues();
		// Convert multivalued parameters back to arrays.
		if ( ( $values['wpFilterWikis'] ?? '' ) !== '' ) {
			$values['wpFilterWikis'] = array_map( 'trim', explode( ',', $values['wpFilterWikis'] ) );
		}
		if ( ( $values['wpFilterTopics'] ?? '' ) !== '' ) {
			$values['wpFilterTopics'] = array_map( 'trim', explode( ',', $values['wpFilterTopics'] ) );
		}
		if ( ( $values['wpFilterEventTypes'] ?? '' ) !== '' ) {
			$values['wpFilterEventTypes'] = array_map( 'trim', explode( ',', $values['wpFilterEventTypes'] ) );
		}
		return $values;
	}

	public function getFormAndEvents(): string {
		$request = $this->getRequest();
		$showForm = !$this->including();
		$searchedVal = $request->getVal( 'wpSearch', '' );
		if ( $this->including() ) {
			// Uses a comma-separated list to support multivalued parameters (T388385#10773882)
			$rawFilterWiki = $request->getRawVal( 'wpFilterWikis' ) ?? '';
			$filterWiki = $this->normalizeFilterValues( $rawFilterWiki );
			$rawFilterTopic = $request->getRawVal( 'wpFilterTopics' ) ?? '';
			$filterTopics = $this->normalizeFilterValues( $rawFilterTopic );
			$rawFilterEventTypes = $request->getRawVal( 'wpFilterEventTypes' ) ?? '';
			$filterEventTypes = $this->normalizeFilterValues( $rawFilterEventTypes );
		} else {
			$filterWiki = $request->getArray( 'wpFilterWikis' ) ?? [];
			$filterTopics = $request->getArray( 'wpFilterTopics' ) ?? [];
			$filterEventTypes = $request->getArray( 'wpFilterEventTypes' ) ?? [];
		}
		$participationOptions = $request->getIntOrNull( 'wpParticipationOptions' );
		$rawStartTime = $request->getRawVal( 'wpStartDate' ) ?? (string)time();
		if ( $this->including() && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $rawStartTime ) ) {
			// Special case: allow specifying just the date when transcluding. Ideally we'd also use just the date
			// in the page itself, but for the time being we need a datetime field to manipulate min/max. T392850
			$rawStartTime .= 'T00:00:00Z';
		}
		$startTime = $rawStartTime === '' ? null : $this->formatDate( $rawStartTime, 'Y-m-d 00:00:00' );
		$rawEndTime = $request->getRawVal( 'wpEndDate' ) ?? '';
		if ( $this->including() && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $rawEndTime ) ) {
			// As above.
			$rawEndTime .= 'T23:59:59Z';
		}
		$endTime = $rawEndTime === '' ? null : $this->formatDate( $rawEndTime, 'Y-m-d 23:59:59' );
		$openSectionsStr = $request->getVal( 'wpOpenSections', self::UPCOMING_SECTION );
		// Use a form identifier to tell whether the form has already been submitted or not, otherwise we can't
		// distinguish between form not submitted and form submitted but checkbox unchecked. This is important
		// because the checkbox is checked by default.
		$formIdentifier = 'campaignevents-allevents';
		if ( $this->including() ) {
			$includeAllWikis = $request->getBool( 'wpIncludeAllWikis', true );
		} elseif ( $request->getVal( 'wpFormIdentifier' ) === $formIdentifier ) {
			$includeAllWikis = $request->getCheck( 'wpIncludeAllWikis' );
		} else {
			// Form wasn't submitted, use default value of true.
			$includeAllWikis = true;
		}

		$content = '';
		if ( $showForm ) {
			$form = $this->getHTMLForm(
				$searchedVal,
				$participationOptions,
				$startTime,
				$openSectionsStr,
				$formIdentifier
			);
			$result = $form->prepareForm()->tryAuthorizedSubmit();
			$content = $form->getHTML( $result );
		}
		$upcomingPager = $this->eventsPagerFactory->newListPager(
			$this->getContext(),
			$searchedVal,
			$participationOptions,
			$startTime,
			$endTime,
			$filterWiki,
			$includeAllWikis,
			$filterTopics,
			$filterEventTypes
		);
		if ( $startTime !== null ) {
			$openSections = explode( ',', $openSectionsStr );
			$ongoingPager = $this->eventsPagerFactory->newOngoingListPager(
				$this->getContext(),
				$searchedVal,
				$participationOptions,
				$startTime,
				$filterWiki,
				$includeAllWikis,
				$filterTopics,
				$filterEventTypes
			);
			// TODO: Remove this awful hack when we find a way to have separate paging (T386019).
			$ongoingPager->mLimit = 5000;
			$content .= $this->getAccordionTemplate(
				$ongoingPager,
				new MessageValue( 'campaignevents-allevents-label-ongoing-events-title' ),
				new MessageValue( 'campaignevents-allevents-label-ongoing-events-description' ),
				'ext-campaignevents-allevents-ongoing-events',
				in_array( self::ONGOING_SECTION, $openSections, true )
			);
			$content .= $this->getAccordionTemplate(
				$upcomingPager,
				new MessageValue( 'campaignevents-allevents-label-upcoming-events-title' ),
				new MessageValue( 'campaignevents-allevents-label-upcoming-events-description' ),
				'ext-campaignevents-allevents-upcoming-events',
				in_array( self::UPCOMING_SECTION, $openSections, true )
			);
			return $content;
		}
		$upcomingEventsNavigation = $this->including() ? '' : $upcomingPager->getNavigationBar();
		return $content
			. $upcomingEventsNavigation
			. $upcomingPager->getBody()
			. $upcomingEventsNavigation;
	}

	public function getHTMLForm(
		?string $searchedVal,
		?int $participationOptions,
		?string $startTime,
		string $openSectionsStr,
		string $formIdentifier
	): HTMLForm {
		$formDescriptor = [
			'Search' => [
				'type' => 'text',
				'label-message' => 'campaignevents-allevents-label-search',
				'default' => $searchedVal,
				'cssclass' => 'ext-campaignevents-allevents-search-field',
			],
			'FilterEventTypes' => [
				'type' => 'multiselect',
				'dropdown' => true,
				'label-message' => 'campaignevents-allevents-label-filter-event-types',
				'options-messages' => $this->eventTypesRegistry->getAllOptionMessages(),
				'placeholder-message' => 'campaignevents-allevents-placeholder-add-event-types',
				'max' => 20,
			],
			'ParticipationOptions' => [
				'type' => 'select',
				'label-message' => 'campaignevents-allevents-label-participation-options',
				'options-messages' => [
					'campaignevents-eventslist-participation-options-all-events' => null,
					'campaignevents-eventslist-participation-options-online' =>
						EventRegistration::PARTICIPATION_OPTION_ONLINE,
					'campaignevents-eventslist-participation-options-in-person' =>
						EventRegistration::PARTICIPATION_OPTION_IN_PERSON,
					'campaignevents-eventslist-participation-options-online-and-in-person' =>
						EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON
				],
				'default' => $participationOptions,
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
		// TODO: Use array unpacking when we drop support for PHP 7.4
		$formDescriptor = array_merge( $formDescriptor, [
			'IncludeAllWikis' => [
				'type' => 'toggle',
				'label-message' => 'campaignevents-allevents-label-include-all-wikis',
				'default' => true,
			],
			'OpenSections' => [
				'type' => 'hidden',
				'default' => $openSectionsStr,
			],
		] );

		return HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'campaignevents-allevents-filter-legend' )
			->setSubmitTextMsg( 'campaignevents-allevents-label-submit' )
			->setMethod( 'get' )
			->setId( 'ext-campaignevents-allevents-form' )
			->setFormIdentifier( $formIdentifier, true )
			->setSubmitCallback( static fn (): bool => true );
	}

	public function getAccordionTemplate(
		EventsListPager $pager,
		MessageSpecifier $title,
		MessageSpecifier $description,
		string $cssClass,
		bool $isOpen
	): string {
		$navigation = $this->including() ? '' : $pager->getNavigationBar();
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

	/** @param array<string,array<string,mixed>> $tabs */
	private function getLayout( array $tabs, string $activeTab ): string {
		$data = [
			'url' => $this->getPageTitle()->getLocalURL(),
		];
		foreach ( $tabs as $tabName => $tab ) {
			$active = $activeTab === $tabName;
			$data['tabs'][] =
				[
					'id' => self::TAB_ID_PREFIX . $tabName,
					'content' => $tab['content'],
					'label' => $tab['label'],
					'active' => wfBoolToStr( $active ),
					'hidden' => wfBoolToStr( !$active ),
				];

		}
		return $this->templateParser->processTemplate( 'TabLayout', $data );
	}

	/**
	 * Normalize a comma-separated string of values into an array of trimmed, lowercase strings.
	 * This allows editors using transclusion to use case-insensitive filters
	 * @param string $value
	 * @return list<string>
	 */
	private function normalizeFilterValues( string $value ): array {
		if ( $value === '' ) {
			return [];
		}
		return array_map( static function ( string $item ): string {
			return strtolower( trim( $item ) );
		}, explode( ',', $value ) );
	}
}

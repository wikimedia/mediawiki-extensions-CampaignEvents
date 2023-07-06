<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Html;
use Language;
use Linker;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\User\UserIdentity;
use OOUI\ButtonWidget;
use OOUI\HtmlSnippet;
use OOUI\IconWidget;
use OOUI\MessageWidget;
use OOUI\PanelLayout;
use OOUI\Tag;
use OutputPage;
use SpecialPage;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class EventDetailsModule {
	private const ORGANIZERS_LIMIT = 10;

	public const MODULE_STYLES = [
		'oojs-ui.styles.icons-location',
		'oojs-ui.styles.icons-interactions',
		'oojs-ui.styles.icons-editing-core',
		'oojs-ui.styles.icons-alerts',
		'oojs-ui.styles.icons-user',
		'oojs-ui.styles.icons-media',
	];

	/** @var OrganizersStore */
	private OrganizersStore $organizersStore;
	/** @var PageURLResolver */
	private PageURLResolver $pageURLResolver;
	/** @var UserLinker */
	private UserLinker $userLinker;
	/** @var EventTimeFormatter */
	private EventTimeFormatter $eventTimeFormatter;
	/** @var TrackingToolRegistry */
	private TrackingToolRegistry $trackingToolRegistry;

	/** @var ExistingEventRegistration */
	private ExistingEventRegistration $registration;
	/** @var Language */
	private Language $language;
	/** @var ITextFormatter */
	private ITextFormatter $msgFormatter;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param OrganizersStore $organizersStore
	 * @param PageURLResolver $pageURLResolver
	 * @param UserLinker $userLinker
	 * @param EventTimeFormatter $eventTimeFormatter
	 * @param TrackingToolRegistry $trackingToolRegistry
	 * @param ExistingEventRegistration $registration
	 * @param Language $language
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		OrganizersStore $organizersStore,
		PageURLResolver $pageURLResolver,
		UserLinker $userLinker,
		EventTimeFormatter $eventTimeFormatter,
		TrackingToolRegistry $trackingToolRegistry,
		ExistingEventRegistration $registration,
		Language $language
	) {
		$this->organizersStore = $organizersStore;
		$this->pageURLResolver = $pageURLResolver;
		$this->userLinker = $userLinker;
		$this->eventTimeFormatter = $eventTimeFormatter;
		$this->trackingToolRegistry = $trackingToolRegistry;

		$this->registration = $registration;
		$this->language = $language;
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
	}

	/**
	 * @param UserIdentity $viewingUser
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param OutputPage $out
	 * @return PanelLayout
	 *
	 * @note Ideally, this wouldn't use MW-specific classes for l10n, but it's hard-ish to avoid and
	 * probably not worth doing.
	 */
	public function createContent(
		UserIdentity $viewingUser,
		bool $isOrganizer,
		bool $isParticipant,
		OutputPage $out
	): PanelLayout {
		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $this->registration->getID() );

		$header = $this->getHeader( $isOrganizer );

		$contentWrapper = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventdetails-content-wrapper' ] );

		$contentWrapper->appendContent(
			$this->getInfoColumn(
				$viewingUser,
				$out,
				$isOrganizer,
				$isParticipant,
				$organizersCount
			)
		);
		$contentWrapper->appendContent(
			$this->getOrganizersColumn(
				$out,
				$organizersCount
			)
		);

		$footer = $this->getFooter();

		return new PanelLayout( [
			'content' => [ $header, $contentWrapper, $footer ],
			'padded' => true,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-eventdetails-panel' ],
		] );
	}

	/**
	 * @param bool $isOrganizer
	 * @return Tag
	 */
	private function getHeader( bool $isOrganizer ): Tag {
		$headerItems = [];
		$headerItems[] = ( new Tag( 'h2' ) )->appendContent(
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-label' )
			)
		);

		if ( $isOrganizer ) {
			$headerItems[] = new ButtonWidget( [
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-edit-button' )
				),
				'href' => SpecialPage::getTitleFor(
					SpecialEditEventRegistration::PAGE_NAME,
					(string)$this->registration->getID()
				)->getLocalURL(),
				'icon' => 'edit'
			] );
		}

		return ( new Tag( 'div' ) )
			->appendContent( $headerItems )
			->addClasses( [ 'ext-campaignevents-event-details-info-topbar' ] );
	}

	/**
	 * @param UserIdentity $viewingUser
	 * @param OutputPage $out
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param int $organizersCount
	 * @return Tag
	 */
	private function getInfoColumn(
		UserIdentity $viewingUser,
		OutputPage $out,
		bool $isOrganizer,
		bool $isParticipant,
		int $organizersCount
	): Tag {
		$items = [];

		$formattedStart = $this->eventTimeFormatter->formatStart( $this->registration, $this->language, $viewingUser );
		$formattedEnd = $this->eventTimeFormatter->formatEnd( $this->registration, $this->language, $viewingUser );
		$datesMsg = $this->msgFormatter->format(
			MessageValue::new( 'campaignevents-event-details-dates' )->params(
				$formattedStart->getTimeAndDate(),
				$formattedStart->getDate(),
				$formattedStart->getTime(),
				$formattedEnd->getTimeAndDate(),
				$formattedEnd->getDate(),
				$formattedEnd->getTime()
			)
		);
		$formattedTimezone = $this->eventTimeFormatter->formatTimezone( $this->registration, $viewingUser );
		// XXX Can't use $this->msgFormatter due to parse()
		$timezoneMsg = $out->msg( 'campaignevents-event-details-timezone' )->params( $formattedTimezone )->parse();
		$items[] = $this->makeSection(
			'clock',
			[
				$datesMsg,
				( new Tag( 'div' ) )->appendContent( new HtmlSnippet( $timezoneMsg ) )
			],
			'campaignevents-event-details-dates-label'
		);

		$needToRegisterMsg = $this->msgFormatter->format(
			MessageValue::new( 'campaignevents-event-details-register-prompt' )
		);

		$items[] = $this->getLocationSection(
			$isOrganizer,
			$isParticipant,
			$organizersCount,
			$needToRegisterMsg
		);

		$trackingToolsSection = $this->getTrackingToolsSection();
		if ( $trackingToolsSection ) {
			$items[] = $trackingToolsSection;
		}

		$chatURL = $this->registration->getChatURL();
		if ( $chatURL ) {
			if ( $isOrganizer || $isParticipant ) {
				$chatSectionContent = new HtmlSnippet( Linker::makeExternalLink( $chatURL, $chatURL ) );
			} else {
				$chatSectionContent = $needToRegisterMsg;
			}
		} else {
			$chatSectionContent = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-chat-link-not-available' )
			);
		}

		$items[] = $this->makeSection(
			'speechBubbles',
			$chatSectionContent,
			'campaignevents-event-details-chat-link'
		);

		return ( new Tag( 'div' ) )
			->appendContent( $items );
	}

	/**
	 * @param OutputPage $out
	 * @param int $organizersCount
	 * @return Tag
	 */
	private function getOrganizersColumn( OutputPage $out, int $organizersCount ): Tag {
		$ret = [];

		$partialOrganizers = $this->organizersStore->getEventOrganizers(
			$this->registration->getID(),
			self::ORGANIZERS_LIMIT
		);
		$organizerListItems = '';
		$lastOrganizerID = null;
		foreach ( $partialOrganizers as $organizer ) {
			$organizerListItems .= Html::rawElement(
				'li',
				[],
				$this->userLinker->generateUserLinkWithFallback( $organizer->getUser(), $this->language->getCode() )
			);
			$lastOrganizerID = $organizer->getOrganizerID();
		}
		$out->addJsConfigVars( [
			'wgCampaignEventsLastOrganizerID' => $lastOrganizerID,
		] );
		$organizersList = Html::rawElement(
			'ul',
			[ 'class' => 'ext-campaignevents-event-details-organizers-list' ],
			$organizerListItems
		);
		$ret[] = new HtmlSnippet( $organizersList );

		if ( count( $partialOrganizers ) < $organizersCount ) {
			$viewMoreBtn = new ButtonWidget( [
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-organizers-view-more' )
				),
				'classes' => [ 'ext-campaignevents-event-details-load-organizers-link' ],
				'framed' => false,
				'flags' => [ 'progressive' ]
			] );
			$viewMoreNoscript = ( new Tag( 'noscript' ) )
				->appendContent(
					$this->msgFormatter->format(
						MessageValue::new( 'campaignevents-event-details-organizers-noscript' )
					)
				);
			$ret[] = ( new Tag( 'p' ) )->appendContent( $viewMoreBtn, $viewMoreNoscript );
		}

		return $this->makeSection(
			'userRights',
			$ret,
			'campaignevents-event-details-organizers-header'
		);
	}

	/**
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param int $organizersCount
	 * @param string $needToRegisterMsg
	 * @return Tag
	 */
	private function getLocationSection(
		bool $isOrganizer,
		bool $isParticipant,
		int $organizersCount,
		string $needToRegisterMsg
	): Tag {
		$meetingType = $this->registration->getMeetingType();
		$items = [];
		if ( $meetingType & ExistingEventRegistration::MEETING_TYPE_IN_PERSON ) {
			$items[] = ( new Tag( 'h4' ) )
				->appendContent( $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-in-person-event-label' )
				) );

			$rawAddress = $this->registration->getMeetingAddress();
			$rawCountry = $this->registration->getMeetingCountry();
			if ( $rawAddress || $rawCountry ) {
				// NOTE: This is not pretty if exactly one of address and country is specified, but
				// that's going to be fixed when we switch to using an actual geocoding service (T309325)
				$address = $rawAddress . "\n" . $rawCountry;
				$items[] = ( new Tag( 'div' ) )
					->appendContent( $address )
					->setAttributes( [ 'dir' => Utils::guessStringDirection( $address ) ] );
			} else {
				$items[] = ( new Tag( 'div' ) )
					->appendContent(
						$this->msgFormatter->format(
							MessageValue::new( 'campaignevents-event-details-venue-not-available' )
								->numParams( $organizersCount )
						)
					);
			}
		}

		if ( $meetingType & ExistingEventRegistration::MEETING_TYPE_ONLINE ) {
			$items[] = ( new Tag( 'h4' ) )
				->appendContent( $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-online-label' )
				) );

			$meetingURL = $this->registration->getMeetingURL();
			if ( $meetingURL ) {
				if ( $isOrganizer || $isParticipant ) {
					$items[] = new HtmlSnippet( Linker::makeExternalLink( $meetingURL, $meetingURL ) );
				} else {
					$items[] = $needToRegisterMsg;
				}
			} else {
				$items[] = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-online-link-not-available' )
						->numParams( $organizersCount )
				);
			}
		}

		return $this->makeSection(
			'mapPin',
			$items,
			'campaignevents-event-details-location-header'
		);
	}

	/**
	 * @return Tag|null
	 */
	private function getTrackingToolsSection(): ?Tag {
		$trackingTools = $this->registration->getTrackingTools();

		if ( !$trackingTools ) {
			return null;
		}

		if ( count( $trackingTools ) > 1 ) {
			throw new LogicException( "Not expecting more than one tool" );
		}
		$toolAssoc = $trackingTools[0];
		$toolUserInfo = $this->trackingToolRegistry->getUserInfo(
			$toolAssoc->getToolID(),
			$toolAssoc->getToolEventID()
		);
		if ( $toolUserInfo['user-id'] !== 'wikimedia-pe-dashboard' ) {
			throw new LogicException( "Only the P&E Dashboard should be available as a tool for now" );
		}

		$syncStatus = $toolAssoc->getSyncStatus();
		$lastSyncTS = $toolAssoc->getLastSyncTimestamp();
		if ( $syncStatus === TrackingToolAssociation::SYNC_STATUS_UNKNOWN || $lastSyncTS === null ) {
			// Maybe the tool is being added right now. But this shouldn't even happen, as
			// UNKNOWN should currently only be used as a temporary placeholder within a single
			// request. At any rate, skip.
			return null;
		}

		$sectionItems = [];

		$courseURL = $toolUserInfo['tool-event-url'];
		$sectionItems[] = new HtmlSnippet( Linker::makeExternalLink( $courseURL, $courseURL ) );

		if ( $syncStatus === TrackingToolAssociation::SYNC_STATUS_SYNCED ) {
			$msgType = 'success';
			$msgStatus = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-tracking-tool-p&e-dashboard-synced' )
			);
			$msgLastSync = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-tracking-tool-last-update' )
					->dateTimeParams( $lastSyncTS )
					->dateParams( $lastSyncTS )
					->timeParams( $lastSyncTS )
			);

		} else {
			$msgType = 'error';
			$msgStatus = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-tracking-tool-p&e-dashboard-desynced' )
			);
			$msgLastSync = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-tracking-tool-last-successful-update' )
					->dateTimeParams( $lastSyncTS )
					->dateParams( $lastSyncTS )
					->timeParams( $lastSyncTS )
			);
		}
		$syncDetailsRawParagraph = ( new Tag( 'p' ) )
			->addClasses( [ 'ext-campaignevents-event-details-tracking-tool-sync-details' ] )
			->appendContent( htmlspecialchars( $msgLastSync ) );
		$sectionItems[] = new MessageWidget( [
			'type' => $msgType,
			'label' => new HtmlSnippet( htmlspecialchars( $msgStatus ) . $syncDetailsRawParagraph ),
			'classes' => [ 'ext-campaignevents-event-details-tracking-tool-sync' ],
			'inline' => true,
		] );

		return $this->makeSection(
			'chart',
			$sectionItems,
			$toolUserInfo['display-name-msg']
		);
	}

	private function getFooter(): Tag {
		return new ButtonWidget( [
			'flags' => [ 'progressive' ],
			'label' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-view-event-page' )
			),
			'classes' => [ 'ext-campaignevents-event-details-view-event-page-button' ],
			'href' => $this->pageURLResolver->getUrl( $this->registration->getPage() )
		] );
	}

	/**
	 * @param string $icon
	 * @param string|Tag|array $content
	 * @param string $labelMsg
	 * @return Tag
	 */
	private function makeSection( string $icon, $content, string $labelMsg ): Tag {
		$iconWidget = new IconWidget( [
			'icon' => $icon,
			'classes' => [ 'ext-campaignevents-event-details-icon' ]
		] );
		$header = ( new Tag( 'h3' ) )
			->appendContent( $iconWidget, $this->msgFormatter->format( MessageValue::new( $labelMsg ) ) )
			->addClasses( [ 'ext-campaignevents-event-details-section-header' ] );

		$contentTag = ( new Tag( 'div' ) )
			->appendContent( $content )
			->addClasses( [ 'ext-campaignevents-event-details-section-content' ] );

		return ( new Tag( 'div' ) )
			->appendContent( $header, $contentTag );
	}
}

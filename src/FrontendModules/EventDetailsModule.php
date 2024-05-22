<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use LogicException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use OOUI\ButtonWidget;
use OOUI\HtmlSnippet;
use OOUI\IconWidget;
use OOUI\MessageWidget;
use OOUI\PanelLayout;
use OOUI\Tag;
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

	private OrganizersStore $organizersStore;
	private PageURLResolver $pageURLResolver;
	private UserLinker $userLinker;
	private EventTimeFormatter $eventTimeFormatter;
	private TrackingToolRegistry $trackingToolRegistry;

	private ExistingEventRegistration $registration;
	private Language $language;
	private ITextFormatter $msgFormatter;
	private CampaignEventsHookRunner $hookRunner;
	private PermissionChecker $permissionChecker;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param OrganizersStore $organizersStore
	 * @param PageURLResolver $pageURLResolver
	 * @param UserLinker $userLinker
	 * @param EventTimeFormatter $eventTimeFormatter
	 * @param TrackingToolRegistry $trackingToolRegistry
	 * @param CampaignEventsHookRunner $hookRunner
	 * @param PermissionChecker $permissionChecker
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
		CampaignEventsHookRunner $hookRunner,
		PermissionChecker $permissionChecker,
		ExistingEventRegistration $registration,
		Language $language
	) {
		$this->organizersStore = $organizersStore;
		$this->pageURLResolver = $pageURLResolver;
		$this->userLinker = $userLinker;
		$this->eventTimeFormatter = $eventTimeFormatter;
		$this->trackingToolRegistry = $trackingToolRegistry;

		$this->hookRunner = $hookRunner;
		$this->permissionChecker = $permissionChecker;
		$this->registration = $registration;
		$this->language = $language;
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
	}

	/**
	 * @param UserIdentity $viewingUser
	 * @param ICampaignsAuthority $authority
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param string|false $wikiID
	 * @param OutputPage $out
	 * @return PanelLayout
	 *
	 * @note Ideally, this wouldn't use MW-specific classes for l10n, but it's hard-ish to avoid and
	 * probably not worth doing.
	 */
	public function createContent(
		UserIdentity $viewingUser,
		ICampaignsAuthority $authority,
		bool $isOrganizer,
		bool $isParticipant,
		$wikiID,
		OutputPage $out
	): PanelLayout {
		$eventID = $this->registration->getID();
		$isLocalWiki = $wikiID === WikiAwareEntity::LOCAL;
		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $eventID );

		$header = $this->getHeader( $isOrganizer, $isLocalWiki );

		$contentWrapper = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventdetails-content-wrapper' ] );

		$infoColumn = $this->getInfoColumn(
			$viewingUser,
			$authority,
			$out,
			$isOrganizer,
			$isParticipant,
			$isLocalWiki,
			$wikiID,
			$organizersCount
		);
		$organizersColumn = $this->getOrganizersColumn( $out, $organizersCount );

		$this->hookRunner->onCampaignEventsGetEventDetails(
			$infoColumn,
			$organizersColumn,
			$eventID,
			$isOrganizer,
			$out,
			$isLocalWiki
		);

		$contentWrapper->appendContent( $infoColumn, $organizersColumn );

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
	 * @param bool $isLocalWiki
	 * @return Tag
	 */
	private function getHeader( bool $isOrganizer, bool $isLocalWiki ): Tag {
		$headerItems = [];
		$headerItems[] = ( new Tag( 'h2' ) )->appendContent(
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-label' )
			)
		);

		if ( $isOrganizer && $isLocalWiki ) {
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
	 * @param ICampaignsAuthority $authority
	 * @param OutputPage $out
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param bool $isLocalWiki
	 * @param string|false $wikiID
	 * @param int $organizersCount
	 * @return Tag
	 */
	private function getInfoColumn(
		UserIdentity $viewingUser,
		ICampaignsAuthority $authority,
		OutputPage $out,
		bool $isOrganizer,
		bool $isParticipant,
		bool $isLocalWiki,
		$wikiID,
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
		$items[] = self::makeSection(
			'clock',
			[
				$datesMsg,
				( new Tag( 'div' ) )->appendContent( new HtmlSnippet( $timezoneMsg ) )
			],
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-dates-label' ) )
		);

		$needToRegisterMsg = $this->msgFormatter->format(
			MessageValue::new( 'campaignevents-event-details-register-prompt' )
		);

		$wikiName = WikiMap::getWikiName( Utils::getWikiIDString( $wikiID ) );
		$foreignDetailsURL = WikiMap::getForeignURL(
			$wikiID, 'Special:' . SpecialEventDetails::PAGE_NAME . "/{$this->registration->getID()}"
		);
		$needToBeOnLocalWikiMessage = new HtmlSnippet(
			$out->msg( 'campaignevents-event-details-not-local-wiki-prompt' )
				->params( [
					$foreignDetailsURL, $wikiName
				] )->parse()
		);

		$userCanViewSensitiveEventData = $this->permissionChecker->userCanViewSensitiveEventData( $authority );
		$items[] = $this->getLocationSection(
			$isOrganizer,
			$isParticipant,
			$isLocalWiki,
			$organizersCount,
			$needToRegisterMsg,
			$needToBeOnLocalWikiMessage,
			$userCanViewSensitiveEventData
		);

		$trackingToolsSection = $this->getTrackingToolsSection();
		if ( $trackingToolsSection ) {
			$items[] = $trackingToolsSection;
		}

		$chatURL = $this->registration->getChatURL();
		if ( $userCanViewSensitiveEventData && $chatURL ) {
			if ( ( $isOrganizer || $isParticipant ) && !$isLocalWiki ) {
				$chatSectionContent = $needToBeOnLocalWikiMessage;
			} elseif ( $isOrganizer || $isParticipant ) {
				$chatSectionContent = new HtmlSnippet( Linker::makeExternalLink( $chatURL, $chatURL ) );
			} else {
				$chatSectionContent = $needToRegisterMsg;
			}
		} else {
			$chatSectionContent = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-chat-link-not-available' )
			);
		}

		$items[] = self::makeSection(
			'speechBubbles',
			$chatSectionContent,
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-chat-link' ) )
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

		$organizersSection = self::makeSection(
			'userRights',
			$ret,
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-organizers-header' ) )
		);

		return ( new Tag( 'div' ) )->appendContent( $organizersSection );
	}

	/**
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param bool $isLocalWiki
	 * @param int $organizersCount
	 * @param string $needToRegisterMsg
	 * @param HtmlSnippet $needToBeOnLocalWikiMessage
	 * @param bool $userCanViewSensitiveEventData
	 * @return Tag
	 */
	private function getLocationSection(
		bool $isOrganizer,
		bool $isParticipant,
		bool $isLocalWiki,
		int $organizersCount,
		string $needToRegisterMsg,
		HtmlSnippet $needToBeOnLocalWikiMessage,
		bool $userCanViewSensitiveEventData
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
			if ( $userCanViewSensitiveEventData && $meetingURL ) {
				if ( ( $isOrganizer || $isParticipant ) && !$isLocalWiki ) {
					$items[] = $needToBeOnLocalWikiMessage;
				} elseif ( $isOrganizer || $isParticipant ) {
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

		return self::makeSection(
			'mapPin',
			$items,
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-location-header' ) )
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

		return self::makeSection(
			'chart',
			$sectionItems,
			$this->msgFormatter->format( MessageValue::new( $toolUserInfo['display-name-msg'] ) )
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
	 * Builds a section for the info panel. This method might be called in handlers of the
	 * CampaignEventsGetEventDetails hook.
	 *
	 * @param string $icon
	 * @param string|Tag|array $content
	 * @param string $label
	 * @return Tag
	 */
	public static function makeSection( string $icon, $content, string $label ): Tag {
		$iconWidget = new IconWidget( [
			'icon' => $icon,
			'classes' => [ 'ext-campaignevents-event-details-icon' ]
		] );
		$header = ( new Tag( 'h3' ) )
			->appendContent( $iconWidget, $label )
			->addClasses( [ 'ext-campaignevents-event-details-section-header' ] );

		$contentTag = ( new Tag( 'div' ) )
			->appendContent( $content )
			->addClasses( [ 'ext-campaignevents-event-details-section-content' ] );

		return ( new Tag( 'div' ) )
			->appendContent( $header, $contentTag );
	}
}

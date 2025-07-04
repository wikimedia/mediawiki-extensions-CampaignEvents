<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use LogicException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Formatters\EventFormatter;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\Authority;
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
		'oojs-ui.styles.icons-content',
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
	private WikiLookup $wikiLookup;
	private ITopicRegistry $topicRegistry;
	private EventTypesRegistry $eventTypesRegistry;
	private EventFormatter $eventFormatter;

	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		OrganizersStore $organizersStore,
		PageURLResolver $pageURLResolver,
		UserLinker $userLinker,
		EventTimeFormatter $eventTimeFormatter,
		TrackingToolRegistry $trackingToolRegistry,
		CampaignEventsHookRunner $hookRunner,
		PermissionChecker $permissionChecker,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		EventTypesRegistry $eventTypesRegistry,
		EventFormatter $eventFormatter,
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
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
		$this->registration = $registration;
		$this->language = $language;
		$this->eventTypesRegistry = $eventTypesRegistry;
		$this->eventFormatter = $eventFormatter;
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
	}

	/**
	 * @param UserIdentity $viewingUser
	 * @param Authority $authority
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param string|false $wikiID
	 * @param OutputPage $out
	 * @param LinkRenderer $linkRenderer
	 * @return PanelLayout
	 *
	 * @note Ideally, this wouldn't use MW-specific classes for l10n, but it's hard-ish to avoid and
	 * probably not worth doing.
	 */
	public function createContent(
		UserIdentity $viewingUser,
		Authority $authority,
		bool $isOrganizer,
		bool $isParticipant,
		$wikiID,
		OutputPage $out,
		LinkRenderer $linkRenderer
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
			$linkRenderer,
			$isOrganizer,
			$isParticipant,
			$isLocalWiki,
			$wikiID,
			$organizersCount
		);
		// TDB Rename this column since it is not only the "organizer column"
		$organizersColumn = $this->getOrganizersColumn( $out, $organizersCount );

		$eventTypes = $this->registration->getTypes();
		$organizersColumn->appendContent( $this->getEventTypesSection( $out, $eventTypes ) );

		$this->hookRunner->onCampaignEventsGetEventDetails(
			$infoColumn,
			$organizersColumn,
			$eventID,
			$isOrganizer,
			$out,
			$isLocalWiki
		);

		$eventWikis = $this->getEventWikisSection( $out );
		if ( $eventWikis !== null ) {
			$organizersColumn->appendContent( $eventWikis );
		}

		$footer = $this->getFooter();
		$contentWrapper->appendContent( $infoColumn, $organizersColumn, $footer );
		return new PanelLayout( [
			'content' => [ $header, $contentWrapper ],
			'padded' => true,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-eventdetails-panel' ],
		] );
	}

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
			->addClasses( [ 'ext-campaignevents-eventdetails-info-topbar' ] );
	}

	/**
	 * Return a message to inform the user that they need to see the event details on the wiki of the event.
	 * This method must only be called if the event is not local.
	 *
	 * @param string|false $wikiID
	 * @param OutputPage $out
	 * @return HtmlSnippet
	 */
	private function getNonLocalWikiMessage( $wikiID, OutputPage $out ): HtmlSnippet {
		static $message = null;

		if ( $wikiID === WikiAwareEntity::LOCAL ) {
			throw new LogicException( __METHOD__ . ' called for the local wiki' );
		}

		if ( !$message ) {
			$wikiName = WikiMap::getWikiName( $wikiID );
			$foreignDetailsURL = WikiMap::getForeignURL(
				$wikiID, 'Special:' . SpecialEventDetails::PAGE_NAME . "/{$this->registration->getID()}"
			);
			$message = new HtmlSnippet(
				$out->msg( 'campaignevents-event-details-not-local-wiki-prompt' )
					->params( $foreignDetailsURL, $wikiName )
					->parse()
			);
		}

		return $message;
	}

	/**
	 * @param UserIdentity $viewingUser
	 * @param Authority $authority
	 * @param OutputPage $out
	 * @param LinkRenderer $linkRenderer
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param bool $isLocalWiki
	 * @param string|false $wikiID
	 * @param int $organizersCount
	 * @return Tag
	 */
	private function getInfoColumn(
		UserIdentity $viewingUser,
		Authority $authority,
		OutputPage $out,
		LinkRenderer $linkRenderer,
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
		$formattedTimezone = EventTimeFormatter::wrapTimeZoneForConversion(
			$this->eventTimeFormatter->formatTimezone( $this->registration, $viewingUser )
		);
		// XXX Can't use $this->msgFormatter due to parse()
		$timezoneMsg = $out->msg( 'campaignevents-event-details-timezone' )->params( $formattedTimezone )->parse();
		$items[] = self::makeSection(
			'clock',
			[
				new HtmlSnippet( EventTimeFormatter::wrapRangeForConversion( $this->registration, $datesMsg ) ),
				( new Tag( 'div' ) )->appendContent( new HtmlSnippet( $timezoneMsg ) )
			],
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-dates-label' ) ),
			[ 'ext-campaignevents-eventdetails-time' ]
		);

		$registrationAllowedVal = RegisterParticipantCommand::checkIsRegistrationAllowed(
			$this->registration,
			RegisterParticipantCommand::REGISTRATION_NEW
		);
		$canRegister = $registrationAllowedVal->isGood();

		$userCanViewSensitiveEventData = $this->permissionChecker->userCanViewSensitiveEventData( $authority );
		$items[] = $this->getParticipationOptionsSection(
			$authority,
			$isOrganizer,
			$isParticipant,
			$isLocalWiki,
			$wikiID,
			$organizersCount,
			$canRegister,
			$userCanViewSensitiveEventData,
			$out,
			$linkRenderer
		);

		$trackingToolsSection = $this->getTrackingToolsSection( $out->getTitle(), $linkRenderer );
		if ( $trackingToolsSection ) {
			$items[] = $trackingToolsSection;
		}

		$chatURL = $this->registration->getChatURL();
		if ( $userCanViewSensitiveEventData && $chatURL ) {
			if ( ( $isOrganizer || $isParticipant ) && !$isLocalWiki ) {
				$chatSectionContent = $this->getNonLocalWikiMessage( $wikiID, $out );
			} elseif ( $isOrganizer || $isParticipant ) {
				$chatSectionContent = new HtmlSnippet(
					$linkRenderer->makeExternalLink( $chatURL, $chatURL, $out->getTitle() )
				);
			} elseif ( $canRegister ) {
				$chatSectionContent = $this->getNeedsToRegisterMsg();
			} else {
				$chatSectionContent = null;
			}
		} elseif ( !$userCanViewSensitiveEventData && $chatURL && Utils::isSitewideBlocked( $authority ) ) {
			$chatSectionContent = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-sensitive-data-message-blocked-user' )
			);
		} else {
			$chatSectionContent = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-chat-link-not-available' )
			);
		}

		if ( $chatSectionContent ) {
			$items[] = self::makeSection(
				'speechBubbles',
				$chatSectionContent,
				$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-chat-link' ) )
			);
		}

		$eventTopics = $this->registration->getTopics();
		if ( $eventTopics ) {
			$items[] = $this->getEventTopicsSection( $out, $eventTopics );
		}

		return ( new Tag( 'div' ) )
			->appendContent( $items );
	}

	private function getNeedsToRegisterMsg(): string {
		return $this->msgFormatter->format(
			MessageValue::new( 'campaignevents-event-details-register-prompt' )
		);
	}

	private function getOrganizersColumn( OutputPage $out, int $organizersCount ): Tag {
		$ret = [];

		$partialOrganizers = $this->organizersStore->getEventOrganizers(
			$this->registration->getID(),
			self::ORGANIZERS_LIMIT
		);
		$langCode = $this->language->getCode();
		$organizerListItems = '';
		$lastOrganizerID = null;
		foreach ( $partialOrganizers as $organizer ) {
			$organizerListItems .= Html::rawElement(
				'li',
				[],
				$this->userLinker->generateUserLinkWithFallback( $out, $organizer->getUser(), $langCode )
			);
			$lastOrganizerID = $organizer->getOrganizerID();
		}
		$out->addJsConfigVars( [
			'wgCampaignEventsLastOrganizerID' => $lastOrganizerID,
		] );
		$organizersList = Html::rawElement(
			'ul',
			[ 'class' => 'ext-campaignevents-eventdetails-organizers-list' ],
			$organizerListItems
		);
		$ret[] = new HtmlSnippet( $organizersList );

		if ( count( $partialOrganizers ) < $organizersCount ) {
			$viewMoreBtn = new ButtonWidget( [
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-organizers-view-more' )
				),
				'classes' => [ 'ext-campaignevents-eventdetails-load-organizers-link' ],
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

	private function getEventWikisSection( OutputPage $out ): ?Tag {
		$eventWikis = $this->registration->getWikis();

		if ( $eventWikis === ExistingEventRegistration::ALL_WIKIS ) {
			$eventWikisContent = Html::element(
				'span',
				[],
				$out->msg( 'campaignevents-event-details-wikis-all' )->text()
			);
		} elseif ( count( $eventWikis ) > 0 ) {
			$currentWikiId = WikiMap::getCurrentWikiId();
			$curWikiKey = array_search( $currentWikiId, $eventWikis, true );
			if ( $curWikiKey !== false ) {
				unset( $eventWikis[ $curWikiKey ] );
				array_unshift( $eventWikis, $currentWikiId );
			}

			$eventWikiNames = $this->wikiLookup->getLocalizedNames( $eventWikis );
			$eventWikisListItems = '';
			foreach ( $eventWikiNames as $eventWiki ) {
				$eventWikisListItems .= Html::element( 'li', [], $eventWiki );
			}
			$eventWikisContent = Html::rawElement(
				'ul',
				[ 'class' => 'ext-campaignevents-eventdetails-event-wikis-list' ],
				$eventWikisListItems
			);
		} else {
			return null;
		}

		$wikiIcon = $this->wikiLookup->getWikiIcon( $eventWikis );
		$eventWikisSection = self::makeSection(
			$wikiIcon,
			new HtmlSnippet( $eventWikisContent ),
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-wikis-header' ) )
		);

		return ( new Tag( 'div' ) )->appendContent( $eventWikisSection );
	}

	/**
	 * @param OutputPage $out
	 * @param list<string> $eventTopics
	 * @return ?Tag
	 */
	private function getEventTopicsSection( OutputPage $out, array $eventTopics ): ?Tag {
		$localizedTopicNames = array_map(
			static fn ( string $msgKey ): string => $out->msg( $msgKey )->text(),
			$this->topicRegistry->getTopicMessages( $eventTopics )
		);
		sort( $localizedTopicNames );
		$eventTopicListItems = '';
		foreach ( $localizedTopicNames as $localizedTopic ) {
			$eventTopicListItems .= Html::element( 'li', [], $localizedTopic );
		}
		$eventTopicContent = Html::rawElement(
			'ul',
			[ 'class' => 'ext-campaignevents-eventdetails-event-topics-list' ],
			$eventTopicListItems
		);
		$eventTopicsSection = self::makeSection(
			'tag',
			new HtmlSnippet( $eventTopicContent ),
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-topics-header' ) )
		);

		return $eventTopicsSection;
	}

	/**
	 * Renders the section with the list of event types.
	 *
	 * @param OutputPage $out
	 * @phan-param list<string> $eventTypes
	 * @return Tag
	 */
	private function getEventTypesSection( OutputPage $out, array $eventTypes ): Tag {
		$messageKeys = $this->eventTypesRegistry->getTypeMessages( $eventTypes );

		$localizedEventTypeNames = array_map(
			static fn ( string $msgKey ): string => $out->msg( $msgKey )->escaped(),
			$messageKeys
		);

		sort( $localizedEventTypeNames );
		$eventTypeContent = new HtmlSnippet( $this->language->commaList( $localizedEventTypeNames ) );
		$eventTypesSection = self::makeSection(
			'folderPlaceholder',
			$eventTypeContent,
			$out->msg( 'campaignevents-event-details-event-types-header' )->text()
		);

		return $eventTypesSection;
	}

	/**
	 * @param Authority $performer
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param bool $isLocalWiki
	 * @param string|false $wikiID
	 * @param int $organizersCount
	 * @param bool $canRegister
	 * @param bool $userCanViewSensitiveEventData
	 * @param OutputPage $out
	 * @param LinkRenderer $linkRenderer
	 * @return Tag
	 */
	private function getParticipationOptionsSection(
		Authority $performer,
		bool $isOrganizer,
		bool $isParticipant,
		bool $isLocalWiki,
		$wikiID,
		int $organizersCount,
		bool $canRegister,
		bool $userCanViewSensitiveEventData,
		OutputPage $out,
		LinkRenderer $linkRenderer
	): Tag {
		$participationOptions = $this->registration->getParticipationOptions();
		$items = [];
		if ( $participationOptions & ExistingEventRegistration::PARTICIPATION_OPTION_IN_PERSON ) {
			$items[] = ( new Tag( 'h4' ) )
				->appendContent( $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-in-person-event-label' )
				) );

			$meetingAddress = $this->registration->getAddress();
			if ( $meetingAddress ) {
				$stringDir = Utils::guessStringDirection( $meetingAddress->getAddressWithoutCountry() ?? '' );
				$formattedAddress = $this->eventFormatter->formatAddress(
					$meetingAddress,
					$out->getLanguage()->getCode()
				);
				$items[] = ( new Tag( 'div' ) )
					->appendContent( $formattedAddress )
					->setAttributes( [ 'dir' => $stringDir ] );
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

		if ( $participationOptions & ExistingEventRegistration::PARTICIPATION_OPTION_ONLINE ) {
			$items[] = ( new Tag( 'h4' ) )
				->appendContent( $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-online-label' )
				) );

			$meetingURL = $this->registration->getMeetingURL();
			if ( $userCanViewSensitiveEventData && $meetingURL ) {
				if ( ( $isOrganizer || $isParticipant ) && !$isLocalWiki ) {
					$items[] = $this->getNonLocalWikiMessage( $wikiID, $out );
				} elseif ( $isOrganizer || $isParticipant ) {
					$items[] = new HtmlSnippet(
						$linkRenderer->makeExternalLink( $meetingURL, $meetingURL, $out->getTitle() )
					);
				} elseif ( $canRegister ) {
					$items[] = $this->getNeedsToRegisterMsg();
				}
			} elseif ( !$userCanViewSensitiveEventData && $meetingURL && Utils::isSitewideBlocked( $performer ) ) {
				$items[] = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-sensitive-data-message-blocked-user' )
				);
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
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-participation-options-header' )
			)
		);
	}

	private function getTrackingToolsSection(
		PageIdentity $currentPage,
		LinkRenderer $linkRenderer
	): ?Tag {
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
		$sectionItems[] = new HtmlSnippet( $linkRenderer->makeExternalLink( $courseURL, $courseURL, $currentPage ) );

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
			->addClasses( [ 'ext-campaignevents-eventdetails-tracking-tool-sync-details' ] )
			->appendContent( htmlspecialchars( $msgLastSync ) );
		$sectionItems[] = new MessageWidget( [
			'type' => $msgType,
			'label' => new HtmlSnippet( htmlspecialchars( $msgStatus ) . $syncDetailsRawParagraph ),
			'classes' => [ 'ext-campaignevents-eventdetails-tracking-tool-sync' ],
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
			'classes' => [ 'ext-campaignevents-eventdetails-view-event-page-button' ],
			'href' => $this->pageURLResolver->getUrl( $this->registration->getPage() )
		] );
	}

	/**
	 * Builds a section for the info panel. This method might be called in handlers of the
	 * CampaignEventsGetEventDetails hook.
	 *
	 * @param string $icon
	 * @param string|Tag|array|HtmlSnippet $content
	 * @param string $label
	 * @param string[] $classes
	 * @return Tag
	 */
	public static function makeSection( string $icon, $content, string $label, array $classes = [] ): Tag {
		$iconWidget = new IconWidget( [
			'icon' => $icon,
			'classes' => [ 'ext-campaignevents-eventdetails-icon' ]
		] );
		$header = ( new Tag( 'h3' ) )
			->appendContent( $iconWidget, $label )
			->addClasses( [ 'ext-campaignevents-eventdetails-section-header' ] );

		$contentTag = ( new Tag( 'div' ) )
			->appendContent( $content )
			->addClasses( [ 'ext-campaignevents-eventdetails-section-content', ...$classes ] );

		return ( new Tag( 'div' ) )
			->appendContent( $header, $contentTag );
	}
}

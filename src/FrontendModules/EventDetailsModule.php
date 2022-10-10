<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Html;
use Language;
use Linker;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Extension\CampaignEvents\Widget\IconLabelContentWidget;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\User\UserIdentity;
use OOUI\ButtonWidget;
use OOUI\HtmlSnippet;
use OOUI\IconWidget;
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
	];

	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var PageURLResolver */
	private $pageURLResolver;
	/** @var UserLinker */
	private $userLinker;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param OrganizersStore $organizersStore
	 * @param PageURLResolver $pageURLResolver
	 * @param UserLinker $userLinker
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		OrganizersStore $organizersStore,
		PageURLResolver $pageURLResolver,
		UserLinker $userLinker
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->organizersStore = $organizersStore;
		$this->pageURLResolver = $pageURLResolver;
		$this->userLinker = $userLinker;
	}

	/**
	 * @param Language $language
	 * @param ExistingEventRegistration $registration
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
		Language $language,
		ExistingEventRegistration $registration,
		UserIdentity $viewingUser,
		bool $isOrganizer,
		bool $isParticipant,
		OutputPage $out
	): PanelLayout {
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );
		$eventID = $registration->getID();

		$items = [];

		$headerItems = [];
		$headerItems[] = ( new Tag( 'span' ) )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-label' )
			)
		)->addClasses( [ 'ext-campaignevents-event-details-info-header' ] );

		if ( $isOrganizer ) {
			$headerItems[] = new ButtonWidget( [
				'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-event-details-edit-button' ) ),
				'href' => SpecialPage::getTitleFor(
					SpecialEditEventRegistration::PAGE_NAME,
					(string)$registration->getID()
				)->getLocalURL(),
				'icon' => 'edit'
			] );
		}

		$items[] = ( new Tag( 'div' ) )
			->appendContent( $headerItems )
			->addClasses( [ 'ext-campaignevents-event-details-info-topbar' ] );

		$items[] = new TextWithIconWidget( [
			'icon' => 'clock',
			'content' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-dates' )->params(
					$language->userTimeAndDate( $registration->getStartUTCTimestamp(), $viewingUser ),
					$language->userDate( $registration->getStartUTCTimestamp(), $viewingUser ),
					$language->userTime( $registration->getStartUTCTimestamp(), $viewingUser ),
					$language->userTimeAndDate( $registration->getEndUTCTimestamp(), $viewingUser ),
					$language->userDate( $registration->getEndUTCTimestamp(), $viewingUser ),
					$language->userTime( $registration->getEndUTCTimestamp(), $viewingUser )
				)
			),
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-event-details-dates-label' ) ),
			'icon_classes' => [ 'ext-campaignevents-event-details-icons-style' ],
		] );

		$needToRegisterMsg = ( new Tag( 'p' ) )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-register-prompt' )
			)
		);

		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $eventID );

		$items = array_merge(
			$items,
			$this->getLocationContent(
				$registration,
				$msgFormatter,
				$isOrganizer,
				$isParticipant,
				$organizersCount,
				$needToRegisterMsg
			)
		);

		$chatURL = $registration->getChatURL();
		if ( $chatURL ) {
			$items[] = ( new Tag() )->appendContent(
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-chat-link' )
				)
			)->addClasses( [ 'ext-campaignevents-event-details-section-header' ] );

			if ( $isOrganizer || $isParticipant ) {
				$iconLink = ( new IconWidget( [
					'icon' => 'link',
				] ) )->addClasses( [ 'ext-campaignevents-event-details-icons-style' ] );
				$items[] = $iconLink;
				$items[] = new HtmlSnippet(
					Linker::makeExternalLink(
						$chatURL,
						$chatURL,
						true,
						'',
						[ 'class' => 'ext-campaignevents-event-details-icon-link' ]
					)
				);
			} else {
				$items[] = $needToRegisterMsg;
			}
		}

		$organizerSectionElements = $this->getOrganizersSectionElements(
			$msgFormatter,
			$out,
			$eventID,
			$organizersCount
		);
		$items = array_merge( $items, $organizerSectionElements );

		$items[] = new ButtonWidget( [
			'flags' => [ 'progressive' ],
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-event-details-view-event-page' ) ),
			'classes' => [ 'ext-campaignevents-event-details-view-event-page-button' ],
			'href' => $this->pageURLResolver->getUrl( $registration->getPage() )
		] );

		return new PanelLayout( [
			'content' => $items,
			'padded' => true,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-eventdetails-panel' ],
		] );
	}

	/**
	 * @param ITextFormatter $msgFormatter
	 * @param OutputPage $out
	 * @param int $eventID
	 * @param int $organizersCount
	 * @return array
	 */
	private function getOrganizersSectionElements(
		ITextFormatter $msgFormatter,
		OutputPage $out,
		int $eventID,
		int $organizersCount
	): array {
		$ret = [];
		$ret[] = ( new Tag() )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-organizers-header' )
			)
		)->addClasses( [ 'ext-campaignevents-event-details-section-header' ] );

		$partialOrganizers = $this->organizersStore->getEventOrganizers( $eventID, self::ORGANIZERS_LIMIT );
		$langCode = $msgFormatter->getLangCode();
		$organizerListItems = '';
		$lastOrganizerID = null;
		foreach ( $partialOrganizers as $organizer ) {
			$organizerListItems .= Html::rawElement(
				'li',
				[],
				$this->userLinker->generateUserLinkWithFallback( $organizer->getUser(), $langCode )
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
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-organizers-view-more' )
				),
				'classes' => [ 'ext-campaignevents-event-details-load-organizers-link' ],
				'framed' => false,
				'flags' => [ 'progressive' ]
			] );
			$viewMoreNoscript = ( new Tag( 'noscript' ) )
				->appendContent(
					$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-organizers-noscript' ) )
				);
			$ret[] = ( new Tag( 'p' ) )->appendContent( $viewMoreBtn, $viewMoreNoscript );
		}

		return $ret;
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ITextFormatter $msgFormatter
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param int $organizersCount
	 * @param Tag $needToRegisterMsg
	 * @return array
	 */
	private function getLocationContent(
		ExistingEventRegistration $registration,
		ITextFormatter $msgFormatter,
		bool $isOrganizer,
		bool $isParticipant,
		int $organizersCount,
		Tag $needToRegisterMsg
	): array {
		$meetingType = $registration->getMeetingType();
		$items = [];
		if ( $meetingType & ExistingEventRegistration::MEETING_TYPE_IN_PERSON ) {
			$rawAddress = $registration->getMeetingAddress();
			$rawCountry = $registration->getMeetingCountry();
			if ( $rawAddress || $rawCountry ) {
				// NOTE: This is not pretty if exactly one of address and country is specified, but
				// that's going to be fixed when we switch to using an actual geocoding service (T309325)
				$address = $rawAddress . "\n" . $rawCountry;
				$widgetAttribs = [
					'content' => $address,
					'content_direction' => Utils::guessStringDirection( $address ),
				];
			} else {
				$widgetAttribs = [
					'content' => $msgFormatter->format(
						MessageValue::new( 'campaignevents-event-details-venue-not-available' )
							->numParams( $organizersCount )
					),
				];
			}
			$items[] = new IconLabelContentWidget( $widgetAttribs + [
				'icon' => 'mapPin',
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-in-person-event-label' )
				),
				'icon_classes' => [ 'ext-campaignevents-event-details-icons-style' ],
			] );
		}

		if ( $meetingType & ExistingEventRegistration::MEETING_TYPE_ONLINE ) {
			$meetingURL = $registration->getMeetingURL();
			if ( $meetingURL ) {
				if ( $isOrganizer || $isParticipant ) {
					$iconLink = ( new IconWidget( [
						'icon' => 'link',
					] ) )->addClasses( [ 'ext-campaignevents-event-details-icons-style' ] );
					$content = [
						$iconLink,
						new HtmlSnippet(
							Linker::makeExternalLink(
								$meetingURL,
								$meetingURL,
								true,
								'',
								[ 'class' => 'ext-campaignevents-event-details-icon-link' ]
							)
						)
					];
				} else {
					$content = $needToRegisterMsg;
				}
			} else {
				$content = $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-online-link-not-available' )
						->numParams( $organizersCount )
				);
			}

			$items[] = new IconLabelContentWidget( [
				'icon' => $meetingType === ExistingEventRegistration::MEETING_TYPE_ONLINE ? 'mapPin' : '',
				'content' => $content,
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-online-label' )
				),
				'icon_classes' => [ 'ext-campaignevents-event-details-icons-style' ],
			] );
		}

		return $items;
	}
}

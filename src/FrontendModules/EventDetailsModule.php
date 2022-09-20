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
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class EventDetailsModule {
	private const ORGANIZERS_LIMIT = 10;

	public const MODULE_STYLES = [
		'oojs-ui.styles.icons-location',
		'oojs-ui.styles.icons-interactions',
		'oojs-ui.styles.icons-editing-core',
	];

	/**
	 * @param Language $language
	 * @param ExistingEventRegistration $registration
	 * @param UserIdentity $viewingUser
	 * @param ITextFormatter $msgFormatter
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param OrganizersStore $organizersStore
	 * @param PageURLResolver $pageURLResolver
	 * @param UserLinker $userLinker
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
		ITextFormatter $msgFormatter,
		bool $isOrganizer,
		bool $isParticipant,
		OrganizersStore $organizersStore,
		PageURLResolver $pageURLResolver,
		UserLinker $userLinker,
		OutputPage $out
	): PanelLayout {
		$eventID = $registration->getID();

		$items = [];
		$items[] = ( new Tag( 'span' ) )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-label' )
			)
		)->addClasses( [ 'ext-campaignevents-event-details-info-header' ] );

		if ( $isOrganizer ) {
			$items[] = new ButtonWidget( [
				'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-event-details-edit-button' ) ),
				'classes' => [ 'ext-campaignevents-details-edit-button' ],
				'href' => SpecialPage::getTitleFor(
					SpecialEditEventRegistration::PAGE_NAME,
					(string)$registration->getID()
				)->getLocalURL(),
				'icon' => 'edit'
			] );
		}

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

		$organizersCount = $organizersStore->getOrganizerCountForEvent( $eventID );

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
				$items[] = ( new Tag() )->appendContent(
					$iconLink,
					new HtmlSnippet(
						Linker::makeExternalLink(
							$chatURL,
							$chatURL,
							true,
							'',
							[ 'class' => 'ext-campaignevents-event-details-icon-link' ]
						)
					)
				);
			} else {
				$items[] = $needToRegisterMsg;
			}
		}

		$organizerSectionElements = $this->getOrganizersSectionElements(
			$msgFormatter,
			$organizersStore,
			$userLinker,
			$out,
			$eventID,
			$organizersCount
		);
		$items = array_merge( $items, $organizerSectionElements );

		$items[] = new ButtonWidget( [
			'flags' => [ 'progressive' ],
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-event-details-view-event-page' ) ),
			'classes' => [ 'ext-campaignevents-event-details-view-event-page-button' ],
			'href' => $pageURLResolver->getUrl( $registration->getPage() )
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
	 * @param OrganizersStore $organizersStore
	 * @param UserLinker $userLinker
	 * @param OutputPage $out
	 * @param int $eventID
	 * @param int $organizersCount
	 * @return array
	 */
	private function getOrganizersSectionElements(
		ITextFormatter $msgFormatter,
		OrganizersStore $organizersStore,
		UserLinker $userLinker,
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

		$partialOrganizers = $organizersStore->getEventOrganizers( $eventID, self::ORGANIZERS_LIMIT );
		$langCode = $msgFormatter->getLangCode();
		$organizerListItems = '';
		$lastOrganizerID = null;
		foreach ( $partialOrganizers as $organizer ) {
			$organizerListItems .= Html::rawElement(
				'li',
				[],
				$userLinker->generateUserLinkWithFallback( $organizer->getUser(), $langCode )
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
					$content = ( new Tag() )->appendContent(
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
					);
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

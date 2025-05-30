<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Notifications;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\Language\RawMessage;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;

class RegistrationNotificationPresentationModel extends EchoEventPresentationModel {
	public const NOTIFICATION_NAME = 'campaign-events-notification-registration-confirmation';
	public const ICON_NAME = 'campaignevents-registration';

	private const ORGANIZERS_LIMIT = 4;

	private ExistingEventRegistration $eventRegistration;
	private PageURLResolver $pageUrlResolver;
	private EventTimeFormatter $eventTimeFormatter;
	private OrganizersStore $organizersStore;
	private UserLinker $userLinker;

	/**
	 * @param Event $event
	 * @param Language $language
	 * @param User $user Only used for permissions checking and GENDER
	 * @param string $distributionType
	 */
	protected function __construct(
		Event $event,
		Language $language,
		User $user,
		$distributionType
	) {
		parent::__construct( $event, $language, $user, $distributionType );
		$eventLookup = CampaignEventsServices::getEventLookup();
		$eventID = $this->event->getExtraParam( 'event-id' );
		$this->eventRegistration = $eventLookup->getEventByID( $eventID );
		$this->pageUrlResolver = CampaignEventsServices::getPageURLResolver();
		$this->eventTimeFormatter = CampaignEventsServices::getEventTimeFormatter();
		$this->organizersStore = CampaignEventsServices::getOrganizersStore();
		$this->userLinker = CampaignEventsServices::getUserLinker();
	}

	/**
	 * @inheritDoc
	 */
	public function canRender(): bool {
		if ( $this->getDistributionType() !== 'email' ) {
			return false;
		}

		if ( $this->eventRegistration->getDeletionTimestamp() !== null ) {
			return false;
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconType(): string {
		return self::ICON_NAME;
	}

	/**
	 * @inheritDoc
	 */
	public function getSubjectMessage(): Message {
		return $this->msg( 'campaignevents-notification-registration-subject' )
			->params( $this->eventRegistration->getName() );
	}

	/**
	 * @inheritDoc
	 */
	public function getHeaderMessage() {
		$eventPageLink = $this->makeLink(
			$this->pageUrlResolver->getFullUrl( $this->eventRegistration->getPage() ),
			$this->eventRegistration->getName()
		);
		$ret = $this->msg( 'campaignevents-notification-registration-header-intro' )
			->rawParams( $eventPageLink )
			->escaped();

		$chatURL = $this->eventRegistration->getChatURL();
		if ( $chatURL !== null ) {
			$joinChatLink = $this->makeLink(
				$chatURL,
				$this->msg( 'campaignevents-notification-registration-header-chat-label' )->text()
			);
			$ret .= Html::rawElement(
				'p',
				[],
				$this->msg( 'campaignevents-notification-registration-header-chat' )
					->rawParams( $joinChatLink )
					->escaped()
			);
		}

		return new RawMessage( '$1', [ Message::rawParam( $ret ) ] );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyMessage() {
		$body = Html::element(
			'h1',
			[],
			$this->msg( 'campaignevents-notification-registration-details-header' )->text()
		);

		$body .= $this->getDatesBodySection();
		$body .= $this->getTypeBodySection();
		$body .= $this->gerOrganizersBodySection();
		$body .= $this->msg( 'campaignevents-notification-registration-collaboration-list-link' )->parse();
		$body = Html::rawElement(
			'div',
			[ 'style' => 'font-weight:normal' ],
			$body
		);

		return new RawMessage( '$1', [ Message::rawParam( $body ) ] );
	}

	/**
	 * @return string
	 */
	private function getDatesBodySection(): string {
		$ret = Html::element(
			'h2',
			[],
			$this->msg( 'campaignevents-notification-registration-details-dates-header' )->text()
		);

		$formattedStart = $this->eventTimeFormatter->formatStart(
			$this->eventRegistration,
			$this->language,
			$this->getUser()
		);
		$formattedEnd = $this->eventTimeFormatter->formatEnd(
			$this->eventRegistration,
			$this->language,
			$this->getUser()
		);
		$datesMsg = $this->msg( 'campaignevents-notification-registration-details-dates' )->params(
			$formattedStart->getTimeAndDate(),
			$formattedStart->getDate(),
			$formattedStart->getTime(),
			$formattedEnd->getTimeAndDate(),
			$formattedEnd->getDate(),
			$formattedEnd->getTime()
		)->text();
		$ret .= Html::element( 'p', [], $datesMsg );

		$formattedTimezone = $this->eventTimeFormatter->formatTimezone( $this->eventRegistration, $this->getUser() );
		$timezoneMsg = $this->msg( 'campaignevents-notification-registration-details-dates-timezone' )
			->params( $formattedTimezone )
			->parse();
		$ret .= Html::rawElement( 'div', [], $timezoneMsg );

		return $ret;
	}

	/**
	 * @return string
	 */
	private function getTypeBodySection(): string {
		$ret = Html::element(
			'h2',
			[],
			$this->msg( 'campaignevents-notification-registration-details-type-header' )->text()
		);

		$participationOptions = $this->eventRegistration->getParticipationOptions();

		if ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_ONLINE ) {
			$ret .= Html::element(
				'h3',
				[],
				$this->msg( 'campaignevents-notification-registration-details-type-online-header' )->text()
			);
			$meetingUrl = $this->eventRegistration->getMeetingURL();
			if ( $meetingUrl !== null ) {
				$meetingLink = $this->makeLink( $meetingUrl, $meetingUrl );
				$ret .= Html::rawElement( 'p', [], $meetingLink );
			}
		}
		if ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_IN_PERSON ) {
			$ret .= Html::element(
				'h3',
				[],
				$this->msg( 'campaignevents-notification-registration-details-type-in-person-header' )->text()
			);
			$address = $this->eventRegistration->getMeetingAddress();
			$country = $this->eventRegistration->getMeetingCountry();
			if ( $address || $country ) {
				$ret .= Html::element(
					'p',
					[ 'style' => 'white-space: pre-wrap' ],
					$address . "\n" . $country
				);
			}
		}

		return $ret;
	}

	private function gerOrganizersBodySection(): string {
		$ret = Html::element(
			'h2',
			[],
			$this->msg( 'campaignevents-notification-registration-details-organizers-header' )->text()
		);

		$partialOrganizers = $this->organizersStore->getEventOrganizers(
			$this->eventRegistration->getID(),
			self::ORGANIZERS_LIMIT
		);
		$organizersCount = $this->organizersStore->getOrganizerCountForEvent( $this->eventRegistration->getID() );
		$ctx = RequestContext::getMain();
		$organizerLinks = [];
		foreach ( $partialOrganizers as $organizer ) {
			try {
				$organizerLinks[] = $this->userLinker->generateUserLink( $ctx, $organizer->getUser() );
			} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
				// Can't easily include CSS styles in the message, so skip.
				continue;
			}
		}
		if ( $organizersCount > self::ORGANIZERS_LIMIT ) {
			$moreOrganizersMsg = $this->msg( 'campaignevents-notification-registration-details-organizers-more' )
				->numParams( $organizersCount - self::ORGANIZERS_LIMIT )
				->text();
			$eventDetailsPage = SpecialPage::getTitleFor(
				SpecialEventDetails::PAGE_NAME,
				(string)$this->eventRegistration->getID()
			);
			$organizerLinks[] = $this->makeLink( $eventDetailsPage->getFullURL(), $moreOrganizersMsg );
		}
		$ret .= Html::rawElement(
			'p',
			[],
			$this->language->listToText( $organizerLinks )
		);

		return $ret;
	}

	/**
	 * @inheritDoc
	 */
	public function getPrimaryLink() {
		return [
			'url' => $this->pageUrlResolver->getFullUrl( $this->eventRegistration->getPage() ),
			'label' => $this->msg( 'campaignevents-notification-registration-event-page-link' )->text(),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getSecondaryLinks(): array {
		$links = [];

		$chatURL = $this->eventRegistration->getChatURL();
		if ( $chatURL !== null ) {
			$links[] = [
				'url' => $chatURL,
				'label' => $this->msg( 'campaignevents-notification-registration-chat-link' )->text()
			];
		}

		return $links;
	}

	/**
	 * @param string $url
	 * @param string $label Raw label, must not be escaped beforehand
	 * @return string
	 */
	private function makeLink( string $url, string $label ): string {
		return Html::element(
			'a',
			[ 'href' => $url ],
			$label
		);
	}
}

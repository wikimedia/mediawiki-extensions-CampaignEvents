<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use OOUI\ButtonWidget;
use OOUI\CheckboxInputWidget;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\IconWidget;
use OOUI\PanelLayout;
use OOUI\SearchInputWidget;
use OOUI\Tag;
use OutputPage;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class EventDetailsParticipantsModule {

	private const PARTICIPANTS_LIMIT = 20;
	public const MODULE_STYLES = [
		'oojs-ui.styles.icons-moderation',
		'oojs-ui.styles.icons-user',
		...UserLinker::MODULE_STYLES
	];

	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;
	/** @var UserLinker */
	private $userLinker;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;
	/** @var PermissionChecker */
	private PermissionChecker $permissionChecker;
	/** @var UserFactory */
	private UserFactory $userFactory;
	/** @var CampaignsUserMailer */
	private CampaignsUserMailer $userMailer;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param UserLinker $userLinker
	 * @param ParticipantsStore $participantsStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param PermissionChecker $permissionChecker
	 * @param UserFactory $userFactory
	 * @param CampaignsUserMailer $userMailer
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		UserLinker $userLinker,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup,
		PermissionChecker $permissionChecker,
		UserFactory $userFactory,
		CampaignsUserMailer $userMailer
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->userLinker = $userLinker;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->permissionChecker = $permissionChecker;
		$this->userFactory = $userFactory;
		$this->userMailer = $userMailer;
	}

	/**
	 * @param Language $language
	 * @param ExistingEventRegistration $event
	 * @param UserIdentity $viewingUser
	 * @param ICampaignsAuthority $authority
	 * @param bool $isOrganizer
	 * @param OutputPage $out
	 * @return Tag
	 *
	 * @note Ideally, this wouldn't use MW-specific classes for l10n, but it's hard-ish to avoid and
	 * probably not worth doing.
	 */
	public function createContent(
		Language $language,
		ExistingEventRegistration $event,
		UserIdentity $viewingUser,
		ICampaignsAuthority $authority,
		bool $isOrganizer,
		OutputPage $out
	): Tag {
		$eventID = $event->getID();
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );
		$totalParticipants = $this->participantsStore->getFullParticipantCountForEvent( $eventID );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $authority );
			$curUserParticipant = $this->participantsStore->getEventParticipant( $eventID, $centralUser, true );
		} catch ( UserNotGlobalException $_ ) {
			$curUserParticipant = null;
		}

		$showPrivateParticipants = $this->permissionChecker->userCanViewPrivateParticipants( $authority, $eventID );
		$otherParticipantsNum = $curUserParticipant ? self::PARTICIPANTS_LIMIT - 1 : self::PARTICIPANTS_LIMIT;
		$otherParticipants = $this->participantsStore->getEventParticipants(
			$eventID,
			$otherParticipantsNum,
			null,
			null,
			null,
			$showPrivateParticipants,
			isset( $centralUser ) ? [ $centralUser->getCentralID() ] : null
		);
		$lastParticipant = $otherParticipants ? end( $otherParticipants ) : $curUserParticipant;
		$lastParticipantID = $lastParticipant ? $lastParticipant->getParticipantID() : null;
		$canRemoveParticipants = false;
		if ( $isOrganizer ) {
			$canRemoveParticipants = UnregisterParticipantCommand::checkIsUnregistrationAllowed( $event ) ===
				UnregisterParticipantCommand::CAN_UNREGISTER;
		}

		$items = [];
		$items[] = $this->getHeader( $msgFormatter, $totalParticipants, $canRemoveParticipants );
		if ( $totalParticipants ) {
			$items[] = $this->getTableHeaders( $msgFormatter, $canRemoveParticipants );
		}
		$items[] = $this->getEmptyStateElement( $totalParticipants, $msgFormatter );
		$items[] = $this->getParticipantsContainer(
			$curUserParticipant,
			$otherParticipants,
			$canRemoveParticipants,
			$language,
			$viewingUser,
			$msgFormatter
		);

		$out->addJsConfigVars( [
			// TODO This may change when we add the feature to send messages
			'wgCampaignEventsShowParticipantCheckboxes' => $canRemoveParticipants,
			'wgCampaignEventsShowPrivateParticipants' => $showPrivateParticipants,
			'wgCampaignEventsEventDetailsParticipantsTotal' => $totalParticipants,
			'wgCampaignEventsLastParticipantID' => $lastParticipantID,
			'wgCampaignEventsCurUserCentralID' => isset( $centralUser ) ? $centralUser->getCentralID() : null,
		] );

		$layout = new PanelLayout( [
			'content' => $items,
			'padded' => false,
			'framed' => true,
			'expanded' => false,
		] );

		$content = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-event-details-participants-panel' ] )
			->appendContent( $layout );

		$footer = $this->getFooter( $eventID, $msgFormatter );
		if ( $footer ) {
			$content->appendContent( $footer );
		}

		return $content;
	}

	/**
	 * @param ITextFormatter $msgFormatter
	 * @param int $totalParticipants
	 * @return Tag
	 */
	private function getHeader(
		ITextFormatter $msgFormatter,
		int $totalParticipants,
		bool $viewerCanRemoveParticipants
	): Tag {
		$headerText = ( new Tag( 'div' ) )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-header-participants' )
					->numParams( $totalParticipants )
			)
		)->addClasses( [ 'ext-campaignevents-details-participants-header-text' ] );

		$header = ( new Tag() )->appendContent(
			$headerText
		)->addClasses( [ 'ext-campaignevents-details-participants-header' ] );

		if ( $totalParticipants ) {
			$header->appendContent( $this->getSearchBar( $msgFormatter, $viewerCanRemoveParticipants ) );
		}

		return $header;
	}

	/**
	 * @param int $totalParticipants
	 * @param ITextFormatter $msgFormatter
	 * @return Tag
	 */
	private function getEmptyStateElement( int $totalParticipants, ITextFormatter $msgFormatter ): Tag {
		$noParticipantsIcon = new IconWidget( [
			'icon' => 'userGroup',
			'classes' => [ 'ext-campaignevents-event-details-no-participants-icon' ]
		] );

		$noParticipantsClasses = [ 'ext-campaignevents-details-no-participants-state' ];
		if ( $totalParticipants > 0 ) {
			$noParticipantsClasses[] = 'ext-campaignevents-details-hide-element';
		}
		return ( new Tag() )->appendContent(
			$noParticipantsIcon,
			( new Tag() )->appendContent(
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-no-participants-state' )
				)
			)->addClasses( [ 'ext-campaignevents-details-no-participants-description' ] )
		)->addClasses( $noParticipantsClasses );
	}

	/**
	 * @param ITextFormatter $msgFormatter
	 * @param bool $viewerCanRemoveParticipants
	 * @return Tag
	 */
	private function getSearchBar( ITextFormatter $msgFormatter, bool $viewerCanRemoveParticipants ): Tag {
		$container = ( new Tag( 'div' ) )->appendContent(
			new SearchInputWidget( [
				'placeholder' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-search-participants-placeholder' )
				),
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-details-participants-search' ]
			] )
		)->addClasses( [ 'ext-campaignevents-details-participants-search-container' ] );
		if ( $viewerCanRemoveParticipants ) {
			$removeButton = new ButtonWidget( [
				'infusable' => true,
				'framed' => true,
				'flags' => [
					'destructive'
				],
				'disabled' => true,
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-remove-participant-remove-btn' )
				),
				'id' => 'ext-campaignevents-event-details-remove-participant-button',
				'classes' => [ 'ext-campaignevents-event-details-remove-participant-button' ],
			] );
			$messageAllParticipantsButton = new ButtonWidget( [
				'infusable' => true,
				'framed' => true,
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-message-all' )
				),
				'flags' => [ 'progressive' ],
				'classes' => [ 'ext-campaignevents-event-details-message-all-participants-button' ],
			] );
			$container->appendContent( $removeButton, $messageAllParticipantsButton );
		}
		return $container;
	}

	/**
	 * @param ITextFormatter $msgFormatter
	 * @return Tag
	 */
	private function getTableHeaders( ITextFormatter $msgFormatter, bool $canRemoveParticipants ): Tag {
		$container = ( new Tag( 'div' ) )->addClasses( [ 'ext-campaignevents-details-user-actions-container' ] );
		$selectAllCheckBoxField = new FieldLayout(
			new CheckboxInputWidget( [
				'name' => 'event-details-select-all-participants',
			] ),
			[
				'align' => 'inline',
				'classes' => [ 'ext-campaignevents-event-details-select-all-participant-checkbox-field' ],
				'infusable' => true,
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-select-all' )
				),
				'invisibleLabel' => true
			]
		);

		$headings = [
			$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-participants' ) ),
			$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-time-registered' ) ),
			$msgFormatter->format( MessageValue::new( 'campaignevents-event-details-has-email' ) )
		];
		$row = new Tag( 'div' );
		if ( $canRemoveParticipants ) {
			$row->appendContent( $selectAllCheckBoxField );
		}
		$row->addClasses( [ 'ext-campaignevents-details-user-actions-row' ] );
		foreach ( $headings as $heading ) {
			$row->appendContent(
				( new Tag( 'div' ) )->appendContent( $heading )
					->addClasses( [ 'ext-campaignevents-details-user-actions-heading' ] ) );
		}
		$container->appendContent( $row );
		return $container;
	}

	/**
	 * @param Participant|null $curUserParticipant
	 * @param Participant[] $otherParticipants
	 * @param bool $canRemoveParticipants
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @param ITextFormatter $msgFormatter
	 * @return Tag
	 */
	private function getParticipantsContainer(
		?Participant $curUserParticipant,
		array $otherParticipants,
		bool $canRemoveParticipants,
		Language $language,
		UserIdentity $viewingUser,
		ITextFormatter $msgFormatter
	): Tag {
		$participantsContainer = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-details-users-container' ] );
		if ( !$curUserParticipant && !$otherParticipants ) {
			$participantsContainer->addClasses( [ 'ext-campaignevents-details-hide-element' ] );
		}

		$participantRows = $this->getParticipantRows(
			$curUserParticipant,
			$otherParticipants,
			$canRemoveParticipants,
			$language,
			$viewingUser,
			$msgFormatter
		);

		$participantsContainer->appendContent( $participantRows );

		return $participantsContainer;
	}

	/**
	 * @param Participant|null $curUserParticipant
	 * @param Participant[] $otherParticipants
	 * @param bool $canRemoveParticipants
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @param ITextFormatter $msgFormatter
	 * @return Tag
	 */
	private function getParticipantRows(
		?Participant $curUserParticipant,
		array $otherParticipants,
		bool $canRemoveParticipants,
		Language $language,
		UserIdentity $viewingUser,
		ITextFormatter $msgFormatter
	): Tag {
		$participantRows = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-details-users-rows-container' ] );

		if ( $curUserParticipant ) {
			$participantRows->appendContent(
				$this->getCurUserParticipantRow(
					$curUserParticipant,
					$canRemoveParticipants,
					$language,
					$viewingUser,
					$msgFormatter
				)
			);
		}

		foreach ( $otherParticipants as $participant ) {
			$participantRows->appendContent(
				$this->getParticipantRow( $participant, $canRemoveParticipants, $language, $viewingUser, $msgFormatter )
			);
		}
		return $participantRows;
	}

	/**
	 * @param Participant $participant
	 * @param bool $canRemoveParticipants
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @param ITextFormatter $msgFormatter
	 * @return Tag
	 */
	private function getCurUserParticipantRow(
		Participant $participant,
		bool $canRemoveParticipants,
		Language $language,
		UserIdentity $viewingUser,
		ITextFormatter $msgFormatter
	): Tag {
		$row = $this->getParticipantRow( $participant, $canRemoveParticipants, $language, $viewingUser, $msgFormatter );
		$row->addClasses( [ 'ext-campaignevents-details-current-user-row' ] );
		return $row;
	}

	/**
	 * @param Participant $participant
	 * @param bool $canRemoveParticipants
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @param ITextFormatter $msgFormatter
	 * @return Tag
	 */
	private function getParticipantRow(
		Participant $participant,
		bool $canRemoveParticipants,
		Language $language,
		UserIdentity $viewingUser,
		ITextFormatter $msgFormatter
	): Tag {
		$row = new Tag( 'div' );
		$performer = $this->userFactory->newFromId( $viewingUser->getId() );
		try {
			$userName = $this->centralUserLookup->getUserName( $participant->getUser() );
			$genderUserName = $userName;
			$user = $this->userFactory->newFromName( $userName );
			$userLink = $this->userLinker->getUserPagePath( $participant->getUser() );
		} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
			$user = null;
			$userName = null;
			$genderUserName = '@';
		}
		$recipientIsValid = $user !== null && $this->userMailer->validateTarget( $user, $performer ) === null;

		if ( $canRemoveParticipants ) {
			$checkboxDivider = new Tag( 'div' );
			$checkboxDivider->addClasses( [ 'ext-campaignevents-details-user-row-checkbox' ] );
			$userId = $participant->getUser()->getCentralID();
			$checkboxDivider->appendContent( new CheckboxInputWidget( [
				'name' => 'event-details-participants-checkboxes',
				'infusable' => true,
				'value' => $userId,
				'classes' => [ 'ext-campaignevents-event-details-participants-checkboxes' ],
				'data' => [
					'hasEmail' => $recipientIsValid,
					'username' => $userName,
					'userId' => $userId,
					'userPageLink' => $userLink ?? ""
				]
			] ) );
			$row->appendContent( $checkboxDivider );
		}

		$usernameElement = new HtmlSnippet(
			$this->userLinker->generateUserLinkWithFallback( $participant->getUser(), $language->getCode() )
		);
		$usernameDivider = ( new Tag( 'div' ) )
			->appendContent( $usernameElement )
			->addClasses( [ 'ext-campaignevents-details-participant-username' ] );

		if ( $participant->isPrivateRegistration() ) {

			$labelText = $msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-private-participant-label', [ $genderUserName ] )
			);
			$privateIcon = new IconWidget( [
				'icon' => 'lock',
				'label' => $labelText,
				'title' => $labelText,
				'classes' => [ 'ext-campaignevents-event-details-participants-private-icon' ]
			] );
			$usernameDivider->appendContent( $privateIcon );
		}
		$row->appendContent( $usernameDivider );

		$registrationDateDivider = new Tag( 'div' );
		$registrationDateDivider->appendContent(
			$language->userTimeAndDate(
				$participant->getRegisteredAt(),
				$viewingUser
			)
		)->addClasses( [ 'ext-campaignevents-details-participant-registered-at' ] );
		$row->appendContent( $registrationDateDivider );

		$row->appendContent( ( new Tag( 'div' ) )->appendContent(
			$recipientIsValid
				? $msgFormatter->format( MessageValue::new( 'campaignevents-email-participants-yes' ) )
				: $msgFormatter->format( MessageValue::new( 'campaignevents-email-participants-no' ) )
		)->addClasses( [ 'ext-campaignevents-details-participant-has-email' ] ) );

		return $row
			->addClasses( [ 'ext-campaignevents-details-user-row' ] );
	}

	/**
	 * @param int $eventID
	 * @param ITextFormatter $msgFormatter
	 * @return Tag|null
	 */
	private function getFooter( int $eventID, ITextFormatter $msgFormatter ): ?Tag {
		$privateParticipantsCount = $this->participantsStore->getPrivateParticipantCountForEvent( $eventID );
		if ( $privateParticipantsCount === 0 ) {
			// Don't show anything, it would be redundant.
			return null;
		}

		$icon = new IconWidget( [ 'icon' => 'lock' ] );
		$text = $msgFormatter->format(
			MessageValue::new( 'campaignevents-event-details-participants-private' )
				->numParams( $privateParticipantsCount )
		);
		$textElement = ( new Tag( 'span' ) )
			->appendContent( $text );
		// TODO The number should be updated dynamically when (private) participants are removed, see T322275.
		return ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-event-details-participants-footer' ] )
			->appendContent( $icon, $textElement );
	}
}

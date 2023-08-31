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
use Sanitizer;
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

	/** @var UserLinker */
	private UserLinker $userLinker;
	/** @var ParticipantsStore */
	private ParticipantsStore $participantsStore;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;
	/** @var PermissionChecker */
	private PermissionChecker $permissionChecker;
	/** @var UserFactory */
	private UserFactory $userFactory;
	/** @var CampaignsUserMailer */
	private CampaignsUserMailer $userMailer;

	private ITextFormatter $msgFormatter;
	private Language $language;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param UserLinker $userLinker
	 * @param ParticipantsStore $participantsStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param PermissionChecker $permissionChecker
	 * @param UserFactory $userFactory
	 * @param CampaignsUserMailer $userMailer
	 * @param Language $language
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		UserLinker $userLinker,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup,
		PermissionChecker $permissionChecker,
		UserFactory $userFactory,
		CampaignsUserMailer $userMailer,
		Language $language
	) {
		$this->userLinker = $userLinker;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->permissionChecker = $permissionChecker;
		$this->userFactory = $userFactory;
		$this->userMailer = $userMailer;

		$this->language = $language;
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
	}

	/**
	 * @param ExistingEventRegistration $event
	 * @param UserIdentity $viewingUser
	 * @param ICampaignsAuthority $authority
	 * @param bool $isOrganizer
	 * @param bool $canEmailParticipants
	 * @param OutputPage $out
	 * @return Tag
	 *
	 * @note Ideally, this wouldn't use MW-specific classes for l10n, but it's hard-ish to avoid and
	 * probably not worth doing.
	 */
	public function createContent(
		ExistingEventRegistration $event,
		UserIdentity $viewingUser,
		ICampaignsAuthority $authority,
		bool $isOrganizer,
		bool $canEmailParticipants,
		OutputPage $out
	): Tag {
		$eventID = $event->getID();
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
		$items[] = $this->getPrimaryHeader(
			$totalParticipants,
			$canRemoveParticipants,
			$canEmailParticipants
		);
		if ( $totalParticipants ) {
			$items[] = $this->getParticipantsTable(
				$viewingUser,
				$canRemoveParticipants,
				$curUserParticipant,
				$otherParticipants
			);
		}
		// This is added even if there are participants, because they might be removed from this page.
		$items[] = $this->getEmptyStateElement( $totalParticipants );

		$out->addJsConfigVars( [
			// TODO This may change when we add the feature to send messages
			'wgCampaignEventsShowParticipantCheckboxes' => $canRemoveParticipants,
			'wgCampaignEventsShowPrivateParticipants' => $showPrivateParticipants,
			'wgCampaignEventsEventDetailsParticipantsTotal' => $totalParticipants,
			'wgCampaignEventsLastParticipantID' => $lastParticipantID,
			'wgCampaignEventsCurUserCentralID' => isset( $centralUser ) ? $centralUser->getCentralID() : null,
			'wgCampaignEventsViewerHasEmail' =>
				$this->userFactory->newFromUserIdentity( $viewingUser )->isEmailConfirmed()
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

		$footer = $this->getFooter( $eventID );
		if ( $footer ) {
			$content->appendContent( $footer );
		}

		return $content;
	}

	/**
	 * @param int $totalParticipants
	 * @param bool $canRemoveParticipants
	 * @param bool $canEmailParticipants
	 * @return Tag
	 */
	private function getPrimaryHeader(
		int $totalParticipants, $canRemoveParticipants, $canEmailParticipants
	): Tag {
		$headerText = ( new Tag( 'span' ) )->appendContent(
			$this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-header-participants' )
					->numParams( $totalParticipants )
			)
		)->addClasses( [ 'ext-campaignevents-details-participants-header-text' ] );
		$headerTitle = ( new Tag() )->appendContent(
			$headerText
		)->addClasses( [ 'ext-campaignevents-details-participants-header-title' ] );
		$header = ( new Tag() )->addClasses( [ 'ext-campaignevents-details-participants-header' ] );

		if ( $totalParticipants ) {
			$headerTitle->appendContent( $this->getSearchBar() );
			$header->appendContent(
				$headerTitle
			);
			$header->appendContent( $this->getHeaderControls( $canRemoveParticipants, $canEmailParticipants ) );
		} else {
			$header->appendContent(
				$headerTitle
			);
		}

		return $header;
	}

	/**
	 * @param UserIdentity $viewingUser
	 * @param bool $canRemoveParticipants
	 * @param Participant|null $curUserParticipant
	 * @param Participant[] $otherParticipants
	 * @return Tag
	 */
	private function getParticipantsTable(
		UserIdentity $viewingUser,
		bool $canRemoveParticipants,
		?Participant $curUserParticipant,
		array $otherParticipants
	): Tag {
		// Use an outer container for the infinite scrolling
		$container = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-details-participants-container' ] );
		$table = ( new Tag( 'table' ) )
			->addClasses( [ 'ext-campaignevents-details-participants-table' ] );
		$table->appendContent( $this->getTableHeaders( $canRemoveParticipants ) );
		$table->appendContent( $this->getParticipantRows(
			$curUserParticipant,
			$otherParticipants,
			$canRemoveParticipants,
			$viewingUser
		) );
		$container->appendContent( $table );
		return $container;
	}

	/**
	 * @param int $totalParticipants
	 * @return Tag
	 */
	private function getEmptyStateElement( int $totalParticipants ): Tag {
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
				$this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-no-participants-state' )
				)
			)->addClasses( [ 'ext-campaignevents-details-no-participants-description' ] )
		)->addClasses( $noParticipantsClasses );
	}

	/**
	 * @return Tag
	 */
	private function getSearchBar(): Tag {
			return new SearchInputWidget( [
				'placeholder' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-search-participants-placeholder' )
				),
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-details-participants-search' ]
			] );
	}

	/**
	 * @param bool $canRemoveParticipants
	 * @return Tag
	 */
	private function getTableHeaders( bool $canRemoveParticipants ): Tag {
		$container = ( new Tag( 'thead' ) )->addClasses( [ 'ext-campaignevents-details-participants-table-header' ] );
		$row = ( new Tag( 'tr' ) )
			->addClasses( [ 'ext-campaignevents-details-user-actions-row' ] );

		if ( $canRemoveParticipants ) {
			$selectAllCheckBoxField = new FieldLayout(
				new CheckboxInputWidget( [
					'name' => 'event-details-select-all-participants',
				] ),
				[
					'align' => 'inline',
					'classes' => [ 'ext-campaignevents-event-details-select-all-participant-checkbox-field' ],
					'infusable' => true,
					'label' => $this->msgFormatter->format(
						MessageValue::new( 'campaignevents-event-details-select-all' )
					),
					'invisibleLabel' => true
				]
			);

			$selectAllCell = ( new Tag( 'th' ) )
				->addClasses( [ 'ext-campaignevents-details-participants-selectall-checkbox-cell' ] )
				->appendContent( $selectAllCheckBoxField );
			$row->appendContent( $selectAllCell );
		}

		$headings = [
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-participants' ) ),
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-time-registered' ) ),
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-event-details-has-email' ) )
		];

		foreach ( $headings as $heading ) {
			$row->appendContent( ( new Tag( 'th' ) )->appendContent( $heading ) );
		}
		$container->appendContent( $row );
		return $container;
	}

	/**
	 * @param Participant|null $curUserParticipant
	 * @param Participant[] $otherParticipants
	 * @param bool $canRemoveParticipants
	 * @param UserIdentity $viewingUser
	 * @return Tag
	 */
	private function getParticipantRows(
		?Participant $curUserParticipant,
		array $otherParticipants,
		bool $canRemoveParticipants,
		UserIdentity $viewingUser
	): Tag {
		$body = new Tag( 'tbody' );
		if ( $curUserParticipant ) {
			$body->appendContent( $this->getCurUserParticipantRow(
				$curUserParticipant,
				$canRemoveParticipants,
				$viewingUser
			) );
		}

		foreach ( $otherParticipants as $participant ) {
			$body->appendContent( $this->getParticipantRow( $participant, $canRemoveParticipants, $viewingUser ) );
		}
		return $body;
	}

	/**
	 * @param Participant $participant
	 * @param bool $canRemoveParticipants
	 * @param UserIdentity $viewingUser
	 * @return Tag
	 */
	private function getCurUserParticipantRow(
		Participant $participant,
		bool $canRemoveParticipants,
		UserIdentity $viewingUser
	): Tag {
		$row = $this->getParticipantRow( $participant, $canRemoveParticipants, $viewingUser );
		$row->addClasses( [ 'ext-campaignevents-details-current-user-row' ] );
		return $row;
	}

	/**
	 * @param Participant $participant
	 * @param bool $canRemoveParticipants
	 * @param UserIdentity $viewingUser
	 * @return Tag
	 */
	private function getParticipantRow(
		Participant $participant,
		bool $canRemoveParticipants,
		UserIdentity $viewingUser
	): Tag {
		$row = new Tag( 'tr' );
		$performer = $this->userFactory->newFromId( $viewingUser->getId() );
		try {
			$userName = $this->centralUserLookup->getUserName( $participant->getUser() );
			$genderUserName = $userName;
			$user = $this->userFactory->newFromName( $userName );
			$userLinkComponents = $this->userLinker->getUserPagePath( $participant->getUser() );
		} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
			$user = null;
			$userName = null;
			$genderUserName = '@';
		}
		$recipientIsValid = $user !== null && $this->userMailer->validateTarget( $user, $performer ) === null;
		$userLink = $this->userLinker->generateUserLinkWithFallback(
			$participant->getUser(),
			$this->language->getCode()
		);

		if ( $canRemoveParticipants ) {
			$checkboxCell = new Tag( 'td' );
			$checkboxCell->addClasses( [ 'ext-campaignevents-details-user-row-checkbox' ] );
			$userId = $participant->getUser()->getCentralID();
			$checkbox = new CheckboxInputWidget( [
				'name' => 'event-details-participants-checkboxes',
				'infusable' => true,
				'value' => $userId,
				'classes' => [ 'ext-campaignevents-event-details-participants-checkboxes' ],
				'data' => [
					'hasEmail' => $recipientIsValid,
					'username' => $userName,
					'userId' => $userId,
					'userPageLink' => $userLinkComponents ?? ""
				],
			] );
			$checkboxField = new FieldLayout(
				$checkbox,
				[
					'label' => Sanitizer::stripAllTags( $userLink ),
					'invisibleLabel' => true,
				]
			);
			$checkboxCell->appendContent( $checkboxField );
			$row->appendContent( $checkboxCell );
		}

		$usernameElement = new HtmlSnippet( $userLink );
		$usernameCell = ( new Tag( 'td' ) )
			->appendContent( $usernameElement );

		if ( $participant->isPrivateRegistration() ) {

			$labelText = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-private-participant-label', [ $genderUserName ] )
			);
			$privateIcon = new IconWidget( [
				'icon' => 'lock',
				'label' => $labelText,
				'title' => $labelText,
				'classes' => [ 'ext-campaignevents-event-details-participants-private-icon' ]
			] );
			$usernameCell->appendContent( $privateIcon );
		}
		$row->appendContent( $usernameCell );

		$registrationDateCell = new Tag( 'td' );
		$registrationDateCell->appendContent(
			$this->language->userTimeAndDate(
				$participant->getRegisteredAt(),
				$viewingUser
			)
		);
		$row->appendContent( $registrationDateCell );

		$row->appendContent( ( new Tag( 'td' ) )->appendContent(
			$recipientIsValid
				? $this->msgFormatter->format( MessageValue::new( 'campaignevents-email-participants-yes' ) )
				: $this->msgFormatter->format( MessageValue::new( 'campaignevents-email-participants-no' ) )
		) );

		return $row
			->addClasses( [ 'ext-campaignevents-details-user-row' ] );
	}

	/**
	 * @param int $eventID
	 * @return Tag|null
	 */
	private function getFooter( int $eventID ): ?Tag {
		$privateParticipantsCount = $this->participantsStore->getPrivateParticipantCountForEvent( $eventID );
		if ( $privateParticipantsCount === 0 ) {
			// Don't show anything, it would be redundant.
			return null;
		}

		$icon = new IconWidget( [ 'icon' => 'lock' ] );
		$text = $this->msgFormatter->format(
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

	/**
	 * @param bool $viewerCanRemoveParticipants
	 * @param bool $viewerCanEmailParticipants
	 * @return Tag
	 */
	private function getHeaderControls(
		bool $viewerCanRemoveParticipants,
		bool $viewerCanEmailParticipants
	): Tag {
		$container = ( new Tag( 'div' ) )->addClasses( [ 'ext-campaignevents-details-participants-controls' ] );
		$removeButton = new ButtonWidget( [
			'icon' => 'close',
			'title' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-participants-deselect' )
			),
			'framed' => false,
			'flags' => [ 'progressive' ],
			'infusable' => true,
			'label' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-participants-checkboxes-selected', [ 0,0 ] )
			),
			'classes' => [ 'ext-campaignevents-details-participants-count-button' ]
		] );
		$container->appendContent( [ $removeButton ] );
		$buttonContainer = ( new Tag( 'div' ) )->addClasses( [ 'ext-campaignevents-details-participants-buttons' ] );
		if ( $viewerCanRemoveParticipants ) {
			$removeButton = new ButtonWidget( [
				'infusable' => true,
				'framed' => true,
				'flags' => [
					'destructive'
				],
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-remove-participant-remove-btn' )
				),
				'id' => 'ext-campaignevents-event-details-remove-participant-button',
				'classes' => [ 'ext-campaignevents-event-details-remove-participant-button' ],
			] );
			$buttonContainer->appendContent( $removeButton );
		}
		if ( $viewerCanEmailParticipants ) {
			$messageAllParticipantsButton = new ButtonWidget( [
				'infusable' => true,
				'framed' => true,
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-message-all' )
				),
				'flags' => [ 'progressive' ],
				'classes' => [ 'ext-campaignevents-event-details-message-all-participants-button' ],
			] );
			$buttonContainer->appendContent( $messageAllParticipantsButton );
		}
		$container->appendContent( $buttonContainer );
		return $container;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
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
		'oojs-ui.styles.icons-user'
	];

	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;
	/** @var UserLinker */
	private $userLinker;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param UserLinker $userLinker
	 * @param ParticipantsStore $participantsStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		UserLinker $userLinker,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->userLinker = $userLinker;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @param Language $language
	 * @param ExistingEventRegistration $event
	 * @param UserIdentity $viewingUser
	 * @param ICampaignsAuthority $authority
	 * @param bool $isOrganizer
	 * @param OutputPage $out
	 * @return PanelLayout
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
	): PanelLayout {
		$eventID = $event->getID();
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );

		$totalParticipants = $this->participantsStore->getFullParticipantCountForEvent( $eventID );

		$items = [];
		$items[] = $this->getHeader( $msgFormatter, $totalParticipants );

		$items[] = $this->getEmptyStateElement( $totalParticipants, $msgFormatter );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $authority );
			$curUserParticipant = $this->participantsStore->getEventParticipant( $eventID, $centralUser, true );
		} catch ( UserNotGlobalException $_ ) {
			$curUserParticipant = null;
		}

		if ( $curUserParticipant ) {
			$participants = array_merge(
				[ $curUserParticipant ],
				$this->participantsStore->getEventParticipants(
					$eventID,
					self::PARTICIPANTS_LIMIT - 1,
					null,
					null,
					false,
					// The isset is redundant, but the IDE's unhappy without it.
					isset( $centralUser ) ? $centralUser->getCentralID() : null
				)
			);
		} else {
			$participants = $this->participantsStore->getEventParticipants( $eventID, self::PARTICIPANTS_LIMIT );
		}

		if ( $participants ) {
			$items[] = $this->getSearchBar( $msgFormatter );
		}

		$canRemoveParticipants = false;
		if ( $isOrganizer ) {
			$canRemoveParticipants = UnregisterParticipantCommand::checkIsUnregistrationAllowed( $event ) ===
				UnregisterParticipantCommand::CAN_UNREGISTER;
		}

		if ( $canRemoveParticipants && $participants ) {
			$items[] = $this->getListControls( $msgFormatter );
		}

		$items[] = $this->getParticipantsContainer( $participants, $canRemoveParticipants, $language, $viewingUser );

		$out->addJsConfigVars( [
			// TODO This may change when we add the feature to send messages
			'wgCampaignEventsShowParticipantCheckboxes' => $canRemoveParticipants,
			'wgCampaignEventsEventDetailsParticipantsTotal' => $totalParticipants,
			'wgCampaignEventsLastParticipantID' => !$participants ? null : end( $participants )->getParticipantID(),
			'wgCampaignEventsCurUserCentralID' => isset( $centralUser ) ? $centralUser->getCentralID() : null,
		] );

		return new PanelLayout( [
			'content' => $items,
			'padded' => false,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-event-details-participants-panel' ],
		] );
	}

	/**
	 * @param ITextFormatter $msgFormatter
	 * @param int $totalParticipants
	 * @return Tag
	 */
	private function getHeader( ITextFormatter $msgFormatter, int $totalParticipants ): Tag {
		return ( new Tag() )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-header-participants' )
					->numParams( $totalParticipants )
			)
		)->addClasses( [ 'ext-campaignevents-details-participants-header' ] );
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
	 * @return Tag
	 */
	private function getSearchBar( ITextFormatter $msgFormatter ): Tag {
		return ( new Tag( 'div' ) )->appendContent(
			new SearchInputWidget( [
				'placeholder' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-search-participants-placeholder' )
				),
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-details-participants-search' ]
			] )
		)->addClasses( [ 'ext-campaignevents-details-participants-search-container' ] );
	}

	/**
	 * @param ITextFormatter $msgFormatter
	 * @return Tag
	 */
	private function getListControls( ITextFormatter $msgFormatter ): Tag {
		$selectAllCheckBoxField = new FieldLayout(
			new CheckboxInputWidget( [
				'name' => 'event-details-select-all-participants',
			] ),
			[
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-select-all' )
				),
				'align' => 'inline',
				'classes' => [ 'ext-campaignevents-event-details-select-all-participant-checkbox-field' ],
				'infusable' => true,
			]
		);

		$removeButton = new ButtonWidget( [
			'infusable' => true,
			'framed' => false,
			'flags' => [
				'destructive'
			],
			'icon' => 'trash',
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-remove-participant-remove-btn' )
			),
			'id' => 'ext-campaignevents-event-details-remove-participant-button',
			'classes' => [ 'ext-campaignevents-event-details-remove-participant-button' ],
		] );

		return ( new Tag( 'div' ) )->appendContent( $selectAllCheckBoxField, $removeButton )
			->addClasses( [ 'ext-campaignevents-details-user-actions-container' ] );
	}

	/**
	 * @param array $participants
	 * @param bool $canRemoveParticipants
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @return Tag
	 */
	private function getParticipantsContainer(
		array $participants,
		bool $canRemoveParticipants,
		Language $language,
		UserIdentity $viewingUser
	): Tag {
		$participantsContainer = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-details-users-container' ] );
		if ( !$participants ) {
			$participantsContainer->addClasses( [ 'ext-campaignevents-details-hide-element' ] );
		}

		$participantRows = $this->getParticipantRows( $participants, $canRemoveParticipants, $language, $viewingUser );

		$participantsContainer->appendContent( $participantRows );

		return $participantsContainer;
	}

	/**
	 * @param array $participants
	 * @param bool $canRemoveParticipants
	 * @param Language $language
	 * @param UserIdentity $viewingUser
	 * @return Tag
	 */
	private function getParticipantRows(
		array $participants,
		bool $canRemoveParticipants,
		Language $language,
		UserIdentity $viewingUser
	): Tag {
		$participantRows = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-details-users-rows-container' ] );
		foreach ( $participants as $participant ) {
			try {
				$userLink = new HtmlSnippet( $this->userLinker->generateUserLink( $participant->getUser() ) );
			} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
				continue;
			}
			$elements = [];
			if ( $canRemoveParticipants ) {
				$elements[] = ( new CheckboxInputWidget( [
					'name' => 'event-details-participants-checkboxes',
					'infusable' => true,
					'value' => $participant->getUser()->getCentralID(),
					'classes' => [ 'ext-campaignevents-event-details-participants-checkboxes' ],
				] ) );
			}
			$elements[] = ( new Tag( 'span' ) )
				->appendContent( $userLink )
				->addClasses( [ 'ext-campaignevents-details-participant-username' ] );

			$elements[] = ( new Tag( 'span' ) )->appendContent(
				$language->userTimeAndDate(
					$participant->getRegisteredAt(),
					$viewingUser
				)
			)->addClasses( [ 'ext-campaignevents-details-participant-registered-at' ] );

			$userRow = ( new Tag() )
				->appendContent( ...$elements )
				->addClasses( [ 'ext-campaignevents-details-user-row' ] );

			$participantRows->appendContent( $userRow );
		}
		return $participantRows;
	}
}

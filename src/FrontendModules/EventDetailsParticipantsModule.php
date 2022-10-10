<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
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

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param UserLinker $userLinker
	 * @param ParticipantsStore $participantsStore
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		UserLinker $userLinker,
		ParticipantsStore $participantsStore
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->userLinker = $userLinker;
		$this->participantsStore = $participantsStore;
	}

	/**
	 * @param Language $language
	 * @param ExistingEventRegistration $event
	 * @param UserIdentity $viewingUser
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
		bool $isOrganizer,
		OutputPage $out
	): PanelLayout {
		$eventID = $event->getID();
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );

		$totalParticipants = $this->participantsStore->getParticipantCountForEvent( $eventID );

		$items = [];
		$items[] = ( new Tag() )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-header-participants' )
					->numParams( $totalParticipants )
			)
		)->addClasses( [ 'ext-campaignevents-details-participants-header' ] );

		$noParticipantsIcon = new IconWidget( [
			'icon' => 'userGroup',
			'classes' => [ 'ext-campaignevents-event-details-no-participants-icon' ]
		] );

		$noParticipantsClasses = [ 'ext-campaignevents-details-no-participants-state' ];
		if ( $totalParticipants > 0 ) {
			$noParticipantsClasses[] = 'ext-campaignevents-details-hide-element';
		}
		$items[] = ( new Tag() )->appendContent(
			$noParticipantsIcon,
			( new Tag() )->appendContent(
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-no-participants-state' )
				)
			)->addClasses( [ 'ext-campaignevents-details-no-participants-description' ] )
		)->addClasses( $noParticipantsClasses );

		$participants = $this->participantsStore->getEventParticipants( $eventID, self::PARTICIPANTS_LIMIT );
		if ( $participants ) {
			$items[] = ( new Tag() )->appendContent(
				new SearchInputWidget( [
					'placeholder' => $msgFormatter->format(
						MessageValue::new( 'campaignevents-event-details-search-participants-placeholder' )
					),
					'infusable' => true,
					'classes' => [ 'ext-campaignevents-details-participants-search' ]
				] )
			)->addClasses( [ 'ext-campaignevents-details-participants-search-container' ] );
		}

		if ( $isOrganizer ) {
			$canRemoveParticipants = UnregisterParticipantCommand::checkIsUnregistrationAllowed( $event ) ===
				UnregisterParticipantCommand::CAN_UNREGISTER;
		} else {
			$canRemoveParticipants = false;
		}

		if ( $canRemoveParticipants && $participants ) {
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

			$items[] = ( new Tag( 'div' ) )->appendContent( $selectAllCheckBoxField, $removeButton )
				->addClasses( [ 'ext-campaignevents-details-user-actions-container' ] );
		}

		$usersDivContent = ( new Tag( 'div' ) )
				->addClasses( [ 'ext-campaignevents-details-users-container' ] );
		if ( !$participants ) {
			$usersDivContent->addClasses( [ 'ext-campaignevents-details-hide-element' ] );
		}

		$usersDivRows = ( new Tag( 'div' ) )
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

			$usersDivRows->appendContent( $userRow );
		}

		$usersDivContent->appendContent( $usersDivRows );

		$items[] = $usersDivContent;

		$out->addJsConfigVars( [
			// TODO This may change when we add the feature to send messages
			'wgCampaignEventsShowParticipantCheckboxes' => $canRemoveParticipants,
			'wgCampaignEventsEventDetailsParticipantsTotal' => $totalParticipants,
			'wgCampaignEventsLastParticipantID' => !$participants ? null : end( $participants )->getParticipantID(),
		] );

		return new PanelLayout( [
			'content' => $items,
			'padded' => false,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-event-details-participants-panel' ],
		] );
	}
}

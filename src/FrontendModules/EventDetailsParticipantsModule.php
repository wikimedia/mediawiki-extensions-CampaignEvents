<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Language\Language;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use OOUI\ButtonGroupWidget;
use OOUI\ButtonWidget;
use OOUI\CheckboxInputWidget;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\IconWidget;
use OOUI\MessageWidget;
use OOUI\PanelLayout;
use OOUI\SearchInputWidget;
use OOUI\Tag;
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

	private readonly ITextFormatter $msgFormatter;
	private bool $isPastEvent;

	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		private readonly UserLinker $userLinker,
		private readonly ParticipantsStore $participantsStore,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly PermissionChecker $permissionChecker,
		private readonly UserFactory $userFactory,
		private readonly CampaignsUserMailer $userMailer,
		private readonly EventQuestionsRegistry $eventQuestionsRegistry,
		private readonly Language $language,
		private readonly string $statisticsTabUrl,
	) {
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
		$this->isPastEvent = false;
	}

	/**
	 * @return Tag
	 *
	 * @note Ideally, this wouldn't use MW-specific classes for l10n, but it's hard-ish to avoid and
	 * probably not worth doing.
	 */
	public function createContent(
		ExistingEventRegistration $event,
		UserIdentity $viewingUser,
		Authority $authority,
		bool $isOrganizer,
		bool $canEmailParticipants,
		bool $isLocalWiki,
		OutputPage $out
	): Tag {
		$eventID = $event->getID();
		$this->isPastEvent = $event->isPast();
		$totalParticipants = $this->participantsStore->getFullParticipantCountForEvent( $eventID );

		$centralUser = null;
		$curUserParticipant = null;
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $authority );
			$curUserParticipant = $this->participantsStore->getEventParticipant( $eventID, $centralUser, true );
		} catch ( UserNotGlobalException ) {
		}

		$showPrivateParticipants = $isLocalWiki &&
			$this->permissionChecker->userCanViewPrivateParticipants( $authority, $event );
		$otherParticipantsNum = $curUserParticipant ? self::PARTICIPANTS_LIMIT - 1 : self::PARTICIPANTS_LIMIT;
		$otherParticipants = $this->participantsStore->getEventParticipants(
			$eventID,
			$otherParticipantsNum,
			null,
			null,
			null,
			$showPrivateParticipants,
			$centralUser ? [ $centralUser->getCentralID() ] : null
		);
		$lastParticipant = $otherParticipants ? end( $otherParticipants ) : $curUserParticipant;
		$lastParticipantID = $lastParticipant ? $lastParticipant->getParticipantID() : null;
		$canRemoveParticipants = false;
		if ( $isOrganizer && $isLocalWiki ) {
			$canRemoveParticipants = UnregisterParticipantCommand::checkIsUnregistrationAllowed( $event )->isGood();
		}

		$canViewNonPIIParticipantsData = false;
		if ( $isOrganizer && $isLocalWiki ) {
			$canViewNonPIIParticipantsData = $this->permissionChecker->userCanViewNonPIIParticipantsData(
				$authority, $event
			);
		}

		$nonPIIQuestionIDs = $this->eventQuestionsRegistry->getNonPIIQuestionIDs(
			$event->getParticipantQuestions()
		);

		$items = [];
		$items[] = $this->getPrimaryHeader(
			$event,
			$totalParticipants,
			$canRemoveParticipants,
			$canEmailParticipants,
			$canViewNonPIIParticipantsData
		);
		if ( $totalParticipants ) {
			$items[] = $this->getParticipantsTable(
				$out,
				$viewingUser,
				$canRemoveParticipants,
				$canEmailParticipants,
				$canViewNonPIIParticipantsData,
				$curUserParticipant,
				$otherParticipants,
				$nonPIIQuestionIDs
			);
		}
		// This is added even if there are participants, because they might be removed from this page.
		$items[] = $this->getEmptyStateElement( $totalParticipants );

		$out->addJsConfigVars( [
			'wgCampaignEventsShowParticipantCheckboxes' => $canRemoveParticipants || $canEmailParticipants,
			'wgCampaignEventsShowPrivateParticipants' => $showPrivateParticipants,
			'wgCampaignEventsEventDetailsParticipantsTotal' => $totalParticipants,
			'wgCampaignEventsLastParticipantID' => $lastParticipantID,
			'wgCampaignEventsCurUserCentralID' => $centralUser?->getCentralID(),
			'wgCampaignEventsViewerHasEmail' =>
				$this->userFactory->newFromUserIdentity( $viewingUser )->isEmailConfirmed(),
			'wgCampaignEventsNonPIIQuestionIDs' => $nonPIIQuestionIDs,
		] );

		$layout = new PanelLayout( [
			'content' => $items,
			'padded' => false,
			'framed' => true,
			'expanded' => false,
		] );

		$content = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventdetails-participants-panel' ] )
			->appendContent( $layout );

		$footer = $this->getFooter( $eventID, $canViewNonPIIParticipantsData, $event, $out );
		if ( $footer ) {
			$content->appendContent( $footer );
		}

		return $content;
	}

	private function getPrimaryHeader(
		ExistingEventRegistration $event,
		int $totalParticipants,
		bool $canRemoveParticipants,
		bool $canEmailParticipants,
		bool $canViewNonPIIParticipantsData
	): Tag {
		$participantCountText = $this->msgFormatter->format(
			MessageValue::new( 'campaignevents-event-details-header-participants' )
				->numParams( $totalParticipants )
		);
		$participantsCountElement = ( new Tag( 'span' ) )
			->appendContent( $participantCountText )
			->addClasses( [ 'ext-campaignevents-eventdetails-participants-header-participant-count' ] );
		$participantsElement = ( new Tag( 'div' ) )
			->appendContent( $participantsCountElement )
			->addClasses( [ 'ext-campaignevents-eventdetails-participants-header-participants' ] );
		if (
			$canViewNonPIIParticipantsData &&
			!$this->isPastEvent &&
			$event->getParticipantQuestions()
		) {
			$questionsHelp = new ButtonWidget( [
				'framed' => false,
				'icon' => 'info',
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-header-questions-help' )
				),
				'invisibleLabel' => true,
				'classes' => [ 'ext-campaignevents-eventdetails-participants-header-questions-help' ]
			] );
			$participantsElement->appendContent( $questionsHelp );
		}
		$headerTitle = ( new Tag( 'div' ) )
			->appendContent( $participantsElement )
			->addClasses( [ 'ext-campaignevents-eventdetails-participants-header-title' ] );
		$header = ( new Tag( 'div' ) )->addClasses( [ 'ext-campaignevents-eventdetails-participants-header' ] );

		if ( $totalParticipants ) {
			$headerTitle->appendContent( $this->getSearchBar() );
			$header->appendContent( $headerTitle );
			$header->appendContent( $this->getHeaderControls( $canRemoveParticipants, $canEmailParticipants ) );
		} else {
			$header->appendContent( $headerTitle );
		}

		return $header;
	}

	/**
	 * @param IContextSource $context
	 * @param UserIdentity $viewingUser
	 * @param bool $canRemoveParticipants
	 * @param bool $canEmailParticipants
	 * @param bool $canViewNonPIIParticipantsData
	 * @param Participant|null $curUserParticipant
	 * @param Participant[] $otherParticipants
	 * @param int[] $nonPIIQuestionIDs
	 */
	private function getParticipantsTable(
		IContextSource $context,
		UserIdentity $viewingUser,
		bool $canRemoveParticipants,
		bool $canEmailParticipants,
		bool $canViewNonPIIParticipantsData,
		?Participant $curUserParticipant,
		array $otherParticipants,
		array $nonPIIQuestionIDs
	): Tag {
		// Use an outer container for the infinite scrolling
		$container = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventdetails-participants-container' ] );
		$table = ( new Tag( 'table' ) )
			->addClasses( [ 'ext-campaignevents-eventdetails-participants-table' ] );

		$table->appendContent( $this->getTableHeaders(
				$canRemoveParticipants,
				$canEmailParticipants,
				$nonPIIQuestionIDs,
				$canViewNonPIIParticipantsData
			)
		);
		$table->appendContent( $this->getParticipantRows(
			$context,
			$curUserParticipant,
			$otherParticipants,
			$canRemoveParticipants,
			$canEmailParticipants,
			$viewingUser,
			$nonPIIQuestionIDs,
			$canViewNonPIIParticipantsData
		) );
		$container->appendContent( $table );
		return $container;
	}

	private function getEmptyStateElement( int $totalParticipants ): Tag {
		$noParticipantsIcon = new IconWidget( [
			'icon' => 'userGroup',
			'classes' => [ 'ext-campaignevents-eventdetails-no-participants-icon' ]
		] );

		$noParticipantsClasses = [ 'ext-campaignevents-eventdetails-no-participants-state' ];
		if ( $totalParticipants > 0 ) {
			$noParticipantsClasses[] = 'ext-campaignevents-eventdetails-hide-element';
		}
		return ( new Tag() )->appendContent(
			$noParticipantsIcon,
			( new Tag() )->appendContent(
				$this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-no-participants-state' )
				)
			)->addClasses( [ 'ext-campaignevents-eventdetails-no-participants-description' ] )
		)->addClasses( $noParticipantsClasses );
	}

	private function getSearchBar(): Tag {
			return new SearchInputWidget( [
				'placeholder' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-search-participants-placeholder' )
				),
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-eventdetails-participants-search' ]
			] );
	}

	/**
	 * @param bool $canRemoveParticipants
	 * @param bool $canEmailParticipants
	 * @param list<int> $nonPIIQuestionIDs
	 * @param bool $userCanViewNonPIIParticipantsData
	 */
	private function getTableHeaders(
		bool $canRemoveParticipants,
		bool $canEmailParticipants,
		array $nonPIIQuestionIDs,
		bool $userCanViewNonPIIParticipantsData
	): Tag {
		$container = ( new Tag( 'thead' ) )
			->addClasses( [ 'ext-campaignevents-eventdetails-participants-table-header' ] );
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
				->addClasses( [ 'ext-campaignevents-eventdetails-participants-selectall-checkbox-cell' ] )
				->appendContent( $selectAllCheckBoxField );
			$row->appendContent( $selectAllCell );
		}

		$headings = [
			[
				'message' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-participants' )
				),
				'cssClasses' => [ 'ext-campaignevents-eventdetails-participants-username-cell' ],
			],
			[
				'message' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-time-registered' )
				),
				'cssClasses' => [ 'ext-campaignevents-eventdetails-participants-time-registered-cell' ],
			],
		];
		if ( $canEmailParticipants ) {
			$headings[] = [
				'message' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-can-receive-email' )
				),
				'cssClasses' => [ 'ext-campaignevents-eventdetails-participants-can-receive-email-cell' ],
			];
		}

		if ( !$this->isPastEvent && $userCanViewNonPIIParticipantsData ) {
			$nonPIIQuestionLabels = $this->eventQuestionsRegistry->getNonPIIQuestionLabels(
				$nonPIIQuestionIDs
			);
			if ( $nonPIIQuestionLabels ) {
				foreach ( $nonPIIQuestionLabels as $nonPIIQuestionLabel ) {
					$headings[] = [
						'message' => $this->msgFormatter->format(
							MessageValue::new( $nonPIIQuestionLabel )
						),
						'cssClasses' => [ 'ext-campaignevents-eventdetails-participants-non-pii-question-cells' ],
					];
				}
			}
		}

		foreach ( $headings as $heading ) {
			$row->appendContent(
				( new Tag( 'th' ) )->appendContent( $heading[ 'message' ] )->addClasses( $heading[ 'cssClasses' ] )
			);
		}
		$container->appendContent( $row );

		return $container;
	}

	/**
	 * @param IContextSource $context
	 * @param Participant|null $curUserParticipant
	 * @param Participant[] $otherParticipants
	 * @param bool $canRemoveParticipants
	 * @param bool $canEmailParticipants
	 * @param UserIdentity $viewingUser
	 * @param list<int> $nonPIIQuestionIDs
	 * @param bool $userCanViewNonPIIParticipantsData
	 */
	private function getParticipantRows(
		IContextSource $context,
		?Participant $curUserParticipant,
		array $otherParticipants,
		bool $canRemoveParticipants,
		bool $canEmailParticipants,
		UserIdentity $viewingUser,
		array $nonPIIQuestionIDs,
		bool $userCanViewNonPIIParticipantsData
	): Tag {
		$body = new Tag( 'tbody' );
		if ( $curUserParticipant ) {
			$body->appendContent( $this->getCurUserParticipantRow(
				$context,
				$curUserParticipant,
				$canRemoveParticipants,
				$canEmailParticipants,
				$viewingUser,
				$nonPIIQuestionIDs,
				$userCanViewNonPIIParticipantsData
			) );
		}

		foreach ( $otherParticipants as $participant ) {
			$body->appendContent(
				$this->getParticipantRow(
					$context,
					$participant,
					$canRemoveParticipants,
					$canEmailParticipants,
					$viewingUser,
					$nonPIIQuestionIDs,
					$userCanViewNonPIIParticipantsData
				)
			);
		}
		return $body;
	}

	/**
	 * @param IContextSource $context
	 * @param Participant $participant
	 * @param bool $canRemoveParticipants
	 * @param bool $canEmailParticipants
	 * @param UserIdentity $viewingUser
	 * @param list<int> $nonPIIQuestionIDs
	 * @param bool $userCanViewNonPIIParticipantsData
	 */
	private function getCurUserParticipantRow(
		IContextSource $context,
		Participant $participant,
		bool $canRemoveParticipants,
		bool $canEmailParticipants,
		UserIdentity $viewingUser,
		array $nonPIIQuestionIDs,
		bool $userCanViewNonPIIParticipantsData
	): Tag {
		$row = $this->getParticipantRow(
			$context,
			$participant,
			$canRemoveParticipants,
			$canEmailParticipants,
			$viewingUser,
			$nonPIIQuestionIDs,
			$userCanViewNonPIIParticipantsData
		);
		$row->addClasses( [ 'ext-campaignevents-details-current-user-row' ] );
		return $row;
	}

	/**
	 * @param IContextSource $context
	 * @param Participant $participant
	 * @param bool $canRemoveParticipants
	 * @param bool $canEmailParticipants
	 * @param UserIdentity $viewingUser
	 * @param list<int> $nonPIIQuestionIDs
	 * @param bool $userCanViewNonPIIParticipantsData
	 */
	private function getParticipantRow(
		IContextSource $context,
		Participant $participant,
		bool $canRemoveParticipants,
		bool $canEmailParticipants,
		UserIdentity $viewingUser,
		array $nonPIIQuestionIDs,
		bool $userCanViewNonPIIParticipantsData
	): Tag {
		$row = new Tag( 'tr' );
		$performer = $this->userFactory->newFromId( $viewingUser->getId() );
		try {
			$userName = $this->centralUserLookup->getUserName( $participant->getUser() );
			$genderUserName = $userName;
			$user = $this->userFactory->newFromName( $userName );
			$userLinkComponents = $this->userLinker->getUserPagePath( $participant->getUser() );
		} catch ( CentralUserNotFoundException | HiddenCentralUserException ) {
			$user = null;
			$userName = null;
			$genderUserName = '@';
		}
		$recipientIsValid = $user !== null && $canEmailParticipants &&
			$this->userMailer->validateTarget( $user, $performer ) === null;
		$userLink = $this->userLinker->generateUserLinkWithFallback(
			$context,
			$participant->getUser(),
			$this->language->getCode()
		);

		if ( $canRemoveParticipants ) {
			$checkboxCell = new Tag( 'td' );
			$checkboxCell->addClasses( [ 'ext-campaignevents-eventdetails-user-row-checkbox' ] );
			$userId = $participant->getUser()->getCentralID();
			$checkbox = new CheckboxInputWidget( [
				'name' => 'event-details-participants-checkboxes',
				'infusable' => true,
				'value' => $userId,
				'classes' => [ 'ext-campaignevents-event-details-participants-checkboxes' ],
				'data' => [
					'canReceiveEmail' => $recipientIsValid,
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
				'classes' => [ 'ext-campaignevents-eventdetails-participants-private-icon' ]
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

		if ( $canEmailParticipants ) {
			$row->appendContent( ( new Tag( 'td' ) )->appendContent(
				$recipientIsValid
					? $this->msgFormatter->format( MessageValue::new( 'campaignevents-email-participants-yes' ) )
					: $this->msgFormatter->format( MessageValue::new( 'campaignevents-email-participants-no' ) )
			) );
		}

		if ( !$this->isPastEvent && $userCanViewNonPIIParticipantsData ) {
			$row = $this->addNonPIIParticipantAnswers( $row, $participant, $nonPIIQuestionIDs, $genderUserName );
		}
		return $row
			->addClasses( [ 'ext-campaignevents-details-user-row' ] );
	}

	/**
	 * @param Tag $row
	 * @param Participant $participant
	 * @param list<int> $nonPIIQuestionIDs
	 * @param string $genderUserName
	 */
	private function addNonPIIParticipantAnswers(
		Tag $row,
		Participant $participant,
		array $nonPIIQuestionIDs,
		string $genderUserName
	): Tag {
		if ( !$nonPIIQuestionIDs ) {
			return $row;
		}

		if ( $participant->getAggregationTimestamp() ) {
			$aggregatedMessage = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-participant-question-have-been-aggregated', [ $genderUserName ] )
			);
			$td = ( new Tag( 'td' ) )->setAttributes( [ 'colspan' => count( $nonPIIQuestionIDs ) ] )
				->appendContent( $aggregatedMessage )
				->addClasses( [ 'ext-campaignevents-eventdetails-participants-responses-aggregated-notice' ] );
			$row->appendContent( $td );
			return $row;
		} else {
			$answeredQuestions = [];
			foreach ( $participant->getAnswers() as $answer ) {
				$answeredQuestions[ $answer->getQuestionDBID() ] = $answer;
			}

			$noResponseMessage = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-participant-question-no-response' )
			);
			foreach ( $nonPIIQuestionIDs as $nonPIIQuestionID ) {
				if ( array_key_exists( $nonPIIQuestionID, $answeredQuestions ) ) {
					$nonPIIAnswer = $this->getQuestionAnswer( $answeredQuestions[ $nonPIIQuestionID ] );
					$row->appendContent( ( new Tag( 'td' ) )->appendContent( $nonPIIAnswer ) );
				} else {
					$row->appendContent( ( new Tag( 'td' ) )->appendContent( $noResponseMessage ) );
				}
			}
		}
		return $row;
	}

	private function getQuestionAnswer( Answer $answer ): string {
		$option = $answer->getOption();
		if ( $option === null ) {
			return $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-participant-question-no-response' )
			);
		}
		$optionMessageKey = $this->eventQuestionsRegistry->getQuestionOptionMessageByID(
			$answer->getQuestionDBID(),
			$option
		);
		$participantAnswer = $this->msgFormatter->format( MessageValue::new( $optionMessageKey ) );
		if ( $answer->getText() ) {
			$participantAnswer .= $this->msgFormatter->format(
				MessageValue::new( 'colon-separator' )
			) . $answer->getText();
		}
		return $participantAnswer;
	}

	private function getFooter(
		int $eventID,
		bool $userCanViewNonPIIParticipantsData,
		ExistingEventRegistration $event,
		OutputPage $out
	): ?Tag {
		$privateParticipantsCount = $this->participantsStore->getPrivateParticipantCountForEvent( $eventID );

		$footer = ( new Tag( 'div' ) )->addClasses( [ 'ext-campaignevents-eventdetails-participants-footer' ] );
		if ( $privateParticipantsCount > 0 ) {
			$icon = new IconWidget( [ 'icon' => 'lock' ] );
			$text = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-participants-private' )
					->numParams( $privateParticipantsCount )
			);
			$textElement = ( new Tag( 'span' ) )
				->addClasses( [ 'ext-campaignevents-eventdetails-participants-private-count-msg' ] )
				->setAttributes( [ 'data-mw-count' => $privateParticipantsCount ] )
				->appendContent( $text );
			$privateParticipants = ( new Tag( 'div' ) )
				->addClasses( [ 'ext-campaignevents-eventdetails-participants-private-count-footer' ] )
				->appendContent( $icon, $textElement );
			$footer->appendContent( $privateParticipants );
		}

		if (
			$event->getParticipantQuestions() &&
			$this->isPastEvent &&
			$userCanViewNonPIIParticipantsData
		) {
			$deletedNonPiiInfoNoticeElement = new MessageWidget( [
				'type' => 'notice',
				'label' => new HtmlSnippet(
					$out->msg( 'campaignevents-event-details-participants-individual-data-deleted' )
						->params( $this->statisticsTabUrl )->parse()
				),
				'inline' => true
			] );
			$deletedNonPiiInfoNoticeElement->addClasses(
				[ 'ext-campaignevents-eventdetails-participants-individual-data-deleted-notice' ]
			);

			$footer->appendContent( $deletedNonPiiInfoNoticeElement );
		}
		return $footer;
	}

	private function getHeaderControls(
		bool $viewerCanRemoveParticipants,
		bool $viewerCanEmailParticipants
	): Tag {
		$container = ( new Tag( 'div' ) )->addClasses( [ 'ext-campaignevents-eventdetails-participants-controls' ] );
		$deselectButton = new ButtonWidget( [
			'icon' => 'close',
			'title' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-participants-deselect' )
			),
			'framed' => false,
			'flags' => [ 'progressive' ],
			'infusable' => true,
			'label' => $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-participants-checkboxes-selected', [ 0, 0 ] )
			),
			'classes' => [ 'ext-campaignevents-eventdetails-participants-count-button' ]
		] );
		$container->appendContent( [ $deselectButton ] );

		$extraButtons = [];
		if ( $viewerCanRemoveParticipants ) {
			$extraButtons[] = new ButtonWidget( [
				'infusable' => true,
				'framed' => true,
				'flags' => [
					'destructive'
				],
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-remove-participant-remove-btn' )
				),
				'id' => 'ext-campaignevents-event-details-remove-participant-button',
				'classes' => [
					'ext-campaignevents-event-details-remove-participant-button',
					'ext-campaignevents-eventdetails-hide-element'
				],
			] );
		}
		if ( $viewerCanEmailParticipants ) {
			$extraButtons[] = new ButtonWidget( [
				'infusable' => true,
				'framed' => true,
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-message-all' )
				),
				'flags' => [ 'progressive' ],
				'classes' => [ 'ext-campaignevents-eventdetails-message-all-participants-button' ],
			] );
		}

		if ( $extraButtons ) {
			$container->appendContent( new ButtonGroupWidget( [
				'items' => $extraButtons,
				'classes' => [ 'ext-campaignevents-eventdetails-extra-buttons' ],
			] ) );
		}
		return $container;
	}
}

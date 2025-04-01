<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserArray;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ListParticipantsHandler extends SimpleHandler {
	use EventIDParamTrait;

	// TODO: Implement proper pagination (T305389)
	private const RES_LIMIT = 20;

	private PermissionChecker $permissionChecker;
	private IEventLookup $eventLookup;
	private ParticipantsStore $participantsStore;
	private CampaignsCentralUserLookup $centralUserLookup;
	private UserLinker $userLinker;
	private UserFactory $userFactory;
	private CampaignsUserMailer $campaignsUserMailer;
	private EventQuestionsRegistry $questionsRegistry;
	private IMessageFormatterFactory $messageFormatterFactory;

	public function __construct(
		PermissionChecker $permissionChecker,
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker,
		UserFactory $userFactory,
		CampaignsUserMailer $campaignsUserMailer,
		EventQuestionsRegistry $questionsRegistry,
		IMessageFormatterFactory $messageFormatterFactory
	) {
		$this->permissionChecker = $permissionChecker;
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
		$this->userFactory = $userFactory;
		$this->campaignsUserMailer = $campaignsUserMailer;
		$this->questionsRegistry = $questionsRegistry;
		$this->messageFormatterFactory = $messageFormatterFactory;
	}

	protected function run( int $eventID ): Response {
		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$params = $this->getValidatedParams();
		$usernameFilter = $params['username_filter'];
		if ( $usernameFilter === '' ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-list-participants-empty-filter' ),
				400
			);
		}

		$includePrivate = $params['include_private'];
		$authority = new MWAuthorityProxy( $this->getAuthority() );
		if (
			$includePrivate &&
			!$this->permissionChecker->userCanViewPrivateParticipants( $authority, $event )
		) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-list-participants-cannot-see-private' ),
				403
			);
		}

		$participants = $this->participantsStore->getEventParticipants(
			$eventID,
			self::RES_LIMIT,
			$params['last_participant_id'],
			$usernameFilter,
			null,
			$includePrivate,
			$params['exclude_users']
		);

		$responseData = $this->getResponseData( $authority, $event, $participants );
		return $this->getResponseFactory()->createJson( $responseData );
	}

	/**
	 * @param MWAuthorityProxy $authority
	 * @param ExistingEventRegistration $event
	 * @param Participant[] $participants
	 * @return array
	 */
	private function getResponseData(
		MWAuthorityProxy $authority,
		ExistingEventRegistration $event,
		array $participants
	): array {
		// TODO: remove global when T269492 is resolved
		$language = RequestContext::getMain()->getLanguage();
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );
		$performer = $this->userFactory->newFromAuthority( $this->getAuthority() );
		$canEmailParticipants = $this->permissionChecker->userCanEmailParticipants( $authority, $event );
		$userCanViewNonPIIParticipantData = $this->permissionChecker->userCanViewNonPIIParticipantsData(
			$authority,
			$event
		);
		$includeNonPIIData = !$event->isPast() && $userCanViewNonPIIParticipantData;

		$centralIDs = array_map( static fn ( Participant $p ) => $p->getUser()->getCentralID(), $participants );
		[ $usernamesMap, $usersByName ] = $this->getUserBatch( $centralIDs );

		$respDataByCentralID = [];
		foreach ( $participants as $participant ) {
			$centralUser = $participant->getUser();
			$centralID = $centralUser->getCentralID();
			$respDataByCentralID[$centralID] = [
				'participant_id' => $participant->getParticipantID(),
				'user_id' => $centralID,
				'user_registered_at' => wfTimestamp( TS_MW, $participant->getRegisteredAt() ),
				'user_registered_at_formatted' => $language->userTimeAndDate(
					$participant->getRegisteredAt(),
					$this->getAuthority()->getUser()
				),
				'private' => $participant->isPrivateRegistration(),
			];

			$usernameOrError = $usernamesMap[$centralID];
			// Use an invalid username to force unspecified gender when the real username can't be determined.
			$genderUsername = '@';
			if ( $usernameOrError === CampaignsCentralUserLookup::USER_HIDDEN ) {
				$respDataByCentralID[$centralID]['hidden'] = true;
			} elseif ( $usernameOrError === CampaignsCentralUserLookup::USER_NOT_FOUND ) {
				$respDataByCentralID[$centralID]['not_found'] = true;
			} else {
				$genderUsername = $usernameOrError;
				$user = $usersByName[$usernameOrError] ?? null;
				$respDataByCentralID[$centralID] += [
					'user_name' => $usernameOrError,
					'user_page' => $this->userLinker->getUserPagePath( new CentralUser( $centralID ) ),
				];
				if ( $canEmailParticipants ) {
					$respDataByCentralID[$centralID]['user_is_valid_recipient'] =
						$user !== null && $this->campaignsUserMailer->validateTarget( $user, $performer ) === null;
				}
			}

			if ( $includeNonPIIData ) {
				if ( $participant->getAggregationTimestamp() ) {
					$respDataByCentralID[$centralID]['non_pii_answers'] = $msgFormatter->format(
						MessageValue::new( 'campaignevents-participant-question-have-been-aggregated' )
							->params( $genderUsername )
					);
				} else {
					$respDataByCentralID[$centralID]['non_pii_answers'] = $this->getParticipantNonPIIAnswers(
						$participant, $event, $msgFormatter
					);
				}
			}
		}

		return array_values( $respDataByCentralID );
	}

	/**
	 * Loads usernames and User objects for a list of given central user IDs. This must use a single DB query for
	 * performance. It also preloads the data needed for user page links.
	 *
	 * @param int[] $centralIDs
	 * @return array
	 * @phan-return array{0:array<int,string>,1:array<string,\MediaWiki\User\User>}
	 */
	private function getUserBatch( array $centralIDs ): array {
		$centralIDsMap = array_fill_keys( $centralIDs, null );
		$usernamesMap = $this->centralUserLookup->getNamesIncludingDeletedAndSuppressed( $centralIDsMap );
		$usernamesToPreload = array_filter(
			$usernamesMap,
			static function ( $name ) {
				return $name !== CampaignsCentralUserLookup::USER_HIDDEN &&
					$name !== CampaignsCentralUserLookup::USER_NOT_FOUND;
			}
		);
		$this->userLinker->preloadUserLinks( $usernamesToPreload );
		$usersByName = [];
		// XXX We have to use MW-specific classes (including the god object User) because email-related
		// code still lives mostly inside User.
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			$userArray = UserArray::newFromNames( $usernamesToPreload );
			foreach ( $userArray as $user ) {
				$usersByName[$user->getName()] = $user;
			}
		} else {
			// UserArray is highly untestable, fall back to the slow version
			foreach ( $usernamesToPreload as $name ) {
				$usersByName[$name] = $this->userFactory->newFromName( $name );
			}
		}

		return [ $usernamesMap, $usersByName ];
	}

	/**
	 * @param Participant $participant
	 * @param ExistingEventRegistration $event
	 * @param ITextFormatter $msgFormatter
	 * @return array
	 */
	private function getParticipantNonPIIAnswers(
		Participant $participant,
		ExistingEventRegistration $event,
		ITextFormatter $msgFormatter
	): array {
		$answeredQuestions = [];
		foreach ( $participant->getAnswers() as $answer ) {
			$answeredQuestions[ $answer->getQuestionDBID() ] = $answer;
		}

		$nonPIIQuestionIDs = $this->questionsRegistry->getNonPIIQuestionIDs(
			$event->getParticipantQuestions()
		);
		$answers = [];
		foreach ( $nonPIIQuestionIDs as $nonPIIQuestionID ) {
			if ( array_key_exists( $nonPIIQuestionID, $answeredQuestions ) ) {
				$answers[] = $this->getQuestionAnswer( $answeredQuestions[ $nonPIIQuestionID ], $msgFormatter );
			} else {
				$answers[] = [
					'message' => $msgFormatter->format(
							MessageValue::new( 'campaignevents-participant-question-no-response' )
						),
					'questionID' => $nonPIIQuestionID
				];
			}
		}
		return $answers;
	}

	/**
	 * @param Answer $answer
	 * @param ITextFormatter $msgFormatter
	 * @return array
	 */
	private function getQuestionAnswer( Answer $answer, ITextFormatter $msgFormatter ): array {
		$questionAnswer = [
			'message' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-participant-question-no-response' )
				),
			'questionID' => $answer->getQuestionDBID()
		];
		$option = $answer->getOption();
		if ( $option === null ) {
			return $questionAnswer;
		}
		$questionAnswerMessageKey = $this->questionsRegistry->getQuestionOptionMessageByID(
				$answer->getQuestionDBID(),
				$option
			);
		$questionAnswer[ 'message' ] = $msgFormatter->format(
			MessageValue::new( $questionAnswerMessageKey )
		);

		$textOption = $answer->getText();
		if ( $textOption ) {
			$questionAnswer[ 'message' ] .= $msgFormatter->format(
				MessageValue::new( 'colon-separator' )
			) . $textOption;
		}
		return $questionAnswer;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return array_merge(
			$this->getIDParamSetting(),
			[
				'include_private' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_REQUIRED => true,
					ParamValidator::PARAM_TYPE => 'boolean'
				],
				'last_participant_id' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'integer'
				],
				'username_filter' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'string'
				],
				'exclude_users' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'integer',
					ParamValidator::PARAM_ISMULTI => true
				],
			]
		);
	}
}

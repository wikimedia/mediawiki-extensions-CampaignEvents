<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\InvalidAnswerDataException;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class RegisterForEventHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	private IEventLookup $eventLookup;
	private RegisterParticipantCommand $registerParticipantCommand;
	private EventQuestionsRegistry $eventQuestionsRegistry;
	private ParticipantsStore $participantsStore;
	private CampaignsCentralUserLookup $centralUserLookup;

	public function __construct(
		IEventLookup $eventLookup,
		RegisterParticipantCommand $registerParticipantCommand,
		EventQuestionsRegistry $eventQuestionsRegistry,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->eventLookup = $eventLookup;
		$this->registerParticipantCommand = $registerParticipantCommand;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	protected function run( int $eventID ): Response {
		$body = $this->getValidatedBody() ?? [];
		$performer = $this->getAuthority();
		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
			$curParticipantData = $this->participantsStore->getEventParticipant( $eventID, $centralUser );
			$curAnswers = $curParticipantData ? $curParticipantData->getAnswers() : [];
		} catch ( UserNotGlobalException ) {
			// Silently ignore it for now, it's going to be thrown when attempting to register
			$curAnswers = [];
		}
		$allowedQuestions = EventQuestionsRegistry::getParticipantQuestionsToShow(
			$eventRegistration->getParticipantQuestions(),
			$curAnswers
		);

		$privateFlag = $body['is_private'] ?
			RegisterParticipantCommand::REGISTRATION_PRIVATE :
			RegisterParticipantCommand::REGISTRATION_PUBLIC;

		$wikiID = $eventRegistration->getPage()->getWikiId();
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-register-for-event-nonlocal-error-message' )
					->params( $wikiID ),
				400
			);
		}
		try {
			$answers = $this->eventQuestionsRegistry->extractUserAnswersAPI(
				$body['answers'] ?? [],
				$allowedQuestions
			);
		} catch ( InvalidAnswerDataException $e ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-register-invalid-answer' )
					->params( $e->getQuestionName() ),
				400
			);
		}
		$status = $this->registerParticipantCommand->registerIfAllowed(
			$eventRegistration,
			$performer,
			$privateFlag,
			$answers
		);
		if ( !$status->isGood() ) {
			$httpStatus = $status instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $status, $httpStatus );
		}
		return $this->getResponseFactory()->createJson( [
			'modified' => $status->getValue()
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function getBodyParamSettings(): array {
		return [
			'is_private' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'answers' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
			],
		] + $this->getTokenParamDefinition();
	}
}

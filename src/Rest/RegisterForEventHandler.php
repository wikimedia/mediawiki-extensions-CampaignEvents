<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use LogicException;
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

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly RegisterParticipantCommand $registerParticipantCommand,
		private readonly EventQuestionsRegistry $eventQuestionsRegistry,
		private readonly ParticipantsStore $participantsStore,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	protected function run( int $eventID ): Response {
		$body = $this->getValidatedBody() ?? throw new LogicException( 'T357909 - Body should be non-null' );
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
		// @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset https://github.com/phan/phan/issues/5444
		$contributionAssociationMode = $body['show_contribution_association_prompt']
			? RegisterParticipantCommand::SHOW_CONTRIBUTION_ASSOCIATION_PROMPT
			: RegisterParticipantCommand::HIDE_CONTRIBUTION_ASSOCIATION_PROMPT;
		$status = $this->registerParticipantCommand->registerIfAllowed(
			$eventRegistration,
			$performer,
			$privateFlag,
			$answers,
			$contributionAssociationMode,
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
			// Note: unlike Special:RegisterForEvent, this lets users change the value after an event has ended, and
			// for events that do not track contributions. This should be harmless.
			'show_contribution_association_prompt' => [
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

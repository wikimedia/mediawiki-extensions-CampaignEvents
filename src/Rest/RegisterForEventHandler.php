<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use Config;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\InvalidAnswerDataException;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class RegisterForEventHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var RegisterParticipantCommand */
	private $registerParticipantCommand;
	/** @var EventQuestionsRegistry */
	private EventQuestionsRegistry $eventQuestionsRegistry;
	/** @var bool */
	private bool $participantQuestionsEnabled;

	/**
	 * @param IEventLookup $eventLookup
	 * @param RegisterParticipantCommand $registerParticipantCommand
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 * @param Config $config
	 */
	public function __construct(
		IEventLookup $eventLookup,
		RegisterParticipantCommand $registerParticipantCommand,
		EventQuestionsRegistry $eventQuestionsRegistry,
		Config $config
	) {
		$this->eventLookup = $eventLookup;
		$this->registerParticipantCommand = $registerParticipantCommand;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->participantQuestionsEnabled = $config->get( 'CampaignEventsEnableParticipantQuestions' );
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$body = $this->getValidatedBody();
		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$privateFlag = $body['is_private'] ?
			RegisterParticipantCommand::REGISTRATION_PRIVATE :
			RegisterParticipantCommand::REGISTRATION_PUBLIC;
		if ( $this->participantQuestionsEnabled ) {
			try {
				$answers = $this->eventQuestionsRegistry->extractUserQuestionsAPI(
					$body['answers'] ?? [],
					$eventRegistration->getParticipantQuestions()
				);
			} catch ( InvalidAnswerDataException $e ) {
				throw new LocalizedHttpException(
					MessageValue::new( 'campaignevents-register-invalid-answer' )
						->params( $e->getQuestionName() ),
					400
				);
			}
		} else {
			$answers = [];
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
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ) {
		if ( $contentType !== 'application/json' ) {
			return new UnsupportedContentTypeBodyValidator( $contentType );
		}

		return new JsonBodyValidator(
			array_merge(
				$this->getTokenParamDefinition(),
				$this->getBodyParams()
			)
		);
	}

	/**
	 * @return array
	 */
	protected function getBodyParams(): array {
		$params = [
			'is_private' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
		if ( $this->participantQuestionsEnabled ) {
			$params['answers'] = [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
			];
		}
		return $params;
	}
}

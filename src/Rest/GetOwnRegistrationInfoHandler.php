<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use Config;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;

class GetOwnRegistrationInfoHandler extends SimpleHandler {
	use EventIDParamTrait;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;

	/** @var bool */
	private bool $participantQuestionsEnabled;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param Config $config
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup,
		Config $config
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->participantQuestionsEnabled = $config->get( 'CampaignEventsEnableParticipantQuestions' );
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->getAuthority() ) );
		} catch ( UserNotGlobalException $_ ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-register-need-central-account' ),
				400
			);
		}

		$participant = $this->participantsStore->getEventParticipant( $eventID, $centralUser, true );
		if ( !$participant ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-get-registration-info-notparticipant' ),
				404
			);
		}

		$response = [
			'private' => $participant->isPrivateRegistration(),
		];
		if ( $this->participantQuestionsEnabled ) {
			$respAnswers = [];
			foreach ( $participant->getAnswers() as $answer ) {
				$respAnswers[$answer->getQuestionDBID()] = [
					'option' => $answer->getOption(),
					'text' => $answer->getText(),
				];
			}
			$response['answers'] = $respAnswers;
		}

		return $this->getResponseFactory()->createJson( $response );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;

class GetOwnRegistrationInfoHandler extends SimpleHandler {
	use EventIDParamTrait;

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly ParticipantsStore $participantsStore,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly EventQuestionsRegistry $eventQuestionsRegistry,
	) {
	}

	protected function run( int $eventID ): Response {
		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->getAuthority() );
		} catch ( UserNotGlobalException ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-register-not-allowed' ),
				403
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
			'show_contribution_association_prompt' => $participant->shouldShowContributionAssociationPrompt(),
			'answers' => $this->eventQuestionsRegistry->formatAnswersForAPI(
				$participant->getAnswers(),
				$event->getParticipantQuestions()
			),
		];

		return $this->getResponseFactory()->createJson( $response );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}

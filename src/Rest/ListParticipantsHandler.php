<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ListParticipantsHandler extends SimpleHandler {
	use EventIDParamTrait;

	// TODO: Implement proper pagination (T305389)
	private const RES_LIMIT = 20;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$params = $this->getValidatedParams();
		$usernameFilter = $params['username_filter'];
		if ( $usernameFilter === '' ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-list-participants-empty-filter' ),
				400
			);
		}
		$participants = $this->participantsStore->getEventParticipants(
			$eventID,
			self::RES_LIMIT,
			$params['last_participant_id'],
			$usernameFilter
		);

		$respVal = [];
		foreach ( $participants as $participant ) {
			$centralUser = $participant->getUser();
			try {
				$userName = $this->centralUserLookup->getUserName( $centralUser );
			} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
				continue;
			}
			$respVal[] = [
				'participant_id' => $participant->getParticipantID(),
				'user_id' => $centralUser->getCentralID(),
				'user_name' => $userName,
				// To DO For now we decided on TS_DB to be the default returned by the api.
				// see T312910
				'user_registered_at' => wfTimestamp( TS_DB, $participant->getRegisteredAt() ),
			];
		}
		return $this->getResponseFactory()->createJson( $respVal );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return array_merge(
			$this->getIDParamSetting(),
			[
				'last_participant_id' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'integer'
				],
				'username_filter' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'string'
				]
			]
		);
	}
}

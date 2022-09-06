<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Sanitizer;
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
	/** @var UserLinker */
	private $userLinker;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserLinker $userLinker
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
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

		// TODO: remove global when T269492 is resolved

		$language = \RequestContext::getMain()->getLanguage();
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
				'user_page' => $this->getUserPagePath( $centralUser ),
				'user_registered_at' => wfTimestamp( TS_MW, $participant->getRegisteredAt() ),
				'user_registered_at_formatted' => $language->userTimeAndDate(
					$participant->getRegisteredAt(),
					$this->getAuthority()->getUser()
				)
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

	/**
	 * @param CentralUser $centralUser
	 * @return string[]
	 * NOTE: Make sure that the user is not hidden before calling this method, or it will throw an exception.
	 * TODO: Remove this hack and replace with a proper javascript implementation of Linker::GetUserLink
	 */
	private function getUserPagePath( CentralUser $centralUser ): array {
		$html = $this->userLinker->generateUserLink( $centralUser );
		$attribs = Sanitizer::decodeTagAttributes( $html );
		return [
			'path' => array_key_exists( 'href', $attribs ) ? $attribs['href'] : '',
			'title' => array_key_exists( 'title', $attribs ) ? $attribs['title'] : '',
			'classes' => array_key_exists( 'class', $attribs ) ? $attribs['class'] : ''
		];
	}
}

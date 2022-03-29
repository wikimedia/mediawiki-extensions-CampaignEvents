<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MWTimestamp;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class RegisterForEventHandler extends SimpleHandler {
	use CSRFCheckTrait;

	/** @var PermissionChecker */
	private $permissionChecker;
	/** @var IEventLookup */
	private $eventLookup;
	/** @var ParticipantsStore */
	private $participantsStore;

	/**
	 * @param PermissionChecker $permissionChecker
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 */
	public function __construct(
		PermissionChecker $permissionChecker,
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore
	) {
		$this->permissionChecker = $permissionChecker;
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->assertCSRFSafety();

		$performerAuthority = $this->getAuthority();
		$user = new MWUserProxy( $performerAuthority->getUser(), $performerAuthority );

		if ( !$this->permissionChecker->userCanRegisterForEvents( $user ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-register-permission-denied' ),
				403
			);
		}

		try {
			$eventRegistration = $this->eventLookup->getEventByID( $eventID );
		} catch ( EventNotFoundException $_ ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-register-event-not-found' ),
				404
			);
		}

		if ( $eventRegistration->getStatus() !== EventRegistration::STATUS_OPEN ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-register-event-not-open' ),
				400
			);
		}

		$endTS = $eventRegistration->getEndTimestamp();
		if ( (int)$endTS < (int)MWTimestamp::now( TS_UNIX ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-register-event-past' ),
				400
			);
		}

		$modified = $this->participantsStore->addParticipantToEvent( $eventID, $user );
		return $this->getResponseFactory()->createJson( [
			'modified' => $modified
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return [
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use StatusValue;
use Wikimedia\Message\MessageValue;

class CreateEventRegistrationHandler extends AbstractEditEventRegistrationHandler {
	/**
	 * @inheritDoc
	 */
	protected function getSuccessResponse( StatusValue $saveStatus ): Response {
		$id = $saveStatus->getValue();
		$resp = $this->getResponseFactory()->createJson( [
			'id' => $id
		] );
		$resp->setStatus( 201 );
		$resp->setHeader( 'Location', $this->getRouter()->getRouteUrl( "/campaignevents/v0/event_registration/$id" ) );
		return $resp;
	}

	/**
	 * @inheritDoc
	 */
	protected function checkPermissions( ICampaignsUser $user ): void {
		if ( !$this->permissionChecker->userCanCreateRegistrations( $user ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-createevent-permission-denied' ),
				403
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function createEventObject( array $body ): EventRegistration {
		$meetingType = 0;
		if ( $body['online_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_ONLINE;
		}
		if ( $body['physical_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_PHYSICAL;
		}

		return $this->eventFactory->newEvent(
			null,
			$body['event_page'],
			$body['chat_url'],
			// TODO MVP Add these
			null,
			null,
			EventRegistration::STATUS_OPEN,
			$body['start_time'],
			$body['end_time'],
			// TODO MVP Get this from the request body
			EventRegistration::TYPE_GENERIC,
			$meetingType,
			$body['meeting_url'],
			$body['meeting_country'],
			$body['meeting_address'],
			null,
			null,
			null,
			EventFactory::VALIDATE_ALL
		);
	}
}

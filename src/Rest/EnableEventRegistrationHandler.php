<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use StatusValue;
use Wikimedia\Message\MessageValue;

class EnableEventRegistrationHandler extends AbstractEditEventRegistrationHandler {
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
	protected function checkPermissions( ICampaignsAuthority $performer ): void {
		if ( !$this->permissionChecker->userCanEnableRegistrations( $performer ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-enable-registration-permission-denied' ),
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
		if ( $body['inperson_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_IN_PERSON;
		}

		return $this->eventFactory->newEvent(
			null,
			$body['event_page'],
			$body['chat_url'],
			// TODO MVP Add these
			null,
			null,
			EventRegistration::STATUS_OPEN,
			$body['timezone'],
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

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use StatusValue;
use Wikimedia\Message\MessageValue;

class CreateEventRegistrationHandler extends AbstractEditEventRegistrationHandler {

	/**
	 * @inheritDoc
	 */
	protected function getEventID(): ?int {
		return null;
	}

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
}

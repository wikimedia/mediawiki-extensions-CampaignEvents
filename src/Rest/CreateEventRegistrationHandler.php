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
		// TODO Set status code 201 when we'll be able to provide a Location
		return $this->getResponseFactory()->createJson( [
			'id' => $saveStatus->getValue()
		] );
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

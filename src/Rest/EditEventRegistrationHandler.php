<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;

class EditEventRegistrationHandler extends AbstractEventRegistrationHandler {

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return array_merge(
			parent::getParamSettings(),
			[
				'id' => [
					Handler::PARAM_SOURCE => 'path',
					ParamValidator::PARAM_TYPE => 'integer',
					ParamValidator::PARAM_REQUIRED => true,
				],
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getSuccessResponse( StatusValue $saveStatus ): Response {
		return $this->getResponseFactory()->createNoContent();
	}

	/**
	 * @inheritDoc
	 */
	protected function getEventID( array $body ): ?int {
		return $body['id'];
	}

	/**
	 * @inheritDoc
	 */
	protected function checkPermissions( ICampaignsUser $user ): void {
		// TODO Determine if we need to do something here
	}
}

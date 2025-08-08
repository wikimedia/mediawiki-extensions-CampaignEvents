<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Helper for handlers that take an event ID as parameter.
 */
trait EventIDParamTrait {
	/**
	 * @return array[]
	 */
	private function getIDParamSetting(): array {
		return [
			'id' => [
				Handler::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}

	/**
	 * Returns a registration with the given ID, ensuring that it exists and throwing an HttpException otherwise.
	 *
	 * @throws HttpException
	 */
	protected function getRegistrationOrThrow( IEventLookup $eventLookup, int $id ): ExistingEventRegistration {
		try {
			return $eventLookup->getEventByID( $id );
		} catch ( EventNotFoundException $_ ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-event-not-found' ),
				404
			);
		}
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

abstract class AbstractListEventsByUserHandler extends Handler {
	/** @var IEventLookup */
	protected $eventLookup;
	/** @var CampaignsCentralUserLookup */
	private $userLookup;

	// TODO: Implement proper pagination (T305389)
	protected const RES_LIMIT = 50;

	/**
	 * @param IEventLookup $eventLookup
	 * @param CampaignsCentralUserLookup $userLookup
	 */
	public function __construct(
		IEventLookup $eventLookup,
		CampaignsCentralUserLookup $userLookup
	) {
		$this->eventLookup = $eventLookup;
		$this->userLookup = $userLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->getValidatedParams();

		$user = new CentralUser( $params['userid'] );
		if ( !$this->userLookup->existsAndIsVisible( $user ) ) {
			// We don't really need an existing and visible account, but letting the user
			// know seems a good idea.
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-user-not-found' ),
				404
			);
		}

		return $this->getEventsByUser( $user, self::RES_LIMIT );
	}

	/**
	 * @param ExistingEventRegistration[] $events
	 * @return array
	 */
	protected function buildResultStructure( array $events ): array {
		$filter = [];

		foreach ( $events as $event ) {
			$data = [
				'event_id' => $event->getID(),
				'event_name' => $event->getName()
			];
			if ( $event->getDeletionTimestamp() !== null ) {
				$data['event_deleted'] = true;
			}
			$filter[] = $data;
		}

		return $filter;
	}

	/**
	 * @param CentralUser $user
	 * @param int $resultLimit
	 * @return array
	 */
	abstract protected function getEventsByUser( CentralUser $user, int $resultLimit ): array;

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return [
			'userid' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\ParamValidator\TypeDef\UserDef;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

abstract class AbstractListEventsByUserHandler extends Handler {
	/** @var IEventLookup */
	protected $eventLookup;
	/** @var CampaignsCentralUserLookup */
	protected $userLookup;
	/** @var UserFactory */
	protected $userFactory;
	/** @var UserNameUtils */
	protected $userNameUtils;

	// TODO: Implement proper pagination (T305389)
	protected const RES_LIMIT = 50;

	/**
	 * @param IEventLookup $eventLookup
	 * @param CampaignsCentralUserLookup $userLookup
	 * @param UserFactory $userFactory
	 * @param UserNameUtils $userNameUtils
	 */
	public function __construct(
		IEventLookup $eventLookup,
		CampaignsCentralUserLookup $userLookup,
		UserFactory $userFactory,
		UserNameUtils $userNameUtils

	) {
		$this->eventLookup = $eventLookup;
		$this->userLookup = $userLookup;
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->getValidatedParams();

		if ( !$this->userNameUtils->isIp( $params['user']->getName() ) && !$params['user']->isRegistered() ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-user-not-found', [ $params['user']->getName() ] ), 404
			);
		}

		$targetAuthority = $this->userFactory->newFromUserIdentity( $params['user'] );

		$user = new MWUserProxy( $params['user'], $targetAuthority );
		$userID = $this->userLookup->getCentralID( $user );

		return $this->getEventsByUser( $userID, self::RES_LIMIT );
	}

	/**
	 * @param int $userID
	 * @param int $resultLimit
	 * @return array
	 */
	abstract protected function getEventsByUser( int $userID, int $resultLimit ): array;

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return [
			'user' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'user',
				ParamValidator::PARAM_REQUIRED => true,
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name' ],
				UserDef::PARAM_RETURN_OBJECT => true
			],
		];
	}
}

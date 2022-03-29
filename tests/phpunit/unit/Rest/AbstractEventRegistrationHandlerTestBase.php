<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Rest\Handler;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @todo We can't test param validation due to T303619
 */
abstract class AbstractEventRegistrationHandlerTestBase extends MediaWikiUnitTestCase {
	use CSRFTestHelperTrait;

	protected const DEFAULT_POST_PARAMS = [
		'name' => 'Some event name',
		'event_page' => 'Some event page title',
		'chat_url' => 'https://chaturl.example.org',
		'tracking_tool_name' => 'Tracking tool',
		'tracking_tool_url' => 'https://trackingtool.example.org',
		'start_time' => '20220308120000',
		'end_time' => '20220308150000',
		'type' => EventRegistration::TYPE_GENERIC,
		'online_meeting' => true,
		'physical_meeting' => true,
		'meeting_url' => 'https://meetingurl.example.org',
		'meeting_country' => 'Country',
		'meeting_address' => 'Address',
	];

	/**
	 * @return string
	 */
	abstract protected function getHandlerClass(): string;

	/**
	 * @param EventFactory|null $eventFactory
	 * @param EditEventCommand|null $editEventCmd
	 * @return Handler
	 */
	protected function newHandler(
		EventFactory $eventFactory = null,
		EditEventCommand $editEventCmd = null
	): Handler {
		if ( !$eventFactory ) {
			$event = $this->createMock( EventRegistration::class );
			$event->method( 'getStatus' )->willReturn( EventRegistration::STATUS_OPEN );
			$event->method( 'getType' )->willReturn( EventRegistration::TYPE_GENERIC );
			$eventFactory = $this->createMock( EventFactory::class );
			$eventFactory->method( 'newEvent' )->willReturn( $event );
		}

		if ( !$editEventCmd ) {
			$editEventCmd = $this->createMock( EditEventCommand::class );
			$editEventCmd->method( 'doEditIfAllowed' )->willReturn( StatusValue::newGood( 42 ) );
		}
		$handlerClass = $this->getHandlerClass();
		$handler = new $handlerClass(
			$eventFactory,
			new PermissionChecker( $this->createMock( UserBlockChecker::class ) ),
			$editEventCmd
		);
		$this->setHandlerCSRFSafe( $handler );
		return $handler;
	}
}

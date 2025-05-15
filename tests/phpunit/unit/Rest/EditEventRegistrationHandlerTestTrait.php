<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use StatusValue;

trait EditEventRegistrationHandlerTestTrait {
	use CSRFTestHelperTrait;
	use HandlerTestTrait;

	private static array $defaultEventParams = [
		'event_page' => 'Some event page title',
		'chat_url' => 'https://chaturl.example.org',
		'wikis' => [ '*' ],
		'topics' => [],
		'timezone' => 'UTC',
		'start_time' => '20220308120000',
		'end_time' => '20220308150000',
		'online_meeting' => true,
		'inperson_meeting' => true,
		'meeting_url' => 'https://meetingurl.example.org',
		'meeting_country' => 'Country',
		'meeting_address' => 'Address',
		'is_test_event' => false,
	];

	/**
	 * @return EditEventCommand
	 */
	protected function getMockEditEventCommand(): EditEventCommand {
		$editEventCmd = $this->createMock( EditEventCommand::class );
		$editEventCmd->method( 'doEditIfAllowed' )->willReturn( StatusValue::newGood( 42 ) );
		return $editEventCmd;
	}
}

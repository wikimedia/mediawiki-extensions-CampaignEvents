<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWikiUnitTestCase;
use StatusValue;

abstract class EditEventRegistrationHandlerTestBase extends MediaWikiUnitTestCase {
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

	protected function getMockEditEventCommand(): EditEventCommand {
		$editEventCmd = $this->createMock( EditEventCommand::class );
		$editEventCmd->method( 'doEditIfAllowed' )->willReturn( StatusValue::newGood( 42 ) );
		return $editEventCmd;
	}

	protected function getMockPermissionChecker(): EditEventCommand {
		return new PermissionChecker(
			$this->createMock( UserBlockChecker::class ),
			$this->createMock( OrganizersStore::class )
		);
	}
}

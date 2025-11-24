<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use StatusValue;

trait EditEventRegistrationHandlerTestTrait {
	use CSRFTestHelperTrait;
	use HandlerTestTrait;

	private static array $defaultEventParams = [
		'event_page' => 'Some event page title',
		'timezone' => 'UTC',
		'start_time' => '20220308120000',
		'end_time' => '20220308150000',
		'types' => [ 'editing-event' ],
		'wikis' => [ '*' ],
		'topics' => [],
		'online_meeting' => true,
		'inperson_meeting' => true,
		'meeting_url' => 'https://meetingurl.example.org',
		'meeting_country_code' => 'PT',
		'meeting_address' => 'Address',
		'tracks_contributions' => true,
		'chat_url' => 'https://chaturl.example.org',
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

	private function getCountryProvider( array $allowedCountries = [ 'PT' ] ): CountryProvider {
		$countryProvider = $this->createMock( CountryProvider::class );
		$countryProvider->method( 'getValidCountryCodes' )->willReturn( $allowedCountries );
		return $countryProvider;
	}
}

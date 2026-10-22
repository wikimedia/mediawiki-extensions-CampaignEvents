<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\FrontendModules;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Output\OutputPage;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\FrontendModules\EmailParticipantsModule
 */
class EmailParticipantsModuleTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideOrganizerEmailWarning */
	public function testOrganizerEmailWarning( bool $userIsEmailConfirmed, bool $expectsWarning ) {
		OutputPage::setupOOUI();
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( 'qqx' );
		$language = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'qqx' );

		$html = (string)CampaignEventsServices::getFrontendModulesFactory()
			->newEmailParticipantsModule()
			->createContent( $language, $userIsEmailConfirmed, $context );

		$warning = '(campaignevents-event-details-no-organizer-email)';
		if ( $expectsWarning ) {
			$this->assertStringContainsString( $warning, $html );
			$this->assertLessThan(
				strpos( $html, '(campaignevents-event-details-email-recipients-label)' ),
				strpos( $html, $warning ),
				'The warning is shown above the recipients'
			);
		} else {
			$this->assertStringNotContainsString( $warning, $html );
		}
	}

	public static function provideOrganizerEmailWarning(): array {
		return [
			'Organizer with a confirmed email address' => [ true, false ],
			'Organizer without an email address' => [ false, true ],
		];
	}
}

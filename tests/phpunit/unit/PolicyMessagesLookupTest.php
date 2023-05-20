<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit;

use Generator;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\StaticHookRegistry;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup
 * @covers ::__construct
 */
class PolicyMessagesLookupTest extends MediaWikiUnitTestCase {
	/**
	 * @param callable[] $hookHandlers
	 * @param string|null $expected
	 * @dataProvider provideHookHandlers
	 * @covers ::getPolicyMessageForRegistration
	 */
	public function testGetPolicyMessage( array $hookHandlers, ?string $expected ) {
		// Note: we can't use $this->createHookContainer() because that only allows one handler per hook
		$hookContainer = new HookContainer(
			new StaticHookRegistry(),
			$this->createSimpleObjectFactory()
		);
		foreach ( $hookHandlers as $callback ) {
			$hookContainer->register( 'CampaignEventsGetPolicyMessageForRegistration', $callback );
		}
		$lookup = new PolicyMessagesLookup( new CampaignEventsHookRunner( $hookContainer ) );
		$this->assertSame( $expected, $lookup->getPolicyMessageForRegistration() );
	}

	public static function provideHookHandlers(): Generator {
		yield 'No handlers' => [ [], null ];

		$notAbortingMsg = 'message-not-aborting';
		$notAbortingHandler = static function ( ?string &$msg ) use ( $notAbortingMsg ): void {
			$msg = $notAbortingMsg;
		};
		$abortingMsg = 'message-aborting';
		$abortingHandler = static function ( ?string &$msg ) use ( $abortingMsg ): bool {
			$msg = $abortingMsg;
			return false;
		};

		yield 'Single handler' => [ [ $notAbortingHandler ], $notAbortingMsg ];
		yield 'Two handlers, first one does not abort' => [ [ $notAbortingHandler, $abortingHandler ], $abortingMsg ];
		yield 'Two handlers, first one aborts' => [ [ $abortingHandler, $notAbortingHandler ], $abortingMsg ];
	}
}

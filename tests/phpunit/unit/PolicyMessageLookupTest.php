<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit;

use Generator;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\PolicyMessageLookup;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\StaticHookRegistry;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\PolicyMessageLookup
 * @covers ::__construct
 */
class PolicyMessageLookupTest extends MediaWikiUnitTestCase {
	/**
	 * @param callable[] $hookHandlers
	 * @param string|null $expected
	 * @dataProvider provideHookHandlers
	 * @covers ::getPolicyMessage
	 */
	public function testGetPolicyMessage( array $hookHandlers, ?string $expected ) {
		// Note: we can't use $this->createHookContainer() because that only allows one handler per hook
		$hookContainer = new HookContainer(
			new StaticHookRegistry(),
			$this->createSimpleObjectFactory()
		);
		foreach ( $hookHandlers as $callback ) {
			$hookContainer->register( 'CampaignEventsGetPolicyMessage', $callback );
		}
		$lookup = new PolicyMessageLookup( new CampaignEventsHookRunner( $hookContainer ) );
		$this->assertSame( $expected, $lookup->getPolicyMessage() );
	}

	public function provideHookHandlers(): Generator {
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

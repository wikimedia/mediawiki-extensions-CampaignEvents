<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use HashConfig;
use Language;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Rest\GetParticipantQuestionsHandler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetParticipantQuestionsHandler
 */
class GetParticipantQuestionsHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private const QUESTION_OVERRIDES = [
		[
			'name' => 'testradio',
			'db-id' => 1,
			'wikimedia' => false,
			'questionData' => [
				'type' => EventQuestionsRegistry::RADIO_BUTTON_QUESTION_TYPE,
				'label-message' => 'question-1-label',
				'options-messages' => [
					'question-1-option-0' => 0,
					'question-1-option-1' => 1,
					'question-1-option-2' => 2,
				],
			],
		],
		[
			'name' => 'testselect',
			'db-id' => 2,
			'wikimedia' => false,
			'questionData' => [
				'type' => EventQuestionsRegistry::SELECT_QUESTION_TYPE,
				'label-message' => 'question-2-label',
				'options-messages' => [
					'question-2-option-0' => 0,
					'question-2-option-1' => 1,
					'question-2-option-2' => 2,
				],
			],
		],
		[
			'name' => 'testother',
			'db-id' => 3,
			'wikimedia' => false,
			'questionData' => [
				'type' => EventQuestionsRegistry::SELECT_QUESTION_TYPE,
				'label-message' => 'question-3-label',
				'options-messages' => [
					'question-3-option-0' => 0,
					'question-3-option-1' => 1,
					'question-3-option-2' => 2,
				],
			],
			'otherOptions' => [
				1 => [
					'type' => EventQuestionsRegistry::FREE_TEXT_QUESTION_TYPE,
					'placeholder-message' => 'question-3-placeholder',
				],
			],
		],
	];

	private const QUESTION_OVERRIDES_API = [
		1 => [
			'name' => 'testradio',
			'type' => EventQuestionsRegistry::RADIO_BUTTON_QUESTION_TYPE,
			'label' => 'question-1-label',
			'options' => [
				'question-1-option-0' => 0,
				'question-1-option-1' => 1,
				'question-1-option-2' => 2,
			],
		],
		2 => [
			'name' => 'testselect',
			'type' => EventQuestionsRegistry::SELECT_QUESTION_TYPE,
			'label' => 'question-2-label',
			'options' => [
				'question-2-option-0' => 0,
				'question-2-option-1' => 1,
				'question-2-option-2' => 2,
			],
		],
		3 => [
			'name' => 'testother',
			'type' => EventQuestionsRegistry::SELECT_QUESTION_TYPE,
			'label' => 'question-3-label',
			'options' => [
				'question-3-option-0' => 0,
				'question-3-option-1' => 1,
				'question-3-option-2' => 2,
			],
			'other-options' => [
				1 => [
					'type' => EventQuestionsRegistry::FREE_TEXT_QUESTION_TYPE,
					'label' => 'question-3-placeholder',
				],
			],
		],
	];

	private function newHandler(): GetParticipantQuestionsHandler {
		$registry = new EventQuestionsRegistry( true );
		$registry->overrideQuestionsForTesting( self::QUESTION_OVERRIDES );
		$msgFormatter = $this->createMock( ITextFormatter::class );
		$msgFormatter->method( 'format' )->willReturnCallback( static fn ( MessageValue $msg ) => $msg->getKey() );
		$msgFormatterFactory = $this->createMock( IMessageFormatterFactory::class );
		$msgFormatterFactory->method( 'getTextFormatter' )->willReturn( $msgFormatter );
		return new GetParticipantQuestionsHandler(
			$registry,
			$msgFormatterFactory,
			$this->createMock( Language::class ),
			new HashConfig( [ 'CampaignEventsEnableParticipantQuestions' => true ] )
		);
	}

	/**
	 * @dataProvider provideExecute
	 */
	public function testExecute( array $queryParams, array $expected ) {
		$handler = $this->newHandler();
		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( [ 'queryParams' => $queryParams ] )
		);
		$this->assertSame( $expected, $respData );
	}

	public function provideExecute(): Generator {
		yield 'No filter' => [
			[],
			self::QUESTION_OVERRIDES_API
		];
		yield 'Empty filter' => [
			[ 'question_ids' => [] ],
			[]
		];
		yield 'Filter by single question' => [
			[ 'question_ids' => [ 1 ] ],
			[ 1 => self::QUESTION_OVERRIDES_API[1] ]
		];
		yield 'Filter by multiple questions' => [
			[ 'question_ids' => [ 1, 2 ] ],
			[ 1 => self::QUESTION_OVERRIDES_API[1], 2 => self::QUESTION_OVERRIDES_API[2] ]
		];
	}
}

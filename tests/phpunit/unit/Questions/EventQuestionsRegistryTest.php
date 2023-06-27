<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Questions;

use Generator;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\UnknownQuestionException;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry
 */
class EventQuestionsRegistryTest extends MediaWikiUnitTestCase {

	private function getRegistry(): EventQuestionsRegistry {
		return new EventQuestionsRegistry( true );
	}

	/**
	 * @covers ::getQuestions
	 */
	public function testRegistryStructure(): void {
		$questions = $this->getRegistry()->getQuestionsForTesting();
		$seenNames = [];
		$seenDBIDs = [];
		foreach ( $questions as $questionDescriptor ) {
			$this->assertIsArray( $questions, 'Question descriptor should be an array' );
			$this->assertArrayHasKey( 'name', $questionDescriptor, 'Questions should have a name' );
			$name = $questionDescriptor['name'];
			$this->assertIsString( $name, 'Question names should be strings' );
			$this->assertArrayNotHasKey( $name, $seenNames, 'Duplicated question name' );
			$seenNames[$name] = true;
			$this->assertArrayHasKey( 'db-id', $questionDescriptor, 'Questions should have a DB ID' );
			$dbID = $questionDescriptor['db-id'];
			$this->assertIsInt( $dbID, 'Question DB IDs should be integers' );
			$this->assertArrayNotHasKey( $dbID, $seenDBIDs, 'Duplicated question DB ID' );
			$seenDBIDs[$dbID] = true;
			$this->assertArrayHasKey(
				'wikimedia',
				$questionDescriptor,
				'Questions should specify whether they are Wikimedia-specific'
			);
			$this->assertIsBool(
				$questionDescriptor['wikimedia'],
				'Values for the "wikimedia" key should be booleans'
			);
			$this->assertArrayHasKey( 'questionData', $questionDescriptor, 'Questions should have data' );
			$questionData = $questionDescriptor['questionData'];
			$this->assertArrayHasKey( 'type', $questionData, 'Questions should have a type' );
			$questionType = $questionData['type'];
			if ( in_array( $questionType, EventQuestionsRegistry::MULTIPLE_CHOICE_TYPES, true ) ) {
				$this->assertArrayHasKey(
					'options-messages',
					$questionData,
					'Multiple-choice questions must have options-messages'
				);
				$this->assertContains(
					0,
					$questionData['options-messages'],
					'There must be a placeholder option with value 0'
				);
			}
			$this->assertArrayHasKey( 'label-message', $questionData, 'Questions should have a label' );
			if ( isset( $questionDescriptor['otherOptions'] ) ) {
				$this->assertIsArray( $questionDescriptor['otherOptions'], 'otherOptions should be an array' );
				$this->assertContains(
					$questionType,
					EventQuestionsRegistry::MULTIPLE_CHOICE_TYPES,
					'Only multiple choice questions can have other options'
				);
				foreach ( $questionDescriptor['otherOptions'] as $key => $val ) {
					$this->assertIsInt( $key, 'otherOptions should use parent values as keys' );
					$this->assertContains(
						$key,
						$questionData['options-messages'],
						'otherOptions keys must be possible values of the parent field'
					);
					$this->assertIsArray( $val, 'Each option in otherOptions should be an array' );
					$this->assertSame(
						EventQuestionsRegistry::FREE_TEXT_QUESTION_TYPE,
							$val['type'] ?? null,
						'Each option in otherOptions should be explicitly defined as free text'
					);
					$this->assertArrayHasKey(
						'placeholder-message',
						$val,
						'Each option in otherOptions should have a placeholder'
					);
				}
			}
		}
	}

	/**
	 * @covers ::getQuestionsForHTMLForm
	 */
	public function testGetQuestionsForHTMLForm(): void {
		$registry = $this->getRegistry();
		$availableQuestionIDs = array_column( $registry->getQuestionsForTesting(), 'db-id' );
		$htmlFormQuestions = $this->getRegistry()->getQuestionsForHTMLForm( $availableQuestionIDs, [] );
		foreach ( $htmlFormQuestions as $key => $descriptor ) {
			$this->assertIsString( $key, 'HTMLForm keys should be strings (field names)' );
			$this->assertIsArray( $descriptor, 'HTMLForm descriptors should be arrays' );
			// TODO: We may want to check that $descriptor is valid (e.g., by calling
			// HTMLForm::loadInputFromParameters), but the code is still heavily reliant on global state.
		}
	}

	/**
	 * @covers ::extractUserAnswersHTMLForm
	 * @covers ::newAnswerFromHTMLForm
	 * @covers ::isPlaceholderValue
	 * @dataProvider provideExtractUserAnswersHTMLForm
	 */
	public function testExtractUserAnswersHTMLForm( array $formData, array $enabledQuestions, array $expectedAnswers ) {
		$this->assertEquals(
			$expectedAnswers,
			$this->getRegistry()->extractUserAnswersHTMLForm( $formData, $enabledQuestions )
		);
	}

	public function provideExtractUserAnswersHTMLForm(): Generator {
		yield 'Empty form data' => [ [], [ 1, 2, 3 ], [] ];
		yield 'No answers in form data' => [ [ 'notaquestion' => 42 ], [ 1 ], [] ];
		yield 'Form data contains answer to question not enabled' => [ [ 'QuestionAge' => 2 ], [], [] ];
		yield 'Placeholder radio' => [ [ 'QuestionGender' => 0 ], [ 1 ], [] ];
		yield 'Placeholder select' => [ [ 'QuestionAge' => 0 ], [ 2 ], [] ];
		yield 'Simple answer with no other value' => [
			[ 'QuestionGender' => 1 ],
			[ 1 ],
			[ new Answer( 1, 1, null ) ]
		];
		yield 'No value provided for otherOption' => [
			[ 'QuestionAffiliate' => 2 ],
			[ 5 ],
			[ new Answer( 5, 2, null ) ]
		];
		yield 'Placeholder provided for otherOption' => [
			[ 'QuestionAffiliate' => 2, 'QuestionAffiliate_Other' => '' ],
			[ 5 ],
			[ new Answer( 5, 2, null ) ]
		];
		yield 'Answer with other value' => [
			[ 'QuestionAffiliate' => 2, 'QuestionAffiliate_Other' => 'some-affiliate' ],
			[ 5 ],
			[ new Answer( 5, 2, 'some-affiliate' ) ]
		];
		yield 'Multiple answer types' => [
			[
				'QuestionGender' => 3,
				'QuestionAge' => 5,
				'QuestionAffiliate' => 2,
				'QuestionAffiliate_Other' => 'some-affiliate'
			],
			[ 1, 2, 3, 4, 5 ],
			[
				new Answer( 1, 3, null ),
				new Answer( 2, 5, null ),
				new Answer( 5, 2, 'some-affiliate' )
			]
		];
	}

	/**
	 * @covers ::getQuestionsForHTMLForm
	 * @covers ::extractUserAnswersHTMLForm
	 * @covers ::newAnswerFromHTMLForm
	 */
	public function testHTMLFormRoundtrip() {
		$registry = $this->getRegistry();
		$enabledQuestions = [ 1, 2, 3, 4, 5 ];
		$htmlFormDescriptor = $registry->getQuestionsForHTMLForm( $enabledQuestions, [] );
		$reqData = [];
		foreach ( $htmlFormDescriptor as $name => $field ) {
			switch ( $field['type'] ) {
				case 'radio':
				case 'select':
					// Note: this assumes that the affiliate question only uses 'otherOption' when the value is 2
					$val = 2;
					break;
				case 'text':
					$val = 'foo';
					break;
				default:
					$this->fail( "Unhandled field type " . $field['type'] );
			}
			$reqData[$name] = $val;
		}
		$parsedAnswers = $registry->extractUserAnswersHTMLForm( $reqData, $enabledQuestions );
		$expected = [
			new Answer( 1, 2, null ),
			new Answer( 2, 2, null ),
			new Answer( 3, 2, null ),
			new Answer( 4, 2, null ),
			new Answer( 5, 2, 'foo' ),
		];
		$this->assertEquals( $expected, $parsedAnswers );
	}

	/**
	 * @covers ::getAvailableQuestionNames
	 */
	public function testGetAvailableQuestionNames() {
		$expected = [
			'gender',
			'age',
			'profession',
			'confidence',
			'affiliate',
		];
		$this->assertSame( $expected, $this->getRegistry()->getAvailableQuestionNames() );
	}

	/**
	 * @covers ::nameToDBID
	 * @dataProvider provideNameToDBID
	 */
	public function testNameToDBID( string $name, ?int $expected ) {
		if ( $expected === null ) {
			$this->expectException( UnknownQuestionException::class );
		}
		$actual = $this->getRegistry()->nameToDBID( $name );
		if ( $expected !== null ) {
			$this->assertSame( $expected, $actual );
		}
	}

	public static function provideNameToDBID(): Generator {
		yield 'Valid' => [ 'age', 2 ];
		yield 'Invalid' => [ 'this-is-definitely-invalid', null ];
	}

	/**
	 * @covers ::nameToDBID
	 * @dataProvider provideDbIDToName
	 */
	public function testDbIDToName( int $dbID, ?string $expected ) {
		if ( $expected === null ) {
			$this->expectException( UnknownQuestionException::class );
		}
		$actual = $this->getRegistry()->dbIDToName( $dbID );
		if ( $expected !== null ) {
			$this->assertSame( $expected, $actual );
		}
	}

	public static function provideDbIDToName(): Generator {
		yield 'Valid' => [ 2, 'age' ];
		yield 'Invalid' => [ -142365, null ];
	}
}

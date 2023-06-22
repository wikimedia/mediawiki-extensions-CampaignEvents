<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Questions;

use Generator;
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
		$htmlFormQuestions = $this->getRegistry()->getQuestionsForHTMLForm( $availableQuestionIDs );
		foreach ( $htmlFormQuestions as $key => $descriptor ) {
			$this->assertIsString( $key, 'HTMLForm keys should be strings (field names)' );
			$this->assertIsArray( $descriptor, 'HTMLForm descriptors should be arrays' );
			// TODO: We may want to check that $descriptor is valid (e.g., by calling
			// HTMLForm::loadInputFromParameters), but the code is still heavily reliant on global state.
		}
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

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
			if ( isset( $questionDescriptor['otherOptions'] ) ) {
				$this->assertIsArray( $questionDescriptor['otherOptions'], 'otherOptions should be an array' );
				foreach ( $questionDescriptor['otherOptions'] as $key => $val ) {
					$this->assertIsString( $key, 'otherOptions should use string names as keys' );
					$this->assertIsArray( $val, 'Each option in otherOptions should be an array' );
					$this->assertArrayHasKey( 'type', $val, 'Each option in otherOptions should have a type' );
				}
			}
		}
	}

	/**
	 * @covers ::getQuestionsForHTMLForm
	 */
	public function testGetQuestionsForHTMLForm(): void {
		$htmlFormQuestions = $this->getRegistry()->getQuestionsForHTMLForm();
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

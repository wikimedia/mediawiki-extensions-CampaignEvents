<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Utils
 */
class UtilsTest extends MediaWikiIntegrationTestCase {
	private function doTestCaseInsensitiveDBSearch( string $columnValue, string $search ): void {
		$db = $this->getDb();
		// Use a simple existing table for testing
		$db->newInsertQueryBuilder()
			->insertInto( 'ce_event_wikis' )
			->row( [ 'ceew_event_id' => 1, 'ceew_wiki' => $columnValue ] )
			->caller( __METHOD__ )
			->execute();

		$rowCount = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_event_wikis' )
			->where( Utils::buildCaseInsensitiveSubstringSearchDBCondition( $db, 'ceew_wiki', $search ) )
			->fetchRowCount();
		$this->assertSame( 1, $rowCount );
	}

	public function testBuildCaseInsensitiveSubstringSearchDBCondition() {
		$this->doTestCaseInsensitiveDBSearch( 'tHis is tHE hAYsTaCk', 'IS The' );
	}

	public function testBuildCaseInsensitiveSubstringSearchDBCondition__nonASCII() {
		$this->markTestSkippedIfDbType( 'sqlite' );
		$this->doTestCaseInsensitiveDBSearch( 'accents: ÀèÉìÒù', 'èÉÌò' );
	}
}

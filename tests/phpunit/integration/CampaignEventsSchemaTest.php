<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use MediaWiki\Tests\Structure\AbstractSchemaTestBase;

/**
 * @coversNothing
 */
class CampaignEventsSchemaTest extends AbstractSchemaTestBase {
	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkipped( 'Temporary disabled for gerrit 1174110' );
	}

	protected static function getSchemasDirectory(): string {
		return __DIR__ . '/../../../db_patches';
	}

	protected static function getSchemaChangesDirectory(): string {
		return __DIR__ . '/../../../db_patches/abstractSchemaChanges/';
	}

	protected static function getSchemaSQLDirs(): array {
		return [
			'mysql' => __DIR__ . '/../../../db_patches/mysql',
			'sqlite' => __DIR__ . '/../../../db_patches/sqlite',
			'postgres' => __DIR__ . '/../../../db_patches/postgres',
		];
	}
}

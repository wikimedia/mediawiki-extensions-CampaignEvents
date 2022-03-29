<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use stdClass;
use Wikimedia\Rdbms\IDatabase;

class MWDatabaseProxy implements ICampaignsDatabase {
	/** @var IDatabase */
	private $db;

	/**
	 * @param IDatabase $db
	 */
	public function __construct( IDatabase $db ) {
		$this->db = $db;
	}

	/**
	 * @inheritDoc
	 */
	public function selectRow( $table, $vars, $conds, $options = [], $join_conds = [] ): ?stdClass {
		return $this->db->selectRow( $table, $vars, $conds, wfGetCaller(), $options, $join_conds ) ?: null;
	}

	/**
	 * @inheritDoc
	 */
	public function select( $table, $vars, $conds = '', $options = [], $join_conds = [] ): iterable {
		return $this->db->select( $table, $vars, $conds, wfGetCaller(), $options, $join_conds );
	}

	/**
	 * @inheritDoc
	 */
	public function selectFieldValues(
		$table,
		string $field,
		array $conds = [],
		$options = [],
		$join_conds = []
	): array {
		return $this->db->selectFieldValues( $table, $field, $conds, wfGetCaller(), $options, $join_conds );
	}

	/**
	 * @inheritDoc
	 */
	public function insert( string $table, array $rows, $options = [] ): bool {
		return $this->db->insert( $table, $rows, wfGetCaller(), $options );
	}

	/**
	 * @inheritDoc
	 */
	public function update( string $table, array $set, $conds, $options = [] ): bool {
		return $this->db->update( $table, $set, $conds, wfGetCaller(), $options );
	}

	/**
	 * @inheritDoc
	 */
	public function replace( string $table, string $uniqueKey, array $row ): void {
		$this->db->replace( $table, $uniqueKey, $row, wfGetCaller() );
	}

	/**
	 * @inheritDoc
	 */
	public function insertId(): int {
		return $this->db->insertId();
	}

	/**
	 * @inheritDoc
	 */
	public function affectedRows(): int {
		return $this->db->affectedRows();
	}

	/**
	 * @inheritDoc
	 */
	public function timestamp( $ts = 0 ): string {
		return $this->db->timestamp( $ts );
	}
}

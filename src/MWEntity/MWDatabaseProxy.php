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
	public function selectField( string $table, string $field, array $conds = [], $options = [], $join_conds = [] ) {
		return $this->db->selectField( $table, $field, $conds, wfGetCaller(), $options, $join_conds );
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
	public function upsert( string $table, array $rows, $uniqueKeys, array $set ): void {
		$this->db->upsert( $table, $rows, $uniqueKeys, $set, wfGetCaller() );
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

	/**
	 * @inheritDoc
	 */
	public function startAtomic(): void {
		$this->db->startAtomic( wfGetCaller() );
	}

	/**
	 * @inheritDoc
	 */
	public function endAtomic(): void {
		$this->db->endAtomic( wfGetCaller() );
	}

	/**
	 * @inheritDoc
	 */
	public function addQuotes( $s ): string {
		return $this->db->addQuotes( $s );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( $table, $conds ): bool {
		return $this->db->delete( $table, $conds, wfGetCaller() );
	}

	/**
	 * @inheritDoc
	 */
	public function deleteJoin(
		string $delTable,
		string $joinTable,
		string $delVar,
		string $joinVar,
		$conds
	) {
		return $this->db->deleteJoin( $delTable, $joinTable, $delVar, $joinVar, $conds, wfGetCaller() );
	}

	/**
	 * @inheritDoc
	 */
	public function makeCommaList( array $a ): string {
		return $this->db->makeList( $a, IDatabase::LIST_COMMA );
	}

	/**
	 * @inheritDoc
	 */
	public function conditional( $cond, string $caseTrueExpression, string $caseFalseExpression ): string {
		return $this->db->conditional( $cond, $caseTrueExpression, $caseFalseExpression );
	}

	/**
	 * @inheritDoc
	 */
	public function buildExcludedValue( string $column ): string {
		return $this->db->buildExcludedValue( $column );
	}

	/**
	 * @return IDatabase
	 */
	public function getMWDatabase(): IDatabase {
		return $this->db;
	}

	/**
	 * @inheritDoc
	 */
	public function bitAnd( $fieldLeft, $fieldRight ): string {
		return $this->db->bitAnd( $fieldLeft, $fieldRight );
	}
}

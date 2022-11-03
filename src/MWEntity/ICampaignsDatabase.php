<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use Countable;
use stdClass;

interface ICampaignsDatabase {

	/**
	 * @param string|array $table Table name
	 * @param string|array $vars Field names
	 * @param string|array $conds Conditions
	 * @param string|array $options Query options
	 * @param array|string $join_conds Join conditions
	 * @return stdClass|null
	 */
	public function selectRow(
		$table,
		$vars,
		$conds,
		$options = [],
		$join_conds = []
	): ?stdClass;

	/**
	 * @param string|array $table
	 * @param string|array $vars
	 * @param string|array $conds
	 * @param string|array $options
	 * @param string|array $join_conds
	 * @return iterable<stdClass>&Countable
	 */
	public function select(
		$table,
		$vars,
		$conds = '',
		$options = [],
		$join_conds = []
	): iterable;

	/**
	 * @param string $table
	 * @param string $field
	 * @param array $conds
	 * @param string|array $options
	 * @param string|array $join_conds
	 * @return mixed|false The value from the field, or false if nothing was found
	 */
	public function selectField(
		string $table,
		string $field,
		array $conds = [],
		$options = [],
		$join_conds = []
	);

	/**
	 * @param string|array $table
	 * @param string $field
	 * @param array $conds
	 * @param string|array $options
	 * @param string|array $join_conds
	 * @return array
	 */
	public function selectFieldValues(
		$table,
		string $field,
		array $conds = [],
		$options = [],
		$join_conds = []
	): array;

	/**
	 * @param string $table
	 * @param array $rows
	 * @param string|array $options
	 * @return bool
	 */
	public function insert( string $table, array $rows, $options = [] ): bool;

	/**
	 * @param string $table
	 * @param array $set
	 * @param array|string $conds
	 * @param string|array $options
	 * @return bool
	 */
	public function update( string $table, array $set, $conds, $options = [] ): bool;

	/**
	 * @param string $table
	 * @param array|array[] $rows
	 * @param string|string[][] $uniqueKeys
	 * @param array $set
	 * @since 1.22
	 */
	public function upsert( string $table, array $rows, $uniqueKeys, array $set ): void;

	/**
	 * @return int
	 */
	public function insertId(): int;

	/**
	 * @return int
	 */
	public function affectedRows(): int;

	/**
	 * @param string|int $ts
	 * @return string
	 */
	public function timestamp( $ts = 0 ): string;

	/**
	 * Begins an atomic section
	 */
	public function startAtomic(): void;

	/**
	 * End an atomic section
	 */
	public function endAtomic(): void;

	/**
	 * Escape and quote a raw value string for use in a SQL query
	 *
	 * @param string|int|float|null|bool $s
	 * @return string
	 */
	public function addQuotes( $s ): string;

	/**
	 * @param string $table
	 * @param array $conds
	 * @return bool Return true if no exception was thrown
	 */
	public function delete( string $table, array $conds ): bool;

	/**
	 * @param string $delTable The table to delete from.
	 * @param string $joinTable The other table.
	 * @param string $delVar The variable to join on, in the first table.
	 * @param string $joinVar The variable to join on, in the second table.
	 * @param array|string $conds Condition array of field names mapped to variables,
	 *   ANDed together in the WHERE clause
	 */
	public function deleteJoin(
	   string $delTable,
	   string $joinTable,
	   string $delVar,
	   string $joinVar,
	   $conds
	);

	/**
	 * Makes an encoded list of strings from an array
	 * @param array $a Containing the data
	 * @return string
	 */
	public function makeCommaList( array $a ): string;

	/**
	 * @param string|array $cond
	 * @param string $caseTrueExpression
	 * @param string $caseFalseExpression
	 * @return string
	 */
	public function conditional( $cond, string $caseTrueExpression, string $caseFalseExpression ): string;

	/**
	 * Build a reference to a column value from the conflicting proposed upsert() row.
	 * @param string $column Column name
	 * @return string SQL expression returning a scalar
	 */
	public function buildExcludedValue( string $column ): string;
}

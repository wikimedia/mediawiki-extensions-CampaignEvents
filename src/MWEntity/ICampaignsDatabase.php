<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

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
	 * @return iterable<stdClass>
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
	 * @param string $uniqueKey
	 * @param array $row
	 */
	public function replace( string $table, string $uniqueKey, array $row ): void;

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
}

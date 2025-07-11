<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\GetPreferencesHandler;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Storage\NameTableAccessException;
use MediaWiki\Storage\NameTableStoreFactory;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use UnexpectedValueException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * This class generates a list of potential event participants ("invitation list") by looking at who contributed
 * to a given list of pages ("worklist").
 */
class PotentialInviteesFinder {
	public const SERVICE_NAME = 'CampaignEventsPotentialInviteesFinder';

	/** How many days to look back into the past when scanning revisions. */
	public const CUTOFF_DAYS = 3 * 365;
	public const RESULT_USER_LIMIT = 200;
	private const REVISIONS_PER_PAGE_LIMIT = 5_000;
	private const MIN_SCORE = 5;

	private RevisionStoreFactory $revisionStoreFactory;
	private IConnectionProvider $dbProvider;
	private NameTableStoreFactory $nameTableStoreFactory;
	/**
	 * @var callable
	 * @phan-var callable(string $msg):void
	 */
	private $debugLogger;
	private UserOptionsLookup $userOptionsLookup;

	public function __construct(
		RevisionStoreFactory $revisionStoreFactory,
		IConnectionProvider $dbProvider,
		NameTableStoreFactory $nameTableStoreFactory,
		UserOptionsLookup $userOptionsLookup
	) {
		$this->revisionStoreFactory = $revisionStoreFactory;
		$this->dbProvider = $dbProvider;
		$this->nameTableStoreFactory = $nameTableStoreFactory;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->debugLogger = static function ( string $msg ): void {
		};
	}

	/**
	 * @param callable $debugLogger
	 * @phan-param callable(string $msg):void $debugLogger
	 */
	public function setDebugLogger( callable $debugLogger ): void {
		$this->debugLogger = $debugLogger;
	}

	/**
	 * @param Worklist $worklist
	 * @return array<string,int>
	 */
	public function generate( Worklist $worklist ): array {
		$revisionsByWiki = [];
		foreach ( $worklist->getPagesByWiki() as $wiki => $pages ) {
			if ( $wiki !== WikiMap::getCurrentWikiID() ) {
				// TODO: Re-implement support for multi-wiki worklists. Currently not doable because we'd need to read,
				// and possibly merge, cross-wiki user preferences. Note that this code is currently unreachable.
				throw new UnexpectedValueException( "Unexpected foreign page on $wiki" );
			}
			$revisionsByWiki[$wiki] = $this->getAllRevisionsForWiki( $wiki, $pages );
		}
		$revisionsByWiki = array_filter( $revisionsByWiki );
		if ( !$revisionsByWiki ) {
			return [];
		}

		$rankedUsers = $this->rankUsers( $revisionsByWiki );
		// Return just the top scores to avoid useless mile-long invitation lists. Preserve integer keys
		// in case a username is numeric and PHP cast it to int.
		return array_slice( $rankedUsers, 0, self::RESULT_USER_LIMIT, true );
	}

	/**
	 * @param string $wikiIDStr
	 * @param PageIdentity[] $pages
	 * @return array[] List of arrays with revision data. The page is only included for debugging, and callers should
	 * not rely on its format.
	 * @phan-return list<array{username:string,userID:int,actorID:int,page:string,delta:int}>
	 */
	private function getAllRevisionsForWiki( string $wikiIDStr, array $pages ): array {
		$wikiID = $wikiIDStr === WikiMap::getCurrentWikiId()
			? WikiAwareEntity::LOCAL
			: $wikiIDStr;
		$revisionStore = $this->revisionStoreFactory->getRevisionStore( $wikiID );
		// This script may potentially scan a lot of revisions. Although the queries can use good indexes, sending them
		// to vslow hosts shouldn't hurt.
		$dbr = $this->dbProvider->getReplicaDatabase( $wikiID, 'vslow' );

		$pagesByID = [];
		foreach ( $pages as $page ) {
			$pageID = $page->getId( $wikiID );
			$pagesByID[$pageID] = $page;
		}
		// For simplicity (e.g. below when limiting the number of revisions), order the list of page IDs
		asort( $pagesByID );
		$pageChunks = array_chunk( array_keys( $pagesByID ), 25 );
		$totalPageChunks = count( $pageChunks );

		$baseWhereConds = $this->getRevisionFilterConditions( $wikiID, $dbr );

		$batchSize = 2500;
		$scannedRevisionsPerPage = [];
		$revisions = [];
		$pageBatchIdx = 1;

		// Process the list of pages in smaller chunks, to avoid the optimizer making wrong decisions, and also to keep
		// the queries more readable.
		foreach ( $pageChunks as $batchPageIDs ) {
			$lastPage = 0;
			$lastTimestamp = null;
			$lastRevID = null;
			$innerBatchIdx = 1;
			do {
				$progressMsg = "Running $wikiIDStr batch #$pageBatchIdx.$innerBatchIdx of $totalPageChunks " .
					"from pageID=" . min( $batchPageIDs );
				if ( $lastTimestamp !== null && $lastRevID !== null ) {
					$progressMsg .= ", ts=$lastTimestamp, rev=$lastRevID";
				}
				( $this->debugLogger )( $progressMsg );

				$paginationConds = [
					'rev_page' => $lastPage
				];
				if ( $lastTimestamp && $lastRevID ) {
					$paginationConds = array_merge(
						$paginationConds,
						[
							'rev_timestamp' => $lastTimestamp,
							'rev_id' => $lastRevID
						]
					);
				}

				$revQueryBuilder = $revisionStore->newSelectQueryBuilder( $dbr );
				$res = $revQueryBuilder
					->field( 'actor_name' )
					// Needed for the user_is_temp check.
					->joinUser()
					->where( $baseWhereConds )
					->andWhere( [ 'rev_page' => $batchPageIDs ] )
					->andWhere( $dbr->buildComparison( '>', $paginationConds ) )
					->orderBy( [ 'rev_page', 'rev_timestamp', 'rev_id' ], SelectQueryBuilder::SORT_ASC )
					->limit( $batchSize )
					->caller( __METHOD__ )
					->fetchResultSet();

				$parents = [];
				foreach ( $res as $row ) {
					$parentID = (int)$row->rev_parent_id;
					if ( $parentID !== 0 ) {
						$parents[$row->rev_id] = $parentID;
					}
				}

				$parentSizes = $revisionStore->getRevisionSizes( array_values( $parents ) );

				foreach ( $res as $row ) {
					$pageID = (int)$row->rev_page;
					$parentID = $parents[$row->rev_id] ?? null;
					$parentSize = $parentID ? $parentSizes[$parentID] : 0;
					$revisions[] = [
						'username' => $row->actor_name,
						'userID' => $row->rev_user,
						'actorID' => $row->rev_actor,
						'page' => $pagesByID[$row->rev_page]->__toString(),
						'delta' => (int)$row->rev_len - $parentSize
					];
					$scannedRevisionsPerPage[$pageID] ??= 0;
					$scannedRevisionsPerPage[$pageID]++;
					$lastPage = $pageID;
					$lastTimestamp = $row->rev_timestamp;
					$lastRevID = (int)$row->rev_id;
				}

				if ( $scannedRevisionsPerPage[$lastPage] >= self::REVISIONS_PER_PAGE_LIMIT ) {
					// If we've already analyzed enough revisions for this page, move on to the next one.
					// Ideally we'd set a limit in the query above, but that seems difficult, especially considering
					// the limited subset of SQL we can use. So, just use this as an approximate limit. The ultimate
					// goal is to avoid choking on pages with lots of revisions, so the limit doesn't have to be exact.
					// Unsetting all the pagination conditions except for the page one makes us go straight to the
					// next page in the list.
					$lastTimestamp = null;
					$lastRevID = null;
				}
				$innerBatchIdx++;
				if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
					// Sleep after every batch to avoid putting too much load on DB servers with the revision queries.
					sleep( 1 );
				}
			} while ( $res->numRows() >= $batchSize );
			$pageBatchIdx++;
		}

		return $revisions;
	}

	/**
	 * Returns an array of filters to apply to the revision query.
	 *
	 * @param string|false $wikiID
	 * @param IReadableDatabase $dbr
	 * @return array<string|int,mixed>
	 */
	private function getRevisionFilterConditions( $wikiID, IReadableDatabase $dbr ): array {
		$filterConditions = [];

		// Exclude all sorts of deleted revisions to avoid any chance of data leaks.
		$filterConditions['rev_deleted'] = 0;

		// Exclude anons and temp users.
		$filterConditions[] = $dbr->expr( 'actor_user', '!=', null );
		$filterConditions['user_is_temp'] = 0;

		// Exclude anything too old.
		$startTime = (int)ConvertibleTimestamp::now( TS_UNIX ) - self::CUTOFF_DAYS * 24 * 60 * 60;
		$filterConditions[] = $dbr->expr( 'rev_timestamp', '>=', $dbr->timestamp( $startTime ) );

		// Exclude both edits that have been reverted, and edits that revert other edits. Neither of these is relevant,
		// and can easily skew the deltas.
		$changeTagDefStore = $this->nameTableStoreFactory->getChangeTagDef( $wikiID );
		$revertTagIDs = [];
		foreach ( [ ...ChangeTags::REVERT_TAGS, ChangeTags::TAG_REVERTED ] as $tagName ) {
			try {
				$revertTagIDs[] = $changeTagDefStore->getId( $tagName );
			} catch ( NameTableAccessException $e ) {
				// There's no tag ID if no revisions have ever been tagged with this tag.
			}
		}
		if ( $revertTagIDs ) {
			$tagSubquery = $dbr->newSelectQueryBuilder()
				->select( '1' )
				->from( 'change_tag' )
				->where( [ 'ct_rev_id = rev_id', 'ct_tag_id' => $revertTagIDs ] );
			$filterConditions[] = 'NOT EXISTS(' . $tagSubquery->getSQL() . ')';
		}

		// Exclude users who have a sitewide infinite block.
		$blocksSubquery = $dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'block' )
			->join( 'block_target', null, 'bt_id=bl_target' )
			->where( [
				$dbr->expr( 'bt_user', '!=', null ),
				'actor_rev_user.actor_user = bt_user',
				'bl_expiry' => $dbr->getInfinity(),
				'bl_sitewide' => 1,
			] );
		$filterConditions[] = 'NOT EXISTS(' . $blocksSubquery->getSQL() . ')';

		// Exclude bots. Note, this only checks whether a user is *currently* a bot, not whether
		// they were a bot at the time the edit was made.
		// XXX: Ideally we would use GroupPermissionLookup to list user groups with the 'bot' right, but that
		// only works for the local wiki.
		$botSubquery = $dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'user_groups' )
			->where( [
				'actor_rev_user.actor_user = ug_user',
				'ug_group' => 'bot',
			] );
		$filterConditions[] = 'NOT EXISTS(' . $botSubquery->getSQL() . ')';

		return $filterConditions;
	}

	/**
	 * This method takes a list of contributors along with the total number of bytes they added for each page, and
	 * returns a list of the same users, ranked by the likelihood of them being interested in the event.
	 *
	 * @param array[] $revisionsByWiki
	 * @phpcs:ignore Generic.Files.LineLength
	 * @phan-param array<string,list<array{username:string,userID:int,actorID:int,page:string,delta:int}>> $revisionsByWiki
	 * @return array<string,int> List of users along with their score, sorted from highest to lowest.
	 */
	private function rankUsers( array $revisionsByWiki ): array {
		// get unique usernames and remove those who have opted out of invitation lists
		$filteredRevisions = $this->filterUsersByPreference( $revisionsByWiki );
		$deltasByUser = $this->getDeltasByUser( $filteredRevisions );
		$userDataByWiki = $this->getUserDataByWiki( $filteredRevisions );
		( $this->debugLogger )( "==Scoring debug info==" );
		$rankedUsers = [];
		foreach ( $deltasByUser as $username => $byteDeltas ) {
			// Make sure the username is a string to satisfy the type hint. PHP will have transformed it to an integer
			// if the username is numeric (when used as array key).
			$score = $this->getUserScore( (string)$username, $byteDeltas, $userDataByWiki[$username] );
			if ( $score >= self::MIN_SCORE ) {
				$rankedUsers[$username] = $score;
			}
		}
		arsort( $rankedUsers );
		( $this->debugLogger )( "\n" );
		return $rankedUsers;
	}

	/**
	 * @param array[] $revisionsByWiki
	 * * @phpcs:ignore Generic.Files.LineLength
	 * * @phan-param array<string,list<array{username:string,userID:int,actorID:int,page:string,delta:int}>> $revisionsByWiki
	 * @return array<string,int[]> For each user, this contains a list of deltas in bytes across all relevant pages.
	 */
	private function getDeltasByUser( array $revisionsByWiki ): array {
		$listByUser = [];
		// Flatten the list, merging revisions from all wikis.
		$revisions = array_merge( ...array_values( $revisionsByWiki ) );
		foreach (
			$revisions as [ 'userID' => $userID, 'username' => $username, 'page' => $pageKey, 'delta' => $delta ]
		) {
				$listByUser[$username] ??= [];
				$listByUser[$username][$pageKey] ??= 0;
				$listByUser[$username][$pageKey] += $delta;
		}

		$deltas = [];
		( $this->debugLogger )( "==Contributions==" );
		foreach ( $listByUser as $user => $userDeltas ) {
			foreach ( $userDeltas as $pageKey => $delta ) {
				( $this->debugLogger )( "$user - $pageKey - $delta" );
			}
			// TODO: What should we do with negative totals? Large negative deltas do not necessarily indicate that a
			// user is not interested in the article. This problem is somewhat mitigated by the exclusion of reverts,
			// but there are still situations where a negative delta might be a good thing. For instance, if someone has
			// moved a section of the article to a separate page. In general, the byte count itself is far from being
			// perfect as a metric. For now, we're excluding negative deltas because some of the formulas below expect
			// the total delta to be positive.
			$positiveDeltas = array_filter( $userDeltas, static fn ( int $x ): bool => $x > 0 );
			if ( $positiveDeltas ) {
				$deltas[$user] = array_values( $positiveDeltas );
			}
		}
		( $this->debugLogger )( "\n" );
		return $deltas;
	}

	/**
	 * Returns user identifiers (name, ID, actor ID) for each contributor, for each wiki where they made edits to
	 * articles in the worklist. This can't just use UserIdentity because that doesn't include the actor ID, which we
	 * need for other queries later (particularly in getDaysSinceLastEdit()). Alternatively we could use
	 * ActorNormalization or a join on the user table, but both seem unnecessary (and potentially slow) when we already
	 * have the actor ID available.
	 *
	 * @param array[] $revisionsByWiki
	 * @phpcs:ignore Generic.Files.LineLength
	 * @phan-param array<string,list<array{username:string,userID:int,actorID:int,page:string,delta:int}>> $revisionsByWiki
	 * @return int[][][] Indexed by username first, then wiki ID.
	 * @phan-return array<string,array<string,array{userID:int,actorID:int}>>
	 */
	private function getUserDataByWiki( array $revisionsByWiki ): array {
		$userData = [];
		foreach ( $revisionsByWiki as $wiki => $revisions ) {
			foreach ( $revisions as [ 'username' => $username, 'userID' => $userID, 'actorID' => $actorID ] ) {
					$userData[$username][$wiki] = [ 'userID' => $userID, 'actorID' => $actorID ];
			}
		}
		return $userData;
	}

	/**
	 * Returns a score from 0 to 100 for a given user.
	 *
	 * @param string $username
	 * @param int[] $byteDeltas
	 * @param int[][] $userDataByWiki Map of [ wiki => [ userID: int, actorID: int ] ]
	 * @phan-param array<string,array{userID:int,actorID:int}> $userDataByWiki
	 * @return int
	 */
	private function getUserScore( string $username, array $byteDeltas, array $userDataByWiki ): int {
		// Idea: Maybe check how many edits each user has for each page, and handle each edit separately.
		// This would allow us to better handle outliers like single edits that add a lot of content.
		// Unsure how valuable that would be though, because huge edits can represent at least two different things:
		// 1 - Automated maintenance operation (e.g., adding archive links via IABot)
		// 2 - Substantial additions of content (for example, but not necessarily, upon page creation)
		// Our goal is to avoid 1, while catching 2, which might be difficult. Still, if a user has multiple edits for a
		// given page, it's more likely that they may have a genuine interest in the article subject, as opposed to them
		// performing some mass-maintenance operation that happened to touch a certain article.

		$bytesScore = $this->getOverallBytesScore( $username, $byteDeltas );
		$editCountScore = $this->getEditCountScore( $username, $userDataByWiki );
		$recentActivityScore = $this->getRecentActivityScore( $username, $userDataByWiki );

		// Once we have a (0, 1) score for each criterion, we combine them to obtain an overall score. This is currently
		// doing a weighted geometric mean. Amongst the advantages of the geometric mean is that it's conveniently
		// sensitive to small values. In practice, this means that even a single low score (around zero) will bring the
		// overall score down to around zero.
		$bytesWeight = 4;
		$editCountWeight = 1;
		$recentActivityWeight = 5;
		$overallScore = (
				( $bytesScore ** $bytesWeight ) *
				( $editCountScore ** $editCountWeight ) *
				( $recentActivityScore ** $recentActivityWeight )
			) ** ( 1 / ( $bytesWeight + $editCountWeight + $recentActivityWeight ) );
		return (int)round( 100 * $overallScore );
	}

	/**
	 * Returns a (0, 1) score based on the number and size of contributions that a single user made across all pages
	 * in the worklist.
	 *
	 * @param string $username
	 * @param int[] $deltas
	 * @return float
	 */
	private function getOverallBytesScore( string $username, array $deltas ): float {
		// This function computed a (0, 1) score for each page. Then, we get the maximum of those scores and "boost"
		// it by using the other scores. Let us indicate the overall scoring function with f(x), where x is a
		// k-dimentional vector. Let x_m be the component in x with the maximum value. We then have f(x) = x_m * b(x),
		// where b(x) is the boosting function, which outputs values in [ 1, 1 / x_m ].
		// f(x) satisfies the following conditions:
		// * f(x) ∈ [0, 1]
		// * f( x_1 ) = x_1 (single variable case)
		// * f( 0, ..., 0 ) = 0, f( 1, ..., 1 ) = 1
		// * f(x) >= x_m, where the equality holds true iff all components (at most with the exception of x_m) are 0,
		//   or x_m = 1
		// Note that the case x_m = 0 is defined separately to avoid annoyances with denominators.
		// The b(x) currently used is calculated by taking x_m and linearly amplifying it by a factor proportional to
		// the second largest component, then iterating the process for every component. This is very empirical, and
		// it would be better to scale the amplification based on the actual byte values, by establishing more rigorous
		// relationship to determine how much we shuld favour a given total delta being spread across multiple pages, as
		// opposed to being concentrated in a single page. Hopefully, this simple approach would suffice for now.
		// The two-dimensional version of the function can be visualised in https://www.desmos.com/3d/41a47c8129
		rsort( $deltas );
		$numPages = count( $deltas );
		$maxBytesScore = $this->getBytesScoreForPage( $deltas[0] );
		( $this->debugLogger )( "User $username max bytes $deltas[0] with score $maxBytesScore" );
		$bytesScore = $maxBytesScore;
		if ( $maxBytesScore !== 0.0 ) {
			for ( $i = 1; $i < $numPages; $i++ ) {
				$curBytesScore = $this->getBytesScoreForPage( $deltas[$i] );
				if ( $curBytesScore === 0.0 ) {
					// Scores from here on no longer have any effect.
					break;
				}
				( $this->debugLogger )( "User $username bytes score #$i: $curBytesScore" );
				$damping = 1;
				$bytesScore *= 1 + ( $curBytesScore ** $damping ) * ( 1 / $bytesScore - 1 );
			}
		}
		( $this->debugLogger )( "User $username overall bytes score $bytesScore" );
		return $bytesScore;
	}

	/**
	 * Returns a (0, 1) score based on the contributions made to a single page.
	 *
	 * @param int $delta
	 * @return float
	 */
	private function getBytesScoreForPage( int $delta ): float {
		// Because we use bytes as the main metric in determining the overall score, it's important that the score
		// function is as good as possible. This is a logistic-like model, but multiplied by a function that's really
		// flat near 0, which acts as a sort of high-pass filter.
		// The values for the two parameters have been computed numerically via gradient descent in order to minimize
		// the sum of squared residuals. Then, they've been approximated to more readable values. Both the original fit
		// and the rounded fit can be visualized in https://www.desmos.com/calculator/eu7u0kwkd6
		$scaledX = $delta / 1000;
		$baseScore = 2 / ( 1 + exp( -0.42 * ( $scaledX ** 1.1 ) ) ) - 1;
		$flatteningFactor = exp( -0.00001 / ( $scaledX ** 10 ) );
		return $baseScore * $flatteningFactor;
	}

	/**
	 * Returns a (0, 1) score based on the edit count of the given user.
	 *
	 * @param string $username
	 * @param int[][] $userDataByWiki Map of [ wiki => [ userID: int, actorID: int ] ]
	 * @phan-param array<string,array{userID:int,actorID:int}> $userDataByWiki
	 * @return float
	 */
	private function getEditCountScore( string $username, array $userDataByWiki ): float {
		$editCount = $this->getEditCount( $userDataByWiki );
		// This one uses the same base model as the bytes score, but with different parameters (approximated in the
		// same way). The graph can be visualised in https://www.desmos.com/calculator/4mhrtnhf4i
		$scaledEC = $editCount / 1000;
		$editCountScore = 2 / ( 1 + exp( -3 * ( $scaledEC ** 0.66 ) ) ) - 1;
		( $this->debugLogger )( "User $username edit count $editCount, score $editCountScore" );
		return $editCountScore;
	}

	/**
	 * @param int[][] $userDataByWiki Map of [ wiki => [ userID: int, actorID: int ] ]
	 * @phan-param array<string,array{userID:int,actorID:int}> $userDataByWiki
	 * @return int
	 */
	private function getEditCount( array $userDataByWiki ): int {
		// XXX: UserEditTracker is only available for the local wiki, and the global edit count is a CentralAuth thing
		$totalEditCount = 0;
		foreach ( $userDataByWiki as $wiki => [ 'userID' => $userID ] ) {
			$dbr = $this->dbProvider->getReplicaDatabase( $wiki );
			$curWikiEditCount = $dbr->newSelectQueryBuilder()
				->select( 'user_editcount' )
				->from( 'user' )
				->where( [ 'user_id' => $userID ] )
				->caller( __METHOD__ )
				->fetchField();
			if ( $curWikiEditCount !== null ) {
				$totalEditCount += (int)$curWikiEditCount;
			}
		}
		return $totalEditCount;
	}

	/**
	 * Returns a (0, 1) score based on the recent activity (edits) of the given user.
	 *
	 * @param string $username
	 * @param int[][] $userDataByWiki Map of [ wiki => [ userID: int, actorID: int ] ]
	 * @phan-param array<string,array{userID:int,actorID:int}> $userDataByWiki
	 * @return float
	 */
	private function getRecentActivityScore( string $username, array $userDataByWiki ): float {
		// This uses a rational function, so that it does not decay exponentially over time. See
		// https://www.desmos.com/calculator/vzhiigbxc9
		// Note: we already have a hard cutoff in the revision query (self::CUTOFF_DAYS), so anything before that won't
		// even be scored.
		$daysSinceLastEdit = $this->getDaysSinceLastEdit( $userDataByWiki );
		$xMonths = $daysSinceLastEdit / 30;
		// TODO: This may have to be scaled down. Think of what the overall score would be in function of the recent
		// activity score assuming that the other scores are all 1. For instance, with c=7 and d=0.5 to speed up decay,
		// and then reducing the recent activity weight.
		$recentActivityScore = ( $xMonths ** 2 + 1.2 * $xMonths + 0.7 ) /
			( 0.3 * ( $xMonths ** 3 ) + $xMonths ** 2 + 1.2 * $xMonths + 0.7 );
		( $this->debugLogger )( "User $username last edit $daysSinceLastEdit days ago, score $recentActivityScore" );
		return $recentActivityScore;
	}

	/**
	 * @param int[][] $userDataByWiki Map of [ wiki => [ userID: int, actorID: int ] ]
	 * @phan-param array<string,array{userID:int,actorID:int}> $userDataByWiki
	 * @return float
	 */
	private function getDaysSinceLastEdit( array $userDataByWiki ): float {
		// XXX: UserEditTracker is only available for the local wiki, so just use its query directly.
		$lastEditTS = 0;
		foreach ( $userDataByWiki as $wiki => [ 'actorID' => $actorID ] ) {
			$dbr = $this->dbProvider->getReplicaDatabase( $wiki );
			$curWikiTS = $dbr->newSelectQueryBuilder()
				->select( 'rev_timestamp' )
				->from( 'revision' )
				->where( [ 'rev_actor' => $actorID ] )
				->orderBy( 'rev_timestamp', SelectQueryBuilder::SORT_DESC )
				->caller( __METHOD__ )
				->fetchField();
			if ( $curWikiTS ) {
				$lastEditTS = max( $lastEditTS, (int)MWTimestamp::convert( TS_UNIX, $curWikiTS ) );
			}
		}
		if ( $lastEditTS === 0 ) {
			throw new RuntimeException( "No last edit from user who has edits?!" );
		}
		return ( MWTimestamp::time() - $lastEditTS ) / ( 60 * 60 * 24 );
	}

	/**
	 * @param array<string,list<array{username:string,userID:int,actorID:int,page:string,delta:int}>> $revisionsByWiki
	 * @return array<string,list<array{username:string,userID:int,actorID:int,page:string,delta:int}>>
	 */
	private function filterUsersByPreference( array $revisionsByWiki ): array {
		/** @param list<array{username:string,userID:int,actorID:int,page:string,delta:int}> &$subArray */
		array_walk( $revisionsByWiki, function ( array &$subArray ): void {
			/** @param array{username:string,userID:int,actorID:int,page:string,delta:int} $item */
			$subArray = array_filter( $subArray, function ( array $item ): bool {
				$user = UserIdentityValue::newRegistered( (int)$item['userID'], $item['username'] );
				return $this->userOptionsLookup->getBoolOption(
					$user,
					GetPreferencesHandler::ALLOW_INVITATIONS_PREFERENCE
				);
			} );
		} );
		return $revisionsByWiki;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Maintenance;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\WikiMap\WikiMap;

/**
 * This scripts takes a list of pages as input, and outputs a list of users who've edited those pages the most. Each
 * user is given a score from 0 to 100 based on how likely they are to be a productive participant in an event that is
 * focused on improving the given pages. This score is based on how many edits someone made (and how big they are) to
 * the pages in the list, their global edit count, and their recent global activity.
 * NOTE: This script is just a demo / proof of concept. It is not based on any real-world data and the calculations
 * are very much non-rigorous.
 */
class FindPotentialInvitees extends Maintenance {
	/**
	 * How many days to look back into the past when scanning revisions.
	 * TODO: Is 3 years OK?
	 */
	public const CUTOFF_DAYS = 3 * 365;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Generates a list of potential event participants by looking at who contributed to a given list of pages'
		);
		$this->requireExtension( 'CampaignEvents' );
		$this->addOption(
			'listfile',
			'Path to a file with a list of articles to get contributors for. The file should have one page per ' .
				'line, in the following format: `[wikiID, or empty for the local wiki]:[page title]`. All the pages ' .
				'must be in the mainspace.',
			true,
			true
		);
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): void {
		$finder = CampaignEventsServices::getPotentialInviteesFinder();
		$finder->setDebugLogger( function ( string $msg ): void {
			$this->output( $msg . "\n" );
		} );

		$pageNamesByWiki = $this->getArticlesByWiki();
		$this->output( "==Articles==\n" );
		foreach ( $pageNamesByWiki as $wiki => $pageNames ) {
			$this->output( "===$wiki===\n" . implode( "\n", $pageNames ) );
		}
		$this->output( "\n\n" );

		$worklistStatus = CampaignEventsServices::getWorklistParser()->parseWorklist( $pageNamesByWiki );
		if ( !$worklistStatus->isGood() ) {
			$this->fatalError( $worklistStatus );
		}
		$invitationList = $finder->generate( $worklistStatus->getValue() );
		$out = "\n==Contributor scores==\n";
		foreach ( $invitationList as $username => $score ) {
			$out .= "$username - $score\n";
		}
		$this->output( $out . "\n\n" );
	}

	/**
	 * Reads a list of articles from the file passed as `listfile` to the script.
	 *
	 * @return string[][] Map of [ wiki ID => non-empty list of articles ]
	 * @phan-return non-empty-array<string,non-empty-list<string>>
	 */
	private function getArticlesByWiki(): array {
		$listPath = $this->getOption( 'listfile' );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$rawList = @file_get_contents( $listPath );
		if ( $rawList === false ) {
			$this->fatalError( "Cannot read list of articles" );
		}

		$listLines = array_filter( explode( "\n", $rawList ), static fn ( string $line ): bool => $line !== '' );

		$curWikiID = WikiMap::getCurrentWikiId();
		$pageNamesByWiki = [];
		foreach ( $listLines as $line ) {
			$lineParts = explode( ':', $line, 2 );
			if ( count( $lineParts ) !== 2 ) {
				$this->fatalError( "Line without wiki ID: $line" );
			}
			$wikiID = $lineParts[0] === '' ? $curWikiID : $lineParts[0];
			$title = $lineParts[1];
			$pageNamesByWiki[$wikiID] ??= [];
			$pageNamesByWiki[$wikiID][] = $title;
		}

		return $pageNamesByWiki;
	}
}

return FindPotentialInvitees::class;

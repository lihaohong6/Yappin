<?php

namespace MediaWiki\Extension\Yappin\Maintenance;

use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Extension\Yappin\Models\Comment;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Regenerates the stored HTML of every comment from its wikitext.
 *
 * This must be run after upgrading past the fix for GHSA-5p42-2g3g-pgmq: comments posted
 * before the fix had their HTML supplied by the client rather than produced by the parser,
 * so any HTML already in the database has to be considered untrusted and regenerated.
 *
 * It is also useful after any change to the way comments are parsed.
 */
class ReparseComments extends Maintenance {

	private CommentFactory $commentFactory;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Regenerate the stored HTML of comments from their wikitext. Run this after upgrading ' .
			'to a version that fixes the stored HTML injection vulnerability, or after a change ' .
			'to the way comments are parsed.'
		);
		$this->addOption( 'from', 'Reparse comments from this ID onwards', false, true );
		$this->addOption( 'to', 'Reparse comments up to and including this ID', false, true );
		$this->addOption( 'dry-run', 'Report what would be done without writing to the database' );
		$this->setBatchSize( 100 );
		$this->requireExtension( 'Yappin' );
	}

	public function execute(): void {
		$this->commentFactory = $this->getServiceContainer()->getService( 'Yappin.CommentFactory' );

		$from = (int)$this->getOption( 'from', 0 );
		$to = (int)$this->getOption( 'to', 0 );
		$dryRun = $this->hasOption( 'dry-run' );

		if ( $to && $from > $to ) {
			$this->fatalError( "The 'from' option must not be greater than the 'to' option." );
		}

		$dbr = $this->getReplicaDB();
		$dbw = $this->getPrimaryDB();

		$lastId = $from - 1;
		$done = 0;
		$skipped = 0;

		while ( true ) {
			$query = $dbr->newSelectQueryBuilder()
				->select( '*' )
				->from( Comment::TABLE_NAME )
				->where( $dbr->expr( 'yap_id', '>', $lastId ) )
				->orderBy( 'yap_id', SelectQueryBuilder::SORT_ASC )
				->limit( $this->getBatchSize() )
				->caller( __METHOD__ );

			if ( $to ) {
				$query->andWhere( $dbr->expr( 'yap_id', '<=', $to ) );
			}

			$rows = $query->fetchResultSet();
			if ( !$rows->numRows() ) {
				break;
			}

			foreach ( $rows as $row ) {
				$lastId = (int)$row->yap_id;
				$comment = $this->commentFactory->newFromRow( $row );

				if ( $comment->getWikitext() === '' ) {
					// No wikitext to parse. The stored HTML can't be trusted either, so drop it
					// rather than leaving it in place.
					if ( $row->yap_html !== '' && $row->yap_html !== null ) {
						$this->output( "Comment $lastId has no wikitext; clearing its HTML.\n" );
						if ( !$dryRun ) {
							$dbw->newUpdateQueryBuilder()
								->update( Comment::TABLE_NAME )
								->set( [ 'yap_html' => '' ] )
								->where( [ 'yap_id' => $lastId, 'yap_wikitext' => $row->yap_wikitext ] )
								->caller( __METHOD__ )
								->execute();
						}
						$done++;
					} else {
						$skipped++;
					}
					continue;
				}

				if ( !$comment->getTitle() ) {
					$this->output( "Comment $lastId is attached to missing page {$row->yap_page}; skipping.\n" );
					$skipped++;
					continue;
				}

				$comment->reparse( false );
				if ( !$dryRun ) {
					$dbw->newUpdateQueryBuilder()
						->update( Comment::TABLE_NAME )
						->set( [ 'yap_html' => $comment->getHtml() ] )
						// In case the comment is edited during maintenance script run
						->where( [ 'yap_id' => $lastId, 'yap_wikitext' => $row->yap_wikitext ] )
						->caller( __METHOD__ )
						->execute();
				}
				$done++;
			}

			$this->output( "Reparsed up to comment $lastId ($done done, $skipped skipped)\n" );

			if ( !$dryRun ) {
				$this->commitTransactionRound( __METHOD__ );
			}
		}

		$verb = $dryRun ? 'Would have reparsed' : 'Reparsed';
		$this->output( "$verb $done comment(s), skipped $skipped.\n" );
	}
}

$maintClass = ReparseComments::class;

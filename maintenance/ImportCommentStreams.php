<?php

namespace MediaWiki\Extension\Yappin\Maintenance;

use MediaWiki\Content\TextContent;
use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use stdClass;
use Wikimedia\Rdbms\SelectQueryBuilder;

class ImportCommentStreams extends Maintenance {

	private CommentFactory $commentFactory;
	private RevisionLookup $revisionLookup;
	private TitleFactory $titleFactory;
	private UserFactory $userFactory;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import comments from the CommentStreams extension into Yappin.' );
		$this->addOption( 'force', 'Skip the duplicate-comment guard and the non-empty table check.', false, false, 'f' );
		$this->requireExtension( 'Yappin' );
		$this->requireExtension( 'CommentStreams' );
	}

	public function execute(): void {
		$force = $this->hasOption( 'force' );
		$services = $this->getServiceContainer();

		$this->commentFactory = $services->getService( 'Yappin.CommentFactory' );
		$this->revisionLookup = $services->getRevisionLookup();
		$this->titleFactory = $services->getTitleFactory();
		$this->userFactory = $services->getUserFactory();

		$dbr = $this->getDB( DB_REPLICA );

		// Build a set of already-imported comments (page, timestamp) pairs for deduplication.
		// We assume that if two comments share the same timestamp on the same page, they are likely the same.
		$existingKeys = [];
		if ( !$force ) {
			$existingRows = $dbr->newSelectQueryBuilder()
								->select( [ 'c_page', 'c_timestamp' ] )
								->from( 'com_comment' )
								->caller( __METHOD__ )
								->fetchResultSet();
			foreach ( $existingRows as $er ) {
				$existingKeys[$er->c_page . ':' . $er->c_timestamp] = true;
			}
		}

		// ----------------------------------------------------------------
		// Phase 1: import top-level comments
		// ----------------------------------------------------------------
		$this->output( "Phase 1: importing top-level comments...\n" );

		$commentRows = $dbr->newSelectQueryBuilder()
						   ->select( [
							   'page_title',
							   'page_namespace',
							   'page_id',
							   'cst_c_comment_page_id',
							   'cst_c_assoc_page_id',
							   'cst_c_comment_title'
						   ] )
						   ->from( 'cs_comments' )
						   ->join( 'page', null, 'cst_c_comment_page_id = page_id' )
						   ->orderBy( 'cst_c_comment_page_id', SelectQueryBuilder::SORT_ASC )
						   ->caller( __METHOD__ )
						   ->fetchResultSet();

		/** Maps CS comment page_id → newly created Yappin comment id */
		$csToYappin = [];
		$importedComments = 0;
		$skippedComments = 0;

		foreach ( $commentRows as $row ) {
			$csPageId = (int)$row->cst_c_comment_page_id;
			$assocPageId = (int)$row->cst_c_assoc_page_id;
			$commentTitle = $row->cst_c_comment_title ?? '';

			$result = $this->importEntity(
				$row,
				$commentTitle,
				$assocPageId,
				null, // no parent
				null,
				$force,
				$existingKeys
			);

			if ( $result === null ) {
				$skippedComments++;
			} else {
				$csToYappin[$csPageId] = $result;
				$importedComments++;
			}
		}

		$this->output( "  Imported: $importedComments  Skipped: $skippedComments\n\n" );

		// ----------------------------------------------------------------
		// Phase 2: import replies
		// ----------------------------------------------------------------
		$this->output( "Phase 2: importing replies...\n" );

		$replyRows = $dbr->newSelectQueryBuilder()
						 ->select( [
							 'page_title',
							 'page_namespace',
							 'page_id',
							 'cst_r_reply_page_id',
							 'cst_r_comment_page_id'
						 ] )
						 ->from( 'cs_replies' )
						 ->join( 'page', null, 'cst_r_reply_page_id = page_id' )
						 ->orderBy( 'cst_r_reply_page_id', SelectQueryBuilder::SORT_ASC )
						 ->caller( __METHOD__ )
						 ->fetchResultSet();

		$importedReplies = 0;
		$skippedReplies = 0;

		foreach ( $replyRows as $row ) {
			$csParentPageId = (int)$row->cst_r_comment_page_id;

			if ( !isset( $csToYappin[$csParentPageId] ) ) {
				$this->output(
					"  SKIP reply page_id={$row->cst_r_reply_page_id}: "
					. "parent CS page $csParentPageId was not imported\n"
				);
				$skippedReplies++;
				continue;
			}

			$parentYappinId = $csToYappin[$csParentPageId];

			// Resolve the associated wiki page from the parent Yappin comment.
			$parentComment = $this->commentFactory->newFromId( $parentYappinId );
			$assocPageId = $parentComment->mPageId;

			$result = $this->importEntity(
				$row,
				null, // replies have no comment-title annotation to strip
				$assocPageId,
				$parentYappinId,
				$row->cst_r_reply_page_id,
				$force,
				$existingKeys
			);

			if ( $result === null ) {
				$skippedReplies++;
			} else {
				$importedReplies++;
			}
		}

		$this->output( "  Imported: $importedReplies  Skipped: $skippedReplies\n\n" );

		// ----------------------------------------------------------------
		// Phase 3: import votes
		// ----------------------------------------------------------------
		$this->output( "Phase 3: importing votes...\n" );

		$voteRows = $dbr->newSelectQueryBuilder()
						->select( [ 'cst_v_comment_id', 'actor_id', 'cst_v_vote' ] )
						->from( 'cs_votes' )
						->join( 'actor', null, 'actor_user = cst_v_user_id' )
						->where( $dbr->expr( 'cst_v_vote', '!=', 0 ) )
						->orderBy( 'cst_v_comment_id', SelectQueryBuilder::SORT_ASC )
						->caller( __METHOD__ )
						->fetchResultSet();

		$importedVotes = 0;
		$skippedVotes = 0;
		$voteRowsToInsert = [];
		$affectedYappinIds = [];

		foreach ( $voteRows as $voteRow ) {
			$csCommentId = (int)$voteRow->cst_v_comment_id;

			if ( !isset( $csToYappin[$csCommentId] ) ) {
				$skippedVotes++;
				continue;
			}

			$yappinId = $csToYappin[$csCommentId];

			$voteRowsToInsert[] = [
				'cr_comment' => $yappinId,
				'cr_actor'   => (int)$voteRow->actor_id,
				'cr_rating'  => (int)$voteRow->cst_v_vote,
			];
			$affectedYappinIds[] = $yappinId;
			$importedVotes++;
		}

		if ( $voteRowsToInsert ) {
			$dbw = $this->getPrimaryDB();

			$dbw->newInsertQueryBuilder()
				->insertInto( 'com_rating' )
				->rows( $voteRowsToInsert )
				->caller( __METHOD__ )
				->execute();

			foreach ( array_unique( $affectedYappinIds ) as $yappinId ) {
				$sum = (int)$dbw->newSelectQueryBuilder()
								->select( 'SUM(cr_rating)' )
								->from( 'com_rating' )
								->where( [ 'cr_comment' => $yappinId ] )
								->caller( __METHOD__ )
								->fetchField();
				$dbw->newUpdateQueryBuilder()
					->update( 'com_comment' )
					->set( [ 'c_rating' => $sum ] )
					->where( [ 'c_id' => $yappinId ] )
					->caller( __METHOD__ )
					->execute();
			}
		}

		$this->output( "  Imported: $importedVotes  Skipped: $skippedVotes\n\n" );

		$this->output(
			sprintf(
				"Done. Imported %d comment(s), %d replies, and %d vote(s) (%d total skipped).\n",
				$importedComments,
				$importedReplies,
				$importedVotes,
				$skippedComments + $skippedReplies + $skippedVotes
			)
		);
	}

	/**
	 * Extract data from a CommentStreams wiki page and insert a Yappin comment row.
	 *
	 * @param stdClass $pageRow DB row with page_title/page_namespace/page_id columns
	 * @param string|null $commentTitle The cst_c_comment_title value (top-level comments only)
	 * @param int $assocPageId page_id of the wiki page the comment belongs to
	 * @param int|null $parentYappinId Yappin comment id of the parent (replies only)
	 * @param int|null $logPageId page_id used in skip messages
	 * @return int|null  Yappin comment id on success, null on skip
	 */
	private function importEntity(
		stdClass $pageRow,
		?string $commentTitle,
		int $assocPageId,
		?int $parentYappinId,
		?int $logPageId,
		bool $force,
		array $existingKeys
	): ?int {
		$csPageId = (int)$pageRow->page_id;
		$logId = $logPageId ?? $csPageId;
		$entityLabel = $parentYappinId !== null ? 'reply' : 'comment';

		$title = $this->titleFactory->newFromRow( $pageRow );

		$firstRev = $this->revisionLookup->getFirstRevision( $title );
		if ( !$firstRev ) {
			$this->output( "  SKIP $entityLabel page_id=$logId: no revisions found\n" );
			return null;
		}

		$latestRev = $this->revisionLookup->getRevisionByTitle( $title );
		if ( !$latestRev ) {
			$this->output( "  SKIP $entityLabel page_id=$logId: latest revision missing\n" );
			return null;
		}

		$content = $latestRev->getContent( SlotRecord::MAIN );
		if ( !( $content instanceof TextContent ) ) {
			$this->output( "  SKIP $entityLabel page_id=$logId: content is not TextContent\n" );
			return null;
		}

		$wikitext = $this->removeAnnotations( $content->getText(), $commentTitle ?? '' );
		if ( trim( $wikitext ) === '' ) {
			$this->output( "  SKIP $entityLabel page_id=$logId: wikitext is empty after stripping annotations\n" );
			return null;
		}

		$author = $this->userFactory->newFromUserIdentity( $firstRev->getUser() );
		$createdTs = wfTimestamp( TS_ISO_8601, $firstRev->getTimestamp() );

		$dbr = $this->getDB( DB_REPLICA );
		if ( !$force && isset( $existingKeys[$assocPageId . ':' . $dbr->timestamp( $createdTs )] ) ) {
			$this->output( "  SKIP $entityLabel page_id=$logId: already imported (page $assocPageId, ts $createdTs)\n" );
			return null;
		}

		$yappin = $this->commentFactory->newEmpty();
		$yappin->mPageId = $assocPageId;
		$yappin->mParentId = $parentYappinId;
		$yappin->mCreatedTimestamp = $createdTs;
		$yappin->setActor( $author );
		$yappin->setWikitext( $wikitext, true );

		$id = $yappin->save( false );

		if ( $id === null ) {
			$this->output( "  ERROR: failed to save $entityLabel page_id=$logId\n" );
			return null;
		}

		return $id;
	}

	/**
	 * Strip the {{DISPLAYTITLE:…}} annotation that CommentStreams injects into
	 * top-level comment pages to display the comment title.
	 *
	 * Borrowed from CommentStreams' NamespacePageStore / migrateToTalkPageStorage.php.
	 *
	 * @param string $wikitext
	 * @param string $commentTitle
	 * @return string
	 */
	private function removeAnnotations( string $wikitext, string $commentTitle ): string {
		if ( $commentTitle === '' ) {
			return $wikitext;
		}
		$strip = "{{DISPLAYTITLE:\n$commentTitle\n}}";
		return str_replace( $strip, '', $wikitext );
	}
}

$maintClass = ImportCommentStreams::class;

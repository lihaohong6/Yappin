<?php

namespace MediaWiki\Extension\Yappin;

use InvalidArgumentException;
use MediaWiki\Extension\Yappin\Models\Comment;
use MediaWiki\User\UserIdentity;
use stdClass;
use Wikimedia\Rdbms\LBFactory;

class CommentFactory {
	private LBFactory $lbFactory;

	public function __construct(
		LBFactory $lbFactory
	) {
		$this->lbFactory = $lbFactory;
	}

	/**
	 * Create a new empty Comment object
	 * @return Comment
	 */
	public function newEmpty() {
		return new Comment();
	}

	/**
	 * Create a new Comment object from a database row
	 * @param stdClass $row
	 * @param UserIdentity|null $user optional, instantiates the comment object with a UserIdentity object
	 * @return Comment
	 */
	public function newFromRow( $row, $user = null ) {
		$comment = new Comment();
		$comment->mId = (int)$row->yap_id;
		$comment->mPageId = (int)$row->yap_page;

		if ( $user !== null && $user->getId() !== 0 ) {
			$comment->setActor( $user, (int)$row->yap_actor );
		} else {
			$comment->mActorId = (int)$row->yap_actor;
		}

		$parentId = (int)$row->yap_parent;
		if ( !empty( $parentId ) ) {
			$comment->mParentId = $parentId;
		}

		$comment->mCreatedTimestamp = wfTimestamp( TS_MW, $row->yap_timestamp );
		$comment->mEditedTimestamp = wfTimestampOrNull( TS_MW, $row->yap_edited_timestamp );

		$comment->mDeletedActorId = $row->yap_deleted_actor;
		$comment->mWikitext = (string)$row->yap_wikitext;
		$comment->mHtml = (string)$row->yap_html;
		$comment->mRating = (int)$row->yap_rating;

		return $comment;
	}

	/**
	 * Create a new Comment object from a given ID. The ID should already exist in the database.
	 * @param int $id
	 * @return Comment
	 * @throws InvalidArgumentException
	 */
	public function newFromId( $id ) {
		$db = $this->lbFactory->getPrimaryDatabase();
		$row = $db->newSelectQueryBuilder()
			->fields( '*' )
			->from( 'yappin_comment' )
			->where( [ 'yap_id' => $id ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			throw new InvalidArgumentException( "No comment found with ID: $id" );
		}

		return $this->newFromRow( $row );
	}
}

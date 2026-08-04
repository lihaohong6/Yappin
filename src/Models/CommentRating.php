<?php

namespace MediaWiki\Extension\Yappin\Models;

use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserIdentity;
use stdClass;
use Wikimedia\Rdbms\LBFactory;

class CommentRating {
	/** @var int */
	public $mActorId;

	/** @var int */
	public $mCommentId;

	/** @var Comment */
	private $mComment;

	/** @var int -1, 0, or 1 */
	public $mRating;

	public function __construct(
		private readonly ActorStore $actorStore,
		private readonly LBFactory $lbFactory,
		private readonly CommentFactory $commentFactory
	) {
	}

	/**
	 * Create a CommentRating object from a database row
	 *
	 * @param stdClass $row
	 * @return self
	 */
	public static function newFromRow(
		stdClass $row,
		ActorStore $actorStore,
		LBFactory $lbFactory,
		CommentFactory $commentFactory
	) {
		$obj = new self( $actorStore, $lbFactory, $commentFactory );
		$obj->mCommentId = (int)$row->cr_comment;
		$obj->mActorId = (int)$row->cr_actor;
		$obj->mRating = (int)$row->cr_rating;

		return $obj;
	}

	/**
	 * Returns a CommentRating object for a specific user's rating on a comment. If the user has never interacted with
	 * the rating on a comment, then this will return null.
	 *
	 * @param Comment|int $comment either a Comment object or an integer representing the comment ID
	 * @param UserIdentity|int $actor either a UserIdentity object or an integer representing the actor ID of the user
	 * @param ActorStore $actorStore
	 * @param LBFactory $lbFactory
	 * @param CommentFactory $commentFactory
	 * @return self|null
	 */
	public static function fetchByCommentAndUser(
		Comment|int $comment,
		UserIdentity|int $actor,
		ActorStore $actorStore,
		LBFactory $lbFactory,
		CommentFactory $commentFactory
	) {
		if ( $comment instanceof Comment ) {
			$comment = $comment->getId();
		}

		$dbr = $lbFactory->getReplicaDatabase();

		if ( $actor instanceof UserIdentity ) {
			$actor = $actorStore->findActorId( $actor, $dbr );
		}

		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'com_rating' )
			->where( [ 'cr_comment' => $comment, 'cr_actor' => $actor ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? self::newFromRow( $row, $actorStore, $lbFactory, $commentFactory ) : null;
	}

	/**
	 * The Comment object that this rating is for
	 * @return Comment
	 */
	public function getComment() {
		if ( $this->mComment === null ) {
			$this->mComment = $this->commentFactory->newFromId( $this->mCommentId );
		}

		return $this->mComment;
	}

	/**
	 * ID of the actor
	 * @return int
	 */
	public function getActorId() {
		return $this->mActorId;
	}

	/**
	 * Rating (-1, 0, or 1)
	 * @return int
	 */
	public function getRating() {
		return $this->mRating;
	}

	/**
	 * Sets the actor for this comment rating.
	 *
	 * This method returns the current CommentRating object for easier chaining.
	 * @param UserIdentity|int $actor
	 * @return self
	 */
	public function setActor( $actor ) {
		if ( $actor instanceof UserIdentity ) {
			$actor = $this->actorStore->acquireActorId( $actor, $this->lbFactory->getPrimaryDatabase() );
		}

		$this->mActorId = $actor;
		return $this;
	}

	/**
	 * Sets the user's rating of the comment (-1, 0, or 1)
	 *
	 * This method returns the current CommentRating object for easier chaining.
	 * @param int $rating
	 * @return self
	 */
	public function setRating( $rating ) {
		$this->mRating = $rating;
		return $this;
	}

	/**
	 * Sets the comment that this rating relates to.
	 *
	 * This method returns the current CommentRating object for easier chaining.
	 * @param Comment $comment
	 * @return self
	 */
	public function setComment( $comment ) {
		$this->mComment = $comment;
		$this->mCommentId = $comment->getId();
		return $this;
	}

	/**
	 * Saves this object to the database, overwriting the existing object if necessary.
	 * @return void
	 */
	public function save() {
		$prev = $this->lbFactory->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( 'cr_rating' )
			->table( 'com_rating' )
			->where( [ 'cr_actor' => $this->mActorId, 'cr_comment' => $this->mCommentId ] )
			->caller( __METHOD__ )->fetchField();

		$row = [
			'cr_comment' => $this->mCommentId,
			'cr_actor' => $this->mActorId,
			'cr_rating' => $this->mRating
		];

		$this->lbFactory->getPrimaryDatabase()->newInsertQueryBuilder()
			->insertInto( 'com_rating' )
			->row( $row )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'cr_comment', 'cr_actor' ] )
			->set( [ 'cr_rating' => $this->mRating ] )
			->caller( __METHOD__ )
			->execute();

		$comment = $this->getComment();
		if ( !$prev ) {
			// User had not rated this comment before
			if ( $this->mRating === -1 ) {
				$comment->decrementRatingCount();
			} elseif ( $this->mRating === 1 ) {
				$comment->incrementRatingCount();
			}
		} elseif ( (int)$prev !== $this->mRating ) {
			// Rating is different to what the previous value was for this user
			$diff = abs( $prev - $this->mRating );
			if ( $this->mRating < $prev ) {
				$comment->decrementRatingCount( $diff );
			} else {
				$comment->incrementRatingCount( $diff );
			}
		}
	}
}

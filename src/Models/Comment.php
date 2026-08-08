<?php

namespace MediaWiki\Extension\Yappin\Models;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\Parsoid\HtmlTransformFactory;
use MediaWiki\Parser\Parsoid\ParsoidParserFactory;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use Telepedia\UserProfileV2\Avatar\UserProfileV2Avatar;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;

class Comment {
	public const TABLE_NAME = 'yappin_comment';

	/** @var int|null */
	public $mId = null;

	/** @var Title */
	private $mTitle;

	/** @var int */
	public $mPageId;

	/** @var UserIdentity */
	private $mActor;

	/** @var int */
	public $mActorId;

	/** @var string|null */
	public $mCreatedTimestamp = null;

	/** @var string|null */
	public $mEditedTimestamp = null;

	/** @var Comment|null */
	private $mParent = null;

	/** @var int */
	public $mParentId;

	/** @var UserIdentity */
	public $mDeletedActor = null;

	/** @var int */
	public $mDeletedActorId = null;

	/** @var int */
	public $mRating = 0;

	/** @var string */
	public $mHtml;

	/** @var string */
	public $mWikitext;

	/** @var IDatabase */
	private $dbw;

	/** @var IDatabase */
	private $dbr;

	/**
	 * Comment objects should be obtained from CommentFactory, which supplies these services.
	 *
	 * @internal
	 */
	public function __construct(
		private readonly LBFactory $lbFactory,
		private readonly ActorStore $actorStore,
		private readonly CommentFactory $commentFactory,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
		private readonly ParsoidParserFactory $parserFactory,
		private readonly HtmlTransformFactory $htmlTransformFactory,
		private readonly Config $config,
		private readonly UserIdentityUtils $userIdentityUtils
	) {
		$this->dbw = $this->lbFactory->getPrimaryDatabase();
		$this->dbr = $this->lbFactory->getReplicaDatabase();
	}

	/**
	 * The ID of this comment
	 *
	 * @return int
	 */
	public function getId() {
		return $this->mId;
	}

	/**
	 * The wiki page the comment was posted on
	 *
	 * @return Title
	 */
	public function getTitle() {
		if ( $this->mTitle !== null ) {
			return $this->mTitle;
		}

		$this->mTitle = $this->titleFactory->newFromID( $this->mPageId );
		return $this->mTitle;
	}

	/**
	 * Sets the Title (wiki page) that this comment has been posted on
	 *
	 * This method returns the current Comment object for easier chaining.
	 * @param Title $title
	 * @return Comment
	 */
	public function setTitle( $title ) {
		$this->mTitle = $title;
		$this->mPageId = $this->mTitle->getId();
		return $this;
	}

	/**
	 * The actor who posted the comment
	 *
	 * @return UserIdentity
	 */
	public function getActor(): UserIdentity {
		if ( $this->mActor ) {
			return $this->mActor;
		}

		$this->mActor = $this->actorStore->getActorById( $this->mActorId, $this->dbr );

		return $this->mActor;
	}

	/**
	 * Sets the actor who posted this comment
	 *
	 * This method returns the current Comment object for easier chaining.
	 *
	 * @param UserIdentity $user
	 * @param int|null $actorId
	 *
	 * @return Comment
	 */
	public function setActor( $user, $actorId = null ) {
		$this->mActor = $user;
		$this->mActorId = $actorId ?? $this->actorStore->acquireActorId( $user, $this->dbw );

		return $this;
	}

	/**
	 * The comment that is being replied to
	 * @return Comment|null
	 */
	public function getParent() {
		if ( !$this->mParent && $this->mParentId ) {
			$this->mParent = $this->commentFactory->newFromId( $this->mParentId );
		}

		return $this->mParent;
	}

	/**
	 * Sets the parent comment of this comment.
	 *
	 * A comment can only have one parent, and comments can only be nested
	 * one level deep. Once set, a comment's parent should *not* be mutated.
	 *
	 * This method returns the current Comment object for easier chaining.
	 * @param Comment|null $commentOrNull
	 * @return Comment
	 */
	public function setParent( $commentOrNull ) {
		$this->mParent = $commentOrNull;
		$this->mParentId = $commentOrNull ? $commentOrNull->getId() : null;
		return $this;
	}

	/**
	 * Was this comment deleted?
	 * @return bool
	 */
	public function isDeleted() {
		return $this->mDeletedActorId !== null;
	}

	/**
	 * The actor who deleted the comment
	 * @return UserIdentity|null
	 */
	public function getDeletedActor() {
		if ( $this->mDeletedActorId === null || $this->mDeletedActor !== null ) {
			return $this->mDeletedActor;
		}

		$this->mDeletedActor = $this->actorStore->getActorById( $this->mDeletedActorId, $this->dbr );
		return $this->mDeletedActor;
	}

	/**
	 * Sets the user who deleted this comment.
	 *
	 * This method returns the current Comment object for easier chaining.
	 * @param UserIdentity|null $user
	 * @return Comment
	 */
	public function setDeletedActor( $user ) {
		$this->mDeletedActor = $user;
		$this->mDeletedActorId = $user ? $this->actorStore->acquireActorId( $user, $this->dbw ) : null;
		return $this;
	}

	/**
	 * The parsed HTML for the comment
	 * @return string
	 */
	public function getHtml() {
		return $this->mHtml;
	}

	/**
	 * Sets the HTML for this comment.
	 *
	 * The HTML passed to this method is treated as untrusted: when $parse is true it is only
	 * used to derive the wikitext, and the stored HTML is then re-generated from that wikitext
	 * by the parser. Callers must not rely on the exact HTML they passed in being kept.
	 *
	 * This method returns the current Comment object for easier chaining.
	 * @param string $html
	 * @param bool $parse Whether to reparse the HTML into wikitext
	 * @return Comment
	 */
	public function setHtml( $html, $parse = true ) {
		$this->mHtml = $html;
		if ( $parse === true ) {
			// Convert the supplied HTML to wikitext...
			$this->reparse( true );
			// ...and then throw that HTML away and parse the wikitext back into HTML, so that
			// the stored HTML always comes from the parser (which sanitises it) rather than
			// from the client. Otherwise a crafted API request can store arbitrary HTML.
			if ( ( $this->mWikitext ?? '' ) !== '' ) {
				$this->reparse( false );
			} else {
				// The HTML carried no content; don't leave the unparsed HTML behind.
				$this->mHtml = '';
			}
		}
		return $this;
	}

	/**
	 * The wikitext for the comment, used to populate the textarea when editing the comment.
	 * This field is not used to render the comment, use `Comment::getHtml` instead.
	 * @return string
	 */
	public function getWikitext() {
		return $this->mWikitext;
	}

	/**
	 * Sets the wikitext for this comment, and triggers a parse of it if necessary.
	 *
	 * This method returns the current Comment object for easier chaining.
	 * @param string $text
	 * @param bool $parse
	 * @return Comment
	 */
	public function setWikitext( $text, $parse = true ) {
		$this->mWikitext = $text;
		if ( $parse === true ) {
			$this->reparse( false );
		}
		return $this;
	}

	/**
	 * The timestamp for the comment
	 * @return string
	 */
	public function getTimestamp() {
		return $this->mCreatedTimestamp;
	}

	/**
	 * The edited timestamp for the comment
	 * @return string
	 */
	public function getEditedTimestamp() {
		return $this->mEditedTimestamp;
	}

	/**
	 * The overall rating for the comment.
	 *
	 * This is not necessarily equivalent to a SUM() of all CommentRating objects
	 * associated with this comment, and is instead used as a quick lookup,
	 * similarly to `user_editcount` in MediaWiki core.
	 *
	 * @return int
	 */
	public function getRating() {
		return $this->mRating;
	}

	/**
	 * Gets the CommentRating object for a specific user. If the user has not rated this comment, then this method will
	 * return null.
	 *
	 * @param UserIdentity $user
	 * @return CommentRating
	 */
	public function getRatingForUser( $user ) {
		return CommentRating::fetchByCommentAndUser(
			$this->mId, $user, $this->lbFactory, $this->actorStore, $this->commentFactory
		);
	}

	/**
	 * Sets a rating for a particular user.
	 *
	 * @param UserIdentity $user
	 * @param int $rating an integer matching `-1`, `0`, or `1`
	 * @return CommentRating
	 */
	public function setRatingForUser( $user, $rating ) {
		$obj = $this->commentFactory->newEmptyRating();
		$obj->setComment( $this )
			->setActor( $user )
			->setRating( $rating )
			->save();

		return $obj;
	}

	/**
	 * Increments the current rating count for the comment. This method will update the increment the current live
	 * value in the database, reloading this Comment object with the updated value.
	 *
	 * This method should ONLY be called on comments that already exist in the database.
	 * @param int $amount
	 * @return void
	 */
	public function incrementRatingCount( $amount = 1 ) {
		$this->dbw->newUpdateQueryBuilder()
			->table( $this::TABLE_NAME )
			->set( [ 'yap_rating=yap_rating+' . $amount ] )
			->where( [ 'yap_id' => $this->mId ] )
			->caller( __METHOD__ )->execute();

		$this->mRating = (int)$this->dbw->newSelectQueryBuilder()
			->select( 'yap_rating' )
			->table( $this::TABLE_NAME )
			->where( [ 'yap_id' => $this->mId ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Decrement the current rating count for the comment. This method will update the decrement the current live
	 * value in the database, reloading this Comment object with the updated value.
	 *
	 * This method should ONLY be called on comments that already exist in the database.
	 * @param int $amount
	 * @return void
	 */
	public function decrementRatingCount( $amount = 1 ) {
		$this->dbw->newUpdateQueryBuilder()
			->table( $this::TABLE_NAME )
			->set( [ 'yap_rating=yap_rating-' . $amount ] )
			->where( [ 'yap_id' => $this->mId ] )
			->caller( __METHOD__ )->execute();

		$this->mRating = (int)$this->dbw->newSelectQueryBuilder()
			->select( 'yap_rating' )
			->table( $this::TABLE_NAME )
			->where( [ 'yap_id' => $this->mId ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Sets the rating for this comment. This should not typically be called manually.
	 *
	 * This method returns the current Comment object for easier chaining.
	 *
	 * @param number $rating
	 * @return $this
	 */
	public function setRating( $rating ) {
		$this->mRating = $rating;
		return $this;
	}

	/**
	 * Parse the wikitext and sets the output as appropriate. For convenience, this method also returns the output.
	 *
	 * This method should typically only be called once when the comment is changed. Re-parsing the comment
	 * on every page view is expensive and unnecessary.
	 *
	 * @param bool $fromHtml - whether to use $this->html to
	 * @return void
	 */
	public function reparse( $fromHtml = false ) {
		if ( $fromHtml ) {
			if ( ( $this->mHtml ?? '' ) === '' ) {
				throw new InvalidArgumentException( 'No HTML provided; the comment could not be parsed.' );
			}

			$transform = $this->htmlTransformFactory
				->getHtmlToContentTransform( $this->mHtml, $this->getTitle() );

			$transform->setOptions( [
				'contentmodel' => CONTENT_MODEL_WIKITEXT,
				'offsetType' => 'byte'
			] );

			$content = $transform->htmlToContent();
			if ( !$content instanceof WikitextContent ) {
				// TODO better exception class
				throw new InvalidArgumentException( 'Unable to convert to wikitext' );
			}

			$this->mWikitext = $content->getText();
		} else {
			if ( ( $this->mWikitext ?? '' ) === '' ) {
				throw new InvalidArgumentException( 'No wikitext provided; the comment could not be parsed.' );
			}

			$parser = $this->parserFactory->create();
			// Parse as an anonymous user: the HTML produced here is stored and served to
			// everyone, so it must not vary with the preferences of whoever happened to
			// post or edit the comment.
			$parserOpts = ParserOptions::newFromAnon();
			$parserOpts->setAllowSpecialInclusion( false );
			$parserOutput = $parser->parse( $this->mWikitext, $this->getTitle(), $parserOpts );

			// 'unwrap' drops the .mw-parser-output wrapper.
			$this->mHtml = $parserOutput->runOutputPipeline( $parserOpts, [ 'unwrap' => true ] )
				->getContentHolderText();
		}
	}

	/**
	 * Check whether this Comment object would violate one of the wiki's anti-abuse measures. If the result from this
	 * method is null, then the comment passed validation. Else, it will return an array of errors from
	 * `Status::getErrorsArray()`.
	 * @return array[]|null
	 */
	public function checkSpamFilters() {
		// Run the comment through AbuseFilter, if it is installed and enabled
		if ( $this->config->get( 'YappinUseAbuseFilter' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'Abuse Filter' ) ) {
			// Go through the getters rather than the raw fields: a comment loaded from a
			// database row (i.e. the edit path) only has the actor and page IDs populated.
			$user = $this->userFactory->newFromUserIdentity( $this->getActor() );
			$title = $this->getTitle();

			$vars = AbuseFilterServices::getVariableGeneratorFactory()
				->newGenerator()
				->addUserVars( $user )
				->addTitleVars( $title, 'page' )
				->addGenericVars()
				->getVariableHolder();
			$vars->setVar( 'action', 'comment' );
			$vars->setVar( 'new_wikitext', $this->mWikitext );
			$vars->setLazyLoadVar( 'new_size', 'length', [ 'length-var' => 'new_wikitext' ] );

			$rf = AbuseFilterServices::getFilterRunnerFactory();
			$runner = $rf->newRunner( $user, $title, $vars, 'default' );
			$status = $runner->run();

			if ( !$status->isOK() ) {
				return $status->getErrorsArray();
			}
		}

		return null;
	}

	/**
	 * Saves this object to the database and returns the insert ID
	 * @param bool $setEditedTs
	 * @return int|null
	 */
	public function save( bool $setEditedTs = true ) {
		$isUpdate = $this->mId !== null;

		if ( !$this->mCreatedTimestamp ) {
			$this->mCreatedTimestamp = wfTimestamp( TS_ISO_8601 );
		}

		if ( $isUpdate && $setEditedTs ) {
			$this->mEditedTimestamp = wfTimestampOrNull( TS_ISO_8601, 0 );
		}

		$row = [
			'yap_page' => $this->mPageId,
			'yap_actor' => $this->mActorId,
			'yap_parent' => $this->mParentId,
			'yap_timestamp' => $this->dbw->timestamp( $this->mCreatedTimestamp ),
			'yap_deleted_actor' => $this->mDeletedActorId,
			'yap_rating' => $this->mRating,
			'yap_html' => $this->mHtml,
			'yap_wikitext' => $this->mWikitext,
			'yap_edited_timestamp' => $this->dbw->timestampOrNull( $this->mEditedTimestamp )
		];

		if ( !$isUpdate ) {
			// If there is no ID for this object, then we'll presume it doesn't exist.
			$this->dbw->newInsertQueryBuilder()
					  ->insertInto( self::TABLE_NAME )
					  ->row( $row )
					  ->caller( __METHOD__ )
					  ->execute();

			// Set the ID of this object to the newly inserted object ID
			$this->mId = $this->dbw->insertId();
		} else {
			// Perform an update instead
			$this->dbw->newUpdateQueryBuilder()
				->table( self::TABLE_NAME )
				->set( $row )
				->where( [ 'yap_id' => $this->mId ] )
				->caller( __METHOD__ )
				->execute();
		}

		return $this->dbw->affectedRows() ? $this->mId : null;
	}

	/**
	 * @return array
	 */
	public function toArray(): array {
		$showAvatars = $this->config->get( 'YappinShowUPV2Avatars' );
		$avatarUrl = null;
		if ( $showAvatars && ExtensionRegistry::getInstance()->isLoaded( 'UserProfileV2' ) ) {
			$userId = $this->getActor()->getId();
			$avatar = new UserProfileV2Avatar( $userId );
			$avatarUrl = $avatar->getAvatarUrl( [ "raw" => true ] ) ?? null;
		}

		return [
			'id' => $this->mId,
			'created' => wfTimestamp( TS_ISO_8601, $this->mCreatedTimestamp ),
			'edited' => wfTimestampOrNull( TS_ISO_8601, $this->mEditedTimestamp ),
			'user' => [
				'name' => $this->getActor()->getName(),
				'anon' => !$this->getActor()->isRegistered(),
				'temp' => $this->userIdentityUtils->isTemp( $this->getActor() ),
				'avatar' => $avatarUrl,
			],
			'parent' => $this->mParentId,
			'deleted' => $this->getDeletedActor() ? [
				'name' => $this->getDeletedActor()->getName(),
				'id' => $this->getDeletedActor()->getId()
			] : null,
			'rating' => $this->mRating,
			'html' => $this->mHtml,
			'wikitext' => $this->mWikitext
		];
	}
}

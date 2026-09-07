<?php

namespace MediaWiki\Extension\Yappin\Api;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Extension\Yappin\Models\Comment;
use MediaWiki\Extension\Yappin\Models\CommentControlStatus;
use MediaWiki\Extension\Yappin\Specials\SpecialCommentControl;
use MediaWiki\Extension\Yappin\Utils;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ApiEditComment extends SimpleHandler {
	/**
	 * @var CommentFactory
	 */
	private CommentFactory $commentFactory;

	/**
	 * @var ActorStore
	 */
	private ActorStore $actorStore;

	private Config $config;

	/**
	 * @var UserFactory
	 */
	private UserFactory $userFactory;

	public function __construct(
		CommentFactory $commentFactory,
		ActorStore $actorStore,
		Config $config,
		UserFactory $userFactory
	) {
		$this->commentFactory = $commentFactory;
		$this->actorStore = $actorStore;
		$this->config = $config;
		$this->userFactory = $userFactory;
	}

	/**
	 * @return Response
	 * @throws HttpException
	 */
	public function run() {
		if ( $this->getRequest()->getMethod() === 'PUT' ) {
			return $this->runEditComment();
		} else {
			return $this->runDeleteComment();
		}
	}

	/**
	 * @return Response
	 * @throws HttpException
	 */
	private function runEditComment() {
		$auth = $this->getAuthority();

		$canComment = Utils::canUserComment( $auth );
		if ( $canComment !== true ) {
			throw new LocalizedHttpException( $canComment, 403 );
		}

		if ( $this->config->get( 'YappinReadOnly' ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-readonly' ), 403 );
		}

		$body = $this->getValidatedBody();
		$params = $this->getValidatedParams();
		$commentId = (int)$params[ 'commentid' ];

		$html = trim( (string)$body[ 'html' ] );
		$wikitext = trim( (string)$body[ 'wikitext' ] );

		if ( !$html && !$wikitext ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-empty' ), 400 );
		}

		Utils::checkCommentLength( $this->config, $html ?: $wikitext );

		try {
			$comment = $this->commentFactory->newFromId( $commentId );
		} catch ( InvalidArgumentException $ex ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-generic-error-comment-missing', [ $commentId ] ), 400
			);
		}

		if ( $comment->isDeleted() ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-generic-error-comment-missing', [ $commentId ] ), 400
			);
		}

		// Editing must respect the same page-level restrictions as posting.
		$page = $comment->getTitle();
		if ( !$page
			|| !Utils::isCommentsEnabled( $this->config, $page )
			|| SpecialCommentControl::getControlStatus( $page ) !== CommentControlStatus::ENABLED
		) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-comments-disabled' ), 400
			);
		}

		if ( !self::isOwnComment( $comment, $auth ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-generic-error-notself' ), 400
			);
		}

		Utils::checkCommentRateLimit( $this->userFactory, $auth );

		if ( $html ) {
			$comment->setHtml( $html );
			if ( $comment->getWikitext() === '' ) {
				throw new LocalizedHttpException(
					new MessageValue( 'yappin-submit-error-empty' ), 400 );
			}
		} else {
			$comment->setWikitext( $wikitext );
		}

		Utils::checkCommentLength( $this->config, $comment->getHtml() );

		$isSpam = $comment->checkSpamFilters();
		if ( $isSpam ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-spam' ), 400
			);
		}

		$comment->save();

		return $this->getResponseFactory()->createJson( [
			'comment' => $comment->toArray()
		] );
	}

	/**
	 * Whether the comment was written by the acting user.
	 *
	 * @param Comment $comment
	 * @param Authority $authority
	 * @return bool
	 */
	private static function isOwnComment( Comment $comment, Authority $authority ): bool {
		$user = $authority->getUser();
		return $user->isRegistered() && $comment->getActor()->getId() === $user->getId();
	}

	/**
	 * @return Response
	 * @throws HttpException
	 */
	private function runDeleteComment() {
		$authority = $this->getAuthority();

		// Unlikely but a blocked moderator should not be able to do anything, including deleting comments.
		// Similarly one cannot delete one's own comments when blocked.
		$blocked = Utils::checkCommentBlock( $authority );
		if ( $blocked !== false ) {
			throw new LocalizedHttpException( $blocked, 403 );
		}
		// No deletion in readonly mode.
		if ( $this->config->get( 'YappinReadOnly' ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-readonly' ), 403 );
		}

		$body = $this->getValidatedBody();
		$params = $this->getValidatedParams();
		$commentId = (int)$params[ 'commentid' ];
		$delete = (bool)$body[ 'delete' ];

		try {
			$comment = $this->commentFactory->newFromId( $commentId );
		} catch ( InvalidArgumentException $ex ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-generic-error-comment-missing', [ $commentId ] ), 400
			);
		}

		$ownComment = self::isOwnComment( $comment, $authority );
		$isMod = Utils::canUserModerate( $authority );

		if ( $ownComment && $delete === true ) {
			$comment->setDeletedActor( $comment->getActor() );
		} elseif ( $isMod ) {
			$comment->setDeletedActor( $delete ? $authority->getUser() : null );
		} else {
			// No permission
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-generic-error-notself' ), 400
			);
		}

		$comment->save( false );

		return $this->getResponseFactory()->createJson( [
			'deleted' => $comment->getDeletedActor() ? [
				'name' => $comment->getDeletedActor()->getName(),
				'id' => $comment->getDeletedActor()->getId()
			] : null
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		if ( $this->getRequest()->getMethod() === 'PUT' ) {
			return [
				'html' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => false
				],
				'wikitext' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => false
				]
			];
		} else {
			return [
				'delete' => [
					self::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'boolean',
					ParamValidator::PARAM_REQUIRED => true
				]
			];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'commentid' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true
			]
		];
	}
}

<?php

namespace MediaWiki\Extension\Yappin\Api;

use MediaWiki\Extension\Yappin\Models\Comment;
use MediaWiki\Extension\Yappin\Utils;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ApiEditComment extends CommentWriteHandler {
	/**
	 * @return Response
	 * @throws HttpException
	 */
	public function run() {
		if ( $this->isEditRequest() ) {
			return $this->runEditComment();
		} else {
			return $this->runDeleteComment();
		}
	}

	private function isEditRequest(): bool {
		return $this->getConfig()['method'] === 'PUT';
	}

	/**
	 * @return Response
	 * @throws HttpException
	 */
	private function runEditComment() {
		$this->assertCanComment();

		$commentId = (int)$this->getValidatedParams()[ 'commentid' ];

		[ $html, $wikitext ] = $this->getSubmittedContent();

		$comment = $this->loadNotDeletedComment( $commentId, 'yappin-generic-error-comment-missing' );

		// Editing must respect the same page-level restrictions as posting.
		$page = $comment->getTitle();
		if ( !$page ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-comments-disabled' ), 400
			);
		}
		$this->pageAcceptsNewComments( $page );

		if ( !self::isOwnComment( $comment, $this->getAuthority() ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-generic-error-notself' ), 400
			);
		}

		$this->checkRateLimit();

		$this->setCommentContent( $comment, $html, $wikitext );

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
		$this->assertNotReadOnly();

		$body = $this->getValidatedBody() ?? [];
		$params = $this->getValidatedParams();
		$commentId = (int)$params[ 'commentid' ];
		$delete = (bool)$body[ 'delete' ];

		$comment = $this->loadComment( $commentId, 'yappin-generic-error-comment-missing' );

		$ownComment = self::isOwnComment( $comment, $authority );
		$isMod = Utils::canUserModerate( $authority );

		if ( $ownComment && $delete ) {
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
		if ( $this->isEditRequest() ) {
			return self::getContentBodyParamSettings();
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

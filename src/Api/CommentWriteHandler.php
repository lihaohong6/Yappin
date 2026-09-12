<?php

namespace MediaWiki\Extension\Yappin\Api;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Extension\Yappin\Models\Comment;
use MediaWiki\Extension\Yappin\Models\CommentControlStatus;
use MediaWiki\Extension\Yappin\Specials\SpecialCommentControl;
use MediaWiki\Extension\Yappin\Utils;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

abstract class CommentWriteHandler extends SimpleHandler {
	protected CommentFactory $commentFactory;

	protected Config $config;

	protected UserFactory $userFactory;

	public function __construct(
		CommentFactory $commentFactory,
		Config $config,
		UserFactory $userFactory
	) {
		$this->commentFactory = $commentFactory;
		$this->config = $config;
		$this->userFactory = $userFactory;
	}

	protected function assertCanComment(): void {
		$canComment = Utils::canUserComment( $this->getAuthority() );
		if ( $canComment !== true ) {
			throw new LocalizedHttpException( $canComment, 403 );
		}

		$this->assertNotReadOnly();
	}

	/**
	 * @throws LocalizedHttpException if comments are globally read only
	 */
	protected function assertNotReadOnly(): void {
		if ( $this->config->get( 'YappinReadOnly' ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-readonly' ), 403 );
		}
	}

	/**
	 * @return array<string>
	 * @throws LocalizedHttpException
	 */
	protected function getSubmittedContent(): array {
		$body = $this->getValidatedBody() ?? [];

		$html = trim( (string)$body[ 'html' ] );
		$wikitext = trim( (string)$body[ 'wikitext' ] );

		if ( !$html && !$wikitext ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-empty' ), 400 );
		}

		// HTML will be converted to wikitext and back to HTML.
		// Wikitext will be parsed to HTML.
		// Worth an early check for both cases.
		Utils::checkCommentLength( $this->config, $html ?: $wikitext );

		return [ $html, $wikitext ];
	}

	protected function setCommentContent( Comment $comment, string $html, string $wikitext ): void {
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
	}

	protected function pageAcceptsNewComments( Title $page ): void {
		if ( !Utils::isCommentsEnabled( $this->config, $page )
			|| SpecialCommentControl::getControlStatus( $page ) !== CommentControlStatus::ENABLED
		) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-comments-disabled' ), 400
			);
		}
	}

	protected function loadComment( int $commentId, string $errorKey ): Comment {
		try {
			return $this->commentFactory->newFromId( $commentId );
		} catch ( InvalidArgumentException ) {
			throw new LocalizedHttpException(
				new MessageValue( $errorKey, [ $commentId ] ), 400
			);
		}
	}

	protected function loadNotDeletedComment( int $commentId, string $errorKey ): Comment {
		$comment = $this->loadComment( $commentId, $errorKey );

		if ( $comment->isDeleted() ) {
			throw new LocalizedHttpException(
				new MessageValue( $errorKey, [ $commentId ] ), 400
			);
		}

		return $comment;
	}

	/**
	 * @throws LocalizedHttpException
	 */
	protected function checkRateLimit(): void {
		Utils::checkCommentRateLimit( $this->userFactory, $this->getAuthority() );
	}

	protected static function getContentBodyParamSettings(): array {
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
	}
}

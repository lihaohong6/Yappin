<?php

namespace MediaWiki\Extension\Yappin\Api;

use InvalidArgumentException;
use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\TempUser\TempUserCreator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ApiVoteComment extends SimpleHandler {
	/**
	 * @var CommentFactory
	 */
	private CommentFactory $commentFactory;

	/**
	 * @var TempUserCreator
	 */
	private TempUserCreator $tempUserCreator;

	public function __construct(
		CommentFactory $commentFactory,
		TempUserCreator $tempUserCreator
	) {
		$this->commentFactory = $commentFactory;
		$this->tempUserCreator = $tempUserCreator;
	}

	/**
	 * @return Response
	 * @throws HttpException
	 */
	public function run() {
		$body = $this->getValidatedBody();
		$params = $this->getValidatedParams();

		$commentId = (int)$params[ 'commentid' ];
		$rating = (int)$body[ 'rating' ];

		// Should be a valid value, otherwise fail the call
		if ( $rating !== -1 && $rating !== 0 && $rating !== 1 ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-rating-error-invalid' ), 400
			);
		}

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

		// Voting deliberately does not auto-create a temporary account: it is a one-click action,
		// and quietly registering an account behind it would surprise the user. Once temporary
		// accounts are enabled there is then nothing left to attribute an anonymous vote to, since
		// ActorStore refuses to create IP actors, so anons cannot vote on such a wiki.
		$user = $this->getAuthority()->getUser();
		if ( $this->tempUserCreator->isEnabled() && !$user->isRegistered() ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-rating-error-anon' ), 403
			);
		}

// if ( $comment->getUser()->getId() === $user->getId() ) {
//			throw new HttpException( "Cannot vote on user's own comment", 400 );
//		}

		$rating = $comment->setRatingForUser( $user, $rating );

		return $this->getResponseFactory()->createJson( [
			'comment' => $rating->getComment()->toArray()
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		return [
			'rating' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => [ -1, 0, 1 ],
				ParamValidator::PARAM_REQUIRED => true
			],
		];
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

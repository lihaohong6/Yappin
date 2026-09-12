<?php

namespace MediaWiki\Extension\Yappin\Api;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Extension\Yappin\Utils;
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

	private Config $config;

	public function __construct(
		CommentFactory $commentFactory,
		TempUserCreator $tempUserCreator,
		Config $config
	) {
		$this->commentFactory = $commentFactory;
		$this->tempUserCreator = $tempUserCreator;
		$this->config = $config;
	}

	/**
	 * @return Response
	 * @throws HttpException
	 */
	public function run() {
		$auth = $this->getAuthority();

		// Voting needs the same right as commenting, as otherwise blocked users can still abuse the
		// voting feature.
		$canComment = Utils::canUserComment( $auth );
		if ( $canComment !== true ) {
			throw new LocalizedHttpException( $canComment, 403 );
		}

		// No one can vote in readonly mode.
		if ( $this->config->get( 'YappinReadOnly' ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-readonly' ), 403 );
		}

		$body = $this->getValidatedBody() ?? [];
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
		} catch ( InvalidArgumentException ) {
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
		// and quietly registering an account behind it would surprise the user.
		$user = $auth->getUser();
		if ( $this->tempUserCreator->isEnabled() && !$user->isRegistered() ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-rating-error-anon' ), 403
			);
		}

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

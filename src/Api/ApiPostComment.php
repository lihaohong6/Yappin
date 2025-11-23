<?php

namespace MediaWiki\Extension\Yappin\Api;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Extension\Yappin\Models\Comment;
use MediaWiki\Extension\Yappin\Models\CommentControlStatus;
use MediaWiki\Extension\Yappin\Specials\SpecialCommentControl;
use MediaWiki\Extension\Yappin\Utils;
use MediaWiki\MediaWikiServices;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Notification\Types\WikiNotification;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentity;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Parsoid\Core\LinkTarget as ParsoidLinkTarget;

class ApiPostComment extends SimpleHandler {
	/**
	 * @var TitleFactory
	 */
	private TitleFactory $titleFactory;

	/**
	 * @var CommentFactory
	 */
	private CommentFactory $commentFactory;

	/**
	 * @var Config
	 */
	private Config $config;

	public function __construct(
		TitleFactory $titleFactory,
		CommentFactory $commentFactory,
		Config $config
	) {
		$this->titleFactory = $titleFactory;
		$this->commentFactory = $commentFactory;
		$this->config = $config;
	}

	/**
	 * @throws HttpException
	 */
	public function run() {
		$auth = $this->getAuthority();
		$canComment = Utils::canUserComment( $auth );
		if ( $canComment !== true ) {
			throw new LocalizedHttpException( $canComment, 403 );
		}

		$body = $this->getValidatedBody();
		$pageId = (int)$body[ 'pageid' ];
		$parentId = (int)$body[ 'parentid' ];

		// Must either provide a page ID or a parent ID
		if ( !$pageId && !$parentId ) {
			throw new HttpException( 'Must provide either page ID or parent ID' );
		}

		// FIXME: can we trust user input here?
		$html = trim( (string)$body[ 'html' ] );
		$wikitext = trim( (string)$body[ 'wikitext' ] );

		if ( !$html && !$wikitext ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-empty' ), 400 );
		}

		$parent = null;
		if ( $parentId ) {
			$parent = $this->commentFactory->newFromId( $parentId );

			if ( $parent->isDeleted() ) {
				throw new LocalizedHttpException(
					new MessageValue( 'yappin-submit-error-parent-missing', $parentId ), 400 );
			}
			if ( $parent->getParent() ) {
				throw new LocalizedHttpException(
					new MessageValue( 'yappin-submit-error-parent-hasparent' ), 400 );
			}

			$pageId = $parent->getTitle()->getId();
		}

		$page = $this->titleFactory->newFromID( $pageId );
		if ( !$page || !$page->exists() ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-page-missing', $pageId ), 400 );
		}

		$commentEnabledOnPage = SpecialCommentControl::getControlStatus($page) === CommentControlStatus::ENABLED;
		if ( !Utils::isCommentsEnabled( $this->config, $page ) || !$commentEnabledOnPage) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-comments-disabled' ), 400 );
		}

		// Create a new comment
		$comment = $this->commentFactory->newEmpty()
			->setTitle( $page )
			->setActor( $this->getAuthority()->getUser() )
			->setParent( $parent );

		if ( $html ) {
			$comment->setHtml( $html );
		} else {
			$comment->setWikitext( $wikitext );
		}

		$isSpam = $comment->checkSpamFilters();
		if ( $isSpam ) {
			throw new LocalizedHttpException(
				new MessageValue( 'yappin-submit-error-spam' ), 400
			);
		}

		$comment->save();

		$this->notifyComments( $auth->getUser(), $parent, $page, $comment );

		return $this->getResponseFactory()->createJson( [
			'comment' => $comment->toArray()
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		return [
			'pageid' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false
			],
			'parentid' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false
			],
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

	/**
	 * @param Authority $auth
	 * @param Comment|null $parent
	 * @param Title $page
	 * @param Comment $comment
	 * @return void
	 */
	public function notifyComments(
		UserIdentity $notifier,
		?Comment $parent,
		Title $page,
		Comment $comment
	): void {
		$services = MediaWikiServices::getInstance();
		$userFactory = $services->getUserFactory();
		$recipients = [];

		// 1. Direct reply notification
		if ( $parent ) {
			$parentActor = $parent->getActor();
			if ( $parentActor->getId() !== 0 ) {
				$parentUser = $userFactory->newFromActorId( $parentActor->getId() );
				if ( $parentUser->getId() !== $notifier->getId() ) {
					$recipients[$parentUser->getId()] = [
						'type' => 'yappin-reply'
					];
				}
			}
		}

		// 2. User page notification
		// When a comment is made on a user page, the owner is always notified.
		if ( $page->inNamespace( NS_USER ) ) {
			$pageOwner = $userFactory->newFromName( $page->getText() );
			if (
				$pageOwner &&
				$pageOwner->isRegistered() &&
				!$pageOwner->equals( $notifier ) &&
				!isset( $recipients[$pageOwner->getId()] )
			) {
				$recipients[$pageOwner->getId()] = [
					'type' => 'yappin-user-page'
				];
			}
		}

		// 3. Mention notification
		// Parse wikitext to find links. Note that nested replies also go here.
		$wikitext = $comment->getWikitext();
		$parser = $services->getParser();
		$parserOptions = ParserOptions::newFromUser( $notifier );
		$output = $parser->parse( $wikitext, $page, $parserOptions );
		$links = $output->getLinkList( ParserOutputLinkTypes::LOCAL );

		// "Limit of at most 10 users"
		$linksFound = 0;
		foreach ( $links as $linkObject ) {
			// Prevent ping spam
			if ( $linksFound >= 10 ) {
				break;
			}
			/** @var ParsoidLinkTarget $linkTarget */
			$linkTarget = $linkObject['link'];
			if ( $linkTarget->getNamespace() !== NS_USER ) {
				continue;
			}
			$mentionedUser = $userFactory->newFromName( $linkTarget->getText() );
			if ( $mentionedUser && $mentionedUser->isRegistered() && $mentionedUser->getId() !== $notifier->getId() ) {
				$userId = $mentionedUser->getId();
				if ( isset( $recipients[$userId] ) ) {
					continue;
				}
				$recipients[$userId] = [
					'type' => 'yappin-mention',
				];
				$linksFound++;
			}
		}

		$notifications = MediaWikiServices::getInstance()->getNotificationService();
		foreach ( $recipients as $recipientId => $info ) {
			$info['title'] = $page;
			$info['agent'] = $notifier;
			$notifications->notify(
				new WikiNotification( $info['type'], $page, $notifier, [
					'comment_id' => $comment->getId(),
					'wikitext' => $wikitext,
				] ),
				new RecipientSet( $userFactory->newFromId( $recipientId ) ),
			);
		}
	}
}

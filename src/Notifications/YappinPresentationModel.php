<?php

namespace MediaWiki\Extension\Yappin\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Message\Message;

abstract class YappinPresentationModel extends EchoEventPresentationModel {

	public const int NOTIFICATION_SNIPPET_LENGTH = 150;

	public function getIconType(): string {
		return 'chat';
	}

	/**
	 * @inheritDoc
	 */
	public function canRender() {
		return $this->event->getTitle() !== null && $this->event->getAgent() !== null;
	}

	/**
	 * @inheritDoc
	 */
	public function getPrimaryLink(): array {
		return [
			'url' => $this->event->getTitle()->getLocalURL(
				[ 'comment' => $this->event->getExtraParam( 'comment_id' ) ]
			),
			'label' => $this->msg( 'notification-link-text-view-comment' )->text(),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getSecondaryLinks(): array {
		return [ $this->getAgentLink() ];
	}

	protected function setMessageParams( string $key ): Message {
		$msg = $this->msg( $key );
		$msg->params( $this->event->getAgent()->getName() );
		$msg->params( $this->event->getTitle()->getPrefixedText() );
		return $msg;
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyMessage(): bool|Message {
		$wikitext = (string)$this->event->getExtraParam( 'wikitext', '' );

		$message = $this->msg( 'notification-body-yappin' );
		// Mirrors core where wikitext is not parsed for notifications.
		$message->plaintextParams( $wikitext );
		return $message;
	}
}

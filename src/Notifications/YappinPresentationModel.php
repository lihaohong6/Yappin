<?php

namespace MediaWiki\Extension\Yappin\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Message\Message;

abstract class YappinPresentationModel extends EchoEventPresentationModel {

	public function getIconType(): string {
		return 'chat';
	}

	public function getPrimaryLink(): array {
		return [
			'url' => $this->event->getTitle()->getLocalURL() . '?comment=' . $this->event->getExtraParam( 'comment_id' ),
			'label' => $this->msg( 'notification-link-text-view-comment' )->text(),
		];
	}

	public function getSecondaryLinks(): array {
		return [ $this->getAgentLink() ];
	}

	protected function setMessageParams( string $key ): Message {
		$msg = $this->msg( $key );
		$msg->params( $this->event->getAgent()->getName() );
		$msg->params( $this->event->getTitle()->getPrefixedText() );
		return $msg;
	}

	public function getBodyMessage(): bool | Message {
		$message = $this->msg( 'notification-body-yappin' );
		$message->params( $this->event->getExtraParam( 'wikitext', '' ));
		return $message;
	}
}

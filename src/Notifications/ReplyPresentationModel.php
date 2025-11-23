<?php

namespace MediaWiki\Extension\Yappin\Notifications;

class ReplyPresentationModel extends YappinPresentationModel {

	public function getHeaderMessage() {
		return $this->setMessageParams( 'notification-header-yappin-reply' );
	}
}

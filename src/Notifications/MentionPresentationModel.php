<?php

namespace MediaWiki\Extension\Yappin\Notifications;

class MentionPresentationModel extends YappinPresentationModel {

	public function getHeaderMessage() {
		return $this->setMessageParams( 'notification-header-yappin-mention' );
	}
}

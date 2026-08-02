<?php

namespace MediaWiki\Extension\Yappin\Notifications;

class UserPagePresentationModel extends YappinPresentationModel {
	/** @inheritDoc */
	public function getHeaderMessage() {
		return $this->setMessageParams( 'notification-header-yappin-user-page' );
	}
}

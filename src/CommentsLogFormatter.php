<?php

namespace MediaWiki\Extension\Yappin;

use MediaWiki\Logging\LogFormatter;

class CommentsLogFormatter extends LogFormatter {
	public function getMessageParameters(): array {
		$params = parent::getMessageParameters();
		$type = $this->entry->getFullType();
		if ( $type === 'comments/control' ) {
			$params[3] = wfMessage("yappin-commentcontrol-status-$params[3]")->inContentLanguage();
		}
		return $params;
	}
}

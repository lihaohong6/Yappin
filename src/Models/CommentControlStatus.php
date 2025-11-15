<?php

namespace MediaWiki\Extension\Yappin\Models;

enum CommentControlStatus: int {
	case ENABLED = 0;
	case READ_ONLY = 1;
	case DISABLED = 2;
}

function commentControlStatusToKey( CommentControlStatus $status ): string {
	return str_replace( "_", "-", strtolower( $status->name ) );
}

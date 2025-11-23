<?php

namespace MediaWiki\Extension\Yappin\Hooks;

use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\Extension\Yappin\Notifications\MentionPresentationModel;
use MediaWiki\Extension\Yappin\Notifications\ReplyPresentationModel;
use MediaWiki\Extension\Yappin\Notifications\UserPagePresentationModel;

class NotificationHookHandlers {
	public static function onBeforeCreateEchoEvent(
		&$notifications,
		&$notificationCategories,
		&$icons
	) {
		// Define the category this event belongs to
		// (this will appear in Special:Preferences)
		$notificationCategories['yappin'] = [
			'priority' => 3,
			'title' => "echo-category-title-yappin",
			'tooltip' => 'echo-pref-tooltip-yappin',
		];

		function getNotificationArray( $presentationModel ): array {
			return [
				'category' => 'yappin',
				'group' => 'interactive',
				'section' => 'message',
				'bundle' => [
					'web' => true,
					'expandable' => true,
				],
				'presentation-model' => $presentationModel,
				AttributeManager::ATTR_LOCATORS => [
					[
						[
							UserLocator::class,
							'locateFromEventExtra'
						],
						[ 'user' ]
					]
				],
			];
		}

		$notifications['yappin-reply'] = getNotificationArray( ReplyPresentationModel::class );
		$notifications['yappin-user-page'] = getNotificationArray( UserPagePresentationModel::class );
		$notifications['yappin-mention'] = getNotificationArray( MentionPresentationModel::class );
	}
}

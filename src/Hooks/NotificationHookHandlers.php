<?php

namespace MediaWiki\Extension\Yappin\Hooks;

use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\Extension\Yappin\Notifications\MentionPresentationModel;
use MediaWiki\Extension\Yappin\Notifications\ReplyPresentationModel;
use MediaWiki\Extension\Yappin\Notifications\UserPagePresentationModel;

class NotificationHookHandlers {
	/**
	 * Build the Echo notification definition shared by all Yappin notification types.
	 *
	 * @param string $presentationModel Class name of the presentation model to use
	 * @return array
	 */
	private static function getNotificationArray( string $presentationModel ): array {
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

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 * @return void
	 */
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

		$notifications['yappin-reply'] = self::getNotificationArray( ReplyPresentationModel::class );
		$notifications['yappin-user-page'] = self::getNotificationArray( UserPagePresentationModel::class );
		$notifications['yappin-mention'] = self::getNotificationArray( MentionPresentationModel::class );
	}
}

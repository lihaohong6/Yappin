# Yappin
MediaWiki extension which allows users to leave comments on a page, which is displayed underneath the page content.

Forked from https://github.com/weirdgloop/mediawiki-extensions-Comments. Changes:
- Provides a wikitext mode where the user inputs wikitext, which makes VisualEditor an optional dependency.
- Send notifications for replies, mentions, and comments made on user pages.
- Allow nested replies through mentions.
- Display profile pictures from UserProfileV2.
- Import comments from other services using a generic JSON interface. Importing from CommentStreams is possible with a maintenance script.
- Export all comments to a json file.
- Disable commenting on a per-page basis through Special:CommentControl.
- Use tables.json for the database schema so that it (hopefully) works for mysql, postgres, and sqlite.
- Navigate automatically to the bottom of the page when viewing a specific comment.
- Complete the rename from Comment to Yappin by changing configuration variable and database table names.

Caveats:
- The original extension written by Weird Gloop deliberately minimizes its feature set to improve maintainability. This fork, in contrast, has a LOT of features, and is likely buggier than the original.

## Dependencies
Requires MediaWiki 1.45+ and the Echo extension.

Optionally works with [VisualEditor](https://www.mediawiki.org/wiki/Extension:VisualEditor) and [UserProfileV2](https://www.mediawiki.org/wiki/Extension:UserProfileV2).

## Installing
1. Enable the extension using `wfLoadExtension( 'Yappin' );`
2. Run `update.php` to create the required database tables

To allow users to be blocked from leaving comments, `$wgEnablePartialActionBlocks = true;` should also be set.

If the wiki previously ran a version of this extension that used the `com_*` tables, `update.php` detects them and renames the tables, columns, and indexes to `yappin_*` in place before applying the current schema. No manual migration is needed, but back up the database first as with any schema change.

## Configuration
| Variable                    | Description                                                                                                                                                              | Default                |
|-----------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------|------------------------|
| $wgYappinResultsPerPage     | How many comments to load at a time by default. This value cannot be higher than 100 for performance reasons.                                                            | `50`                   |
| $wgYappinReadOnly           | If enabled, new comments cannot be posted and existing comments cannot be edited                                                                                         | `false`                |
| $wgYappinUseAbuseFilter     | If enabled, run comments through [AbuseFilter](https://www.mediawiki.org/wiki/Extension:AbuseFilter)                                                                     | `true`                 |
| $wgYappinUseVisualEditor    | If enabled, use [VisualEditor](https://www.mediawiki.org/wiki/Extension:VisualEditor) to compose comments when it is installed. Otherwise, the wikitext editor is used.  | `true`                 |
| $wgYappinShowUPV2Avatars    | If enabled, show profile pictures from [UserProfileV2](https://www.mediawiki.org/wiki/Extension:UserProfileV2) next to comments                                          | `false`                |
| $wgYappinEnabledNamespaces  | The namespaces that comments are enabled on, as a map of namespace ID to boolean. If a namespace is not enabled, comments are not visible on that namespace's pages, and new comments cannot be left on them. Every namespace in `$wgContentNamespaces` is enabled by default; set an entry to `false` to opt one out. | `$wgContentNamespaces` |

> Comments are disabled on talk pages, special pages, and non-existent pages, regardless of if the page's namespace is in `$wgYappinEnabledNamespaces`.

## How does it work?
Each wiki page has a comments section displayed at the bottom, which loads the comments (default: `50`) when the user scrolls down to it. Users can leave new comments, or reply to existing comments, which will be attributed to their wiki account or their IP address (if anonymous).

When a user submits a comment, the HTML of the comment is converted (and sanitized) to wikitext syntax using MediaWiki's built-in Parsoid parser and stored. No new pages or namespaces are created by this extension; the comments are stored in their own table, `yappin_comment`.

Comments can be upvoted or downvoted, which will change the score displayed on each comment. This feature helps people find the most useful comments more easily. By default, the comments list is ordered by rating.

Actions users can perform (such as creating or editing a comment, or voting on a comment) are attributed to their entry in the core MediaWiki `actor` database table. This means that (logged out) users sharing the same IP address can edit that IP's comments, votes, etc.

### Composing comments
If VisualEditor is installed and `$wgYappinUseVisualEditor` is enabled, comments are composed in VisualEditor. Otherwise, comments are written as wikitext in a plain text box, which can be rendered with the preview button before posting.

### Notifications
Echo notifications are sent for three events:

- `yappin-reply` — someone replied to your comment
- `yappin-mention` — you were mentioned in a comment via `[[User:Name]]` (at most 10 mentions are notified per comment)
- `yappin-user-page` — a comment was left on your user page

### Moderation
Users with the `yappin-manage` permission can delete other user's comments. Users can be blocked from commenting using the standard Special:Block form by a user that has the `block` right (typically sysops), by blocking the `yappin-comment` action.

Moderation actions are recorded in the `yappin` log.

#### Rights
| Right            | Description                                                                     |
|------------------|---------------------------------------------------------------------------------|
| `yappin-comment` | Post and edit comments. Also available as a partial block action.               |
| `yappin-manage`  | Delete and restore other users' comments; use Special:CommentControl and Special:ExportComments. |
| `yappin-import`  | Use Special:ImportComments.                                                      |

#### AbuseFilter
If `$wgYappinUseAbuseFilter` is enabled and the [AbuseFilter](https://www.mediawiki.org/wiki/Extension:AbuseFilter) extension is installed, comments will run through all enabled filters on the wiki with the "action" variable set to "comment" and the "new_wikitext" value set to the wikitext of the comment. An example of a rule that would match is: `action == 'comment' & new_wikitext irlike "badword"`

## Special pages
| Page                   | Right           | Description                                                                                          |
|------------------------|-----------------|------------------------------------------------------------------------------------------------------|
| Special:Comments       | —               | Browse all comments on the wiki, or the comments of a single user. Requires JavaScript.              |
| Special:CommentControl | `yappin-manage` | Set a page's comment status to enabled, read-only, or disabled, and review pages that are restricted. |
| Special:ImportComments | `yappin-import` | Import comments from a JSON file.                                                                     |
| Special:ExportComments | `yappin-manage` | Export all comments on the wiki to a JSON file.                                                       |

## Maintenance scripts
| Script                              | Description                                                                                                                    |
|-------------------------------------|--------------------------------------------------------------------------------------------------------------------------------|
| `maintenance/ImportCommentStreams.php` | Import comments from the [CommentStreams](https://www.mediawiki.org/wiki/Extension:CommentStreams) extension.                  |
| `maintenance/RemoveAllComments.php`    | Delete every row from `yappin_comment`, `yappin_rating`, and `yappin_control`. Mostly useful for cleaning up a botched import. |

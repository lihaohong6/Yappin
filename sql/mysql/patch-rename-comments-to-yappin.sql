-- Renames com_comment/com_rating/com_control (and their columns) to
-- yappin_comment/yappin_rating/yappin_control, matching the extension's
-- comments-to-yappin rebrand. Uses CHANGE COLUMN rather than RENAME COLUMN
-- for compatibility with MySQL 5.7 / MariaDB 10.3.

RENAME TABLE /*_*/com_comment TO /*_*/yappin_comment;

ALTER TABLE /*_*/yappin_comment
  CHANGE c_id yap_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  CHANGE c_page yap_page INT UNSIGNED NOT NULL,
  CHANGE c_actor yap_actor BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  CHANGE c_timestamp yap_timestamp BINARY(14) NOT NULL,
  CHANGE c_parent yap_parent INT UNSIGNED DEFAULT NULL,
  CHANGE c_deleted_actor yap_deleted_actor BIGINT UNSIGNED DEFAULT NULL,
  CHANGE c_rating yap_rating INT DEFAULT 0 NOT NULL,
  CHANGE c_html yap_html MEDIUMBLOB NOT NULL,
  CHANGE c_wikitext yap_wikitext MEDIUMBLOB NOT NULL,
  CHANGE c_edited_timestamp yap_edited_timestamp BINARY(14) DEFAULT NULL;

ALTER TABLE /*_*/yappin_comment
  DROP INDEX c_timestamp, ADD INDEX yap_timestamp (yap_timestamp),
  DROP INDEX c_parent, ADD INDEX yap_parent (yap_parent),
  DROP INDEX c_page_timestamp, ADD INDEX yap_page_timestamp (yap_page, yap_timestamp),
  DROP INDEX c_actor_timestamp, ADD INDEX yap_actor_timestamp (yap_actor, yap_timestamp),
  DROP INDEX c_rating_timestamp, ADD INDEX yap_rating_timestamp (yap_rating, yap_timestamp);

RENAME TABLE /*_*/com_rating TO /*_*/yappin_rating;

ALTER TABLE /*_*/yappin_rating
  CHANGE cr_comment yr_comment INT UNSIGNED NOT NULL,
  CHANGE cr_actor yr_actor BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  CHANGE cr_rating yr_rating INT NOT NULL;

RENAME TABLE /*_*/com_control TO /*_*/yappin_control;

ALTER TABLE /*_*/yappin_control
  CHANGE cc_page yc_page INT UNSIGNED NOT NULL,
  CHANGE cc_restriction yc_restriction TINYINT UNSIGNED NOT NULL;

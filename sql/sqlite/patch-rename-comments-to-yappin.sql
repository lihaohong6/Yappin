-- Renames com_comment/com_rating/com_control (and their columns) to
-- yappin_comment/yappin_rating/yappin_control, matching the extension's
-- comments-to-yappin rebrand. SQLite only allows one RENAME action per
-- ALTER TABLE statement, so each column gets its own statement. Indexes
-- are recreated under their new names since SQLite has no RENAME INDEX.

ALTER TABLE /*_*/com_comment RENAME TO /*_*/yappin_comment;

ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_id TO yap_id;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_page TO yap_page;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_actor TO yap_actor;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_timestamp TO yap_timestamp;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_parent TO yap_parent;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_deleted_actor TO yap_deleted_actor;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_rating TO yap_rating;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_html TO yap_html;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_wikitext TO yap_wikitext;
ALTER TABLE /*_*/yappin_comment RENAME COLUMN c_edited_timestamp TO yap_edited_timestamp;

DROP INDEX IF EXISTS /*_*/c_timestamp;
CREATE INDEX /*_*/yap_timestamp ON /*_*/yappin_comment (yap_timestamp);

DROP INDEX IF EXISTS /*_*/c_parent;
CREATE INDEX /*_*/yap_parent ON /*_*/yappin_comment (yap_parent);

DROP INDEX IF EXISTS /*_*/c_page_timestamp;
CREATE INDEX /*_*/yap_page_timestamp ON /*_*/yappin_comment (yap_page, yap_timestamp);

DROP INDEX IF EXISTS /*_*/c_actor_timestamp;
CREATE INDEX /*_*/yap_actor_timestamp ON /*_*/yappin_comment (yap_actor, yap_timestamp);

DROP INDEX IF EXISTS /*_*/c_rating_timestamp;
CREATE INDEX /*_*/yap_rating_timestamp ON /*_*/yappin_comment (yap_rating, yap_timestamp);

ALTER TABLE /*_*/com_rating RENAME TO /*_*/yappin_rating;

ALTER TABLE /*_*/yappin_rating RENAME COLUMN cr_comment TO yr_comment;
ALTER TABLE /*_*/yappin_rating RENAME COLUMN cr_actor TO yr_actor;
ALTER TABLE /*_*/yappin_rating RENAME COLUMN cr_rating TO yr_rating;

ALTER TABLE /*_*/com_control RENAME TO /*_*/yappin_control;

ALTER TABLE /*_*/yappin_control RENAME COLUMN cc_page TO yc_page;
ALTER TABLE /*_*/yappin_control RENAME COLUMN cc_restriction TO yc_restriction;

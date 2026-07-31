-- Renames com_comment/com_rating/com_control (and their columns) to
-- yappin_comment/yappin_rating/yappin_control, matching the extension's
-- comments-to-yappin rebrand. Postgres only allows one RENAME action per
-- ALTER TABLE statement, so each column/index gets its own statement.

ALTER TABLE com_comment RENAME TO yappin_comment;

ALTER TABLE yappin_comment RENAME COLUMN c_id TO yap_id;
ALTER TABLE yappin_comment RENAME COLUMN c_page TO yap_page;
ALTER TABLE yappin_comment RENAME COLUMN c_actor TO yap_actor;
ALTER TABLE yappin_comment RENAME COLUMN c_timestamp TO yap_timestamp;
ALTER TABLE yappin_comment RENAME COLUMN c_parent TO yap_parent;
ALTER TABLE yappin_comment RENAME COLUMN c_deleted_actor TO yap_deleted_actor;
ALTER TABLE yappin_comment RENAME COLUMN c_rating TO yap_rating;
ALTER TABLE yappin_comment RENAME COLUMN c_html TO yap_html;
ALTER TABLE yappin_comment RENAME COLUMN c_wikitext TO yap_wikitext;
ALTER TABLE yappin_comment RENAME COLUMN c_edited_timestamp TO yap_edited_timestamp;

ALTER INDEX c_timestamp RENAME TO yap_timestamp;
ALTER INDEX c_parent RENAME TO yap_parent;
ALTER INDEX c_page_timestamp RENAME TO yap_page_timestamp;
ALTER INDEX c_actor_timestamp RENAME TO yap_actor_timestamp;
ALTER INDEX c_rating_timestamp RENAME TO yap_rating_timestamp;

ALTER TABLE com_rating RENAME TO yappin_rating;

ALTER TABLE yappin_rating RENAME COLUMN cr_comment TO yr_comment;
ALTER TABLE yappin_rating RENAME COLUMN cr_actor TO yr_actor;
ALTER TABLE yappin_rating RENAME COLUMN cr_rating TO yr_rating;

ALTER TABLE com_control RENAME TO yappin_control;

ALTER TABLE yappin_control RENAME COLUMN cc_page TO yc_page;
ALTER TABLE yappin_control RENAME COLUMN cc_restriction TO yc_restriction;

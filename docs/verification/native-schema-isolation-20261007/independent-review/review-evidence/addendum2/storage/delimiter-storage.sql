-- Reviewer check (addendum 2): which database names MySQL 8.4.11 accepts, how bodies that
-- qualify them are stored, and the Codex second-P2 / FROM`bb` cases. Throwaway schemas only.
-- A. names
CREATE DATABASE `rvtrail `;
CREATE DATABASE `rvtrailtab	`;
CREATE DATABASE `rv.dot`; CREATE DATABASE `rv/*c`; CREATE DATABASE `rv--c`; CREATE DATABASE `rv#c`;
CREATE DATABASE `rv sp`; CREATE DATABASE `rv"q`; CREATE DATABASE `rv``bt`; CREATE DATABASE `rv\bs`;
CREATE DATABASE bb; CREATE DATABASE `a``b`; CREATE DATABASE `aa``bb`; CREATE DATABASE rvpeer;
SELECT SCHEMA_NAME, HEX(SCHEMA_NAME) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'rv%' OR SCHEMA_NAME IN ('bb','a`b','aa`bb') ORDER BY SCHEMA_NAME;
CREATE TABLE `rv.dot`.owned (id INT); INSERT INTO `rv.dot`.owned VALUES (1);
CREATE TABLE `rv/*c`.owned (id INT); INSERT INTO `rv/*c`.owned VALUES (1);
CREATE TABLE `rv--c`.owned (id INT); INSERT INTO `rv--c`.owned VALUES (1);
CREATE TABLE `rv#c`.owned (id INT); INSERT INTO `rv#c`.owned VALUES (1);
CREATE TABLE `rv sp`.owned (id INT); INSERT INTO `rv sp`.owned VALUES (1);
CREATE TABLE `rv"q`.owned (id INT); INSERT INTO `rv"q`.owned VALUES (1);
CREATE TABLE `rv``bt`.owned (id INT); INSERT INTO `rv``bt`.owned VALUES (1);
CREATE TABLE `rv\bs`.owned (id INT); INSERT INTO `rv\bs`.owned VALUES (1);
CREATE TABLE bb.owned (id INT); INSERT INTO bb.owned VALUES (1),(2);
CREATE TABLE `a``b`.owned (id INT); INSERT INTO `a``b`.owned VALUES (1),(2),(3),(4),(5),(6),(7);
CREATE TABLE `aa``bb`.owned (id INT); INSERT INTO `aa``bb`.owned VALUES (1),(2),(3),(4),(5);
CREATE TABLE rvpeer.src (id INT);
DELIMITER //
-- B. a peer naming each selected-candidate database as qualifier
CREATE PROCEDURE rvpeer.q_dot() SELECT COUNT(*) AS q_dot FROM `rv.dot`.`owned` //
CREATE PROCEDURE rvpeer.q_block() SELECT COUNT(*) AS q_block FROM `rv/*c`.`owned` //
CREATE PROCEDURE rvpeer.q_dash() SELECT COUNT(*) AS q_dash FROM `rv--c`.`owned` //
CREATE PROCEDURE rvpeer.q_hash() SELECT COUNT(*) AS q_hash FROM `rv#c`.`owned` //
CREATE PROCEDURE rvpeer.q_space() SELECT COUNT(*) AS q_space FROM `rv sp` . `owned` //
CREATE PROCEDURE rvpeer.q_dquote() SELECT COUNT(*) AS q_dquote FROM `rv"q`.`owned` //
CREATE PROCEDURE rvpeer.q_backtick() SELECT COUNT(*) AS q_backtick FROM `rv``bt`.`owned` //
CREATE PROCEDURE rvpeer.q_backslash() SELECT COUNT(*) AS q_backslash FROM `rv\bs`.`owned` //
-- C. Codex second P2: peers whose names contain a backtick, each naming its own table
CREATE PROCEDURE `a``b`.own() SELECT COUNT(*) AS own_a_bt_b FROM `a``b`.`owned` //
CREATE PROCEDURE `aa``bb`.own() SELECT COUNT(*) AS own_aa_bt_bb FROM `aa``bb`.`owned` //
CREATE TRIGGER `a``b`.t_own BEFORE INSERT ON `a``b`.owned FOR EACH ROW SET @t_own = (SELECT COUNT(*) FROM `a``b`.`owned`) //
-- D. coordinator's fail-open counterexample: no separator before the opening backtick
CREATE PROCEDURE rvpeer.nosep() SELECT COUNT(*) AS nosep FROM`bb`.`owned` //
CREATE TRIGGER rvpeer.t_nosep BEFORE INSERT ON rvpeer.src FOR EACH ROW SET @t_nosep = (SELECT COUNT(*) FROM`bb`.`owned`) //
CREATE PROCEDURE rvpeer.nosep2() SELECT COUNT(*) AS nosep2 FROM(SELECT id FROM`bb`.`owned`)x //
DELIMITER ;
-- E. ANSI_QUOTES spellings
SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES');
DELIMITER //
CREATE PROCEDURE rvpeer.ansi_dq() SELECT COUNT(*) AS ansi_dq FROM "rv""q"."owned" //
CREATE PROCEDURE rvpeer.ansi_bb() SELECT COUNT(*) AS ansi_bb FROM"bb"."owned" //
DELIMITER ;
SET SESSION sql_mode = DEFAULT;
SELECT ROUTINE_SCHEMA, ROUTINE_NAME, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA IN ('rvpeer','a`b','aa`bb') ORDER BY ROUTINE_SCHEMA, ROUTINE_NAME;
SELECT TRIGGER_SCHEMA, TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ('rvpeer','a`b') ORDER BY TRIGGER_SCHEMA, TRIGGER_NAME;
-- resolution: which table each object actually reads
CALL `a``b`.own(); CALL `aa``bb`.own(); CALL rvpeer.nosep(); CALL rvpeer.nosep2(); CALL rvpeer.q_backtick(); CALL rvpeer.q_dquote(); CALL rvpeer.q_block(); CALL rvpeer.q_hash(); CALL rvpeer.q_dash(); CALL rvpeer.q_dot(); CALL rvpeer.q_space(); CALL rvpeer.ansi_dq(); CALL rvpeer.ansi_bb();
INSERT INTO rvpeer.src VALUES (1); SELECT @t_nosep;
INSERT INTO `a``b`.owned VALUES (8); SELECT @t_own;

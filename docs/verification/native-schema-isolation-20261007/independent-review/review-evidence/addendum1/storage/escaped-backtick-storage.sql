-- Reviewer check: how MySQL 8.4.11 stores bodies containing doubled (escaped) backticks and versioned comments.
DROP DATABASE IF EXISTS rvsel; DROP DATABASE IF EXISTS rvpeer; CREATE DATABASE rvsel; CREATE DATABASE rvpeer;
CREATE TABLE rvsel.owned (id INT); INSERT INTO rvsel.owned VALUES (1),(2),(3);
CREATE TABLE rvpeer.src (id INT);
CREATE TABLE rvpeer.`a``b` (id INT);
DELIMITER //
CREATE PROCEDURE rvpeer.e1() SELECT (SELECT COUNT(*) FROM `a``b`) AS x, (SELECT COUNT(*) FROM `rvsel`.`owned`) AS e1 //
CREATE PROCEDURE rvpeer.e2() SELECT (SELECT COUNT(*) FROM `a``b`) AS x, `a``b`.id, (SELECT COUNT(*) FROM `a``b`), (SELECT COUNT(*) FROM rvsel.owned) AS e2 FROM `a``b` //
CREATE PROCEDURE rvpeer.e3() SELECT 'it''s' AS s, "q""q" AS d, (SELECT COUNT(*) FROM rvsel . owned) AS e3 //
CREATE PROCEDURE rvpeer.e4() SELECT q.`x``y` AS z FROM (SELECT 1 AS `x``y`) q WHERE (SELECT COUNT(*) FROM `rvsel`.`owned`) > 0 //
CREATE TRIGGER rvpeer.t1 BEFORE INSERT ON rvpeer.src FOR EACH ROW SET @t1 = (SELECT COUNT(*) FROM `a``b`) + (SELECT COUNT(*) FROM `rvsel`.`owned`) //
CREATE PROCEDURE rvpeer.v1() SELECT COUNT(*) AS v1 FROM `rvsel` /*!.*/ `owned` //
CREATE PROCEDURE rvpeer.v2() SELECT COUNT(*) AS v2 FROM /*!80000 rvsel */ . owned //
CREATE PROCEDURE rvpeer.v3() SELECT COUNT(*) AS v3 FROM /*!99999 rvsel. */ owned //
DELIMITER ;
SELECT ROUTINE_NAME, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='rvpeer' ORDER BY ROUTINE_NAME;
SELECT TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='rvpeer';
CALL rvpeer.e1(); CALL rvpeer.e3(); CALL rvpeer.v1(); CALL rvpeer.v2();
CALL rvpeer.v3();
DROP DATABASE rvsel; DROP DATABASE rvpeer;
SHOW DATABASES;

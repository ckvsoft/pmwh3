-- 3.0.83: General menu header + box-0 drift cleanup
--
-- 1) The menu helper (pmwh3menu_helper) only emits a box when its
--    sort=0 header row exists ($hasMainAccess flips exclusively on
--    the header promote). The 3.0.82 baseline lacked the General
--    header for box 0 "by convention" -- so EVERY baseline-only
--    install (kvasny 2026-09-28) lost the whole General box.
--    Insert it for existing installs; fresh installs get it from
--    the fixed baseline (this file is stamped, never played, on
--    the fresh path).
--
-- 2) Legacy chain migrations moved box-0 rows by inserting NEW
--    positions without removing the old ones, so long-lived
--    installs (service) show "Traffic" and "Packages" twice
--    (sort 25+40 / 50+70). Delete the drifted duplicates. The
--    deletes are link-scoped so installs that legitimately use
--    those slots for OTHER rows (kvasny has "Session list" at
--    0:40) are untouched.

INSERT IGNORE INTO `pmwh3_menu` (`name`, `link`, `box`, `sort`, `hide`, `icon`, `permission`)
VALUES ('General', '', 0, 0, 'N', 'menu_general.png', 'view_menu_general');

DELETE FROM `pmwh3_menu`
 WHERE box = 0 AND sort = 40 AND link = 'general/traffic';

DELETE FROM `pmwh3_menu`
 WHERE box = 0 AND sort = 70 AND link = 'package/overview';

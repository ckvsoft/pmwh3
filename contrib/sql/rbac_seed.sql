-- rbac_seed.sql -- RBAC-Startstruktur fuer pmwh3-Rollen (Reseller/Customer).
--
-- Quelle: die pmwh2-migrierte, LIVE-erprobte Struktur des service-Installs
-- (framework-DB cevian, Export 2026-09-28: Reseller=0, Customer=0 Grants).
--
-- Wird von InstallBootstrap::seedRbac() gegen die FRAMEWORK-DB gespielt
-- (guard ueber pmwh3_configuration RBAC_SEED_VERSION), idempotent:
--   * permissions: INSERT IGNORE auf UNIQUE permKey
--   * role_perms:  Zuordnung ueber roleName/permKey (NICHT ueber IDs --
--     AUTO-INCREMENT-IDs unterscheiden sich je Installation)
--
-- Danach regiert wie bei pmwh2 die DB + die Gruppenverwaltungs-UI
-- (Ansicht/Gruppen): Aenderungen dort sind die Wahrheit, der Seed nur
-- der Startzustand fuer frische Installationen.

-- 1) Permissions-Katalog sicherstellen
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.change_permissions', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.create_customer', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.create_domain', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.create_group', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.delete_customer', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.delete_domain', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.delete_group', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.edit_customer', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.edit_domain', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.edit_email', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_all_customers', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_customer_count', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_customer_creator', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_customer_limits', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_customers', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_customers', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_customers_overview', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_databases', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_databases_overview', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_databases_phpmyadmin', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_domains', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_domains_overview', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_email', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_email_catchall', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_email_email', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_email_filtering', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_email_forward', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_email_overview', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_ftp', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_ftp_accounts', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_ftp_overview', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_general_overview', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_general_password', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_general_traffic', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_tools', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_tools_applications', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_tools_backup', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_tools_errorlog', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_tools_news', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_tools_objgroups', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_menu_tools_overview', 'pmwh3', '', 1);
INSERT IGNORE INTO permissions (permKey, module, permName, is_used) VALUES ('pmwh3.view_permissions', 'pmwh3', '', 1);

-- 2) Grants je Rolle (name-basiert, idempotent)
CREATE TEMPORARY TABLE _rbac_seed (roleName VARCHAR(64) NOT NULL, permKey VARCHAR(100) NOT NULL);
INSERT INTO _rbac_seed (roleName, permKey) VALUES
  ('Customer', 'pmwh3.edit_email'),
  ('Customer', 'pmwh3.view_all_customers'),
  ('Customer', 'pmwh3.view_customer_count'),
  ('Customer', 'pmwh3.view_customer_creator'),
  ('Customer', 'pmwh3.view_customer_limits'),
  ('Customer', 'pmwh3.view_customers'),
  ('Customer', 'pmwh3.view_menu_customers_overview'),
  ('Customer', 'pmwh3.view_menu_domains'),
  ('Customer', 'pmwh3.view_menu_domains_overview'),
  ('Customer', 'pmwh3.view_menu_email'),
  ('Customer', 'pmwh3.view_menu_email_catchall'),
  ('Customer', 'pmwh3.view_menu_email_email'),
  ('Customer', 'pmwh3.view_menu_email_filtering'),
  ('Customer', 'pmwh3.view_menu_email_forward'),
  ('Customer', 'pmwh3.view_menu_email_overview'),
  ('Customer', 'pmwh3.view_menu_ftp'),
  ('Customer', 'pmwh3.view_menu_ftp_accounts'),
  ('Customer', 'pmwh3.view_menu_ftp_overview'),
  ('Customer', 'pmwh3.view_menu_general_overview'),
  ('Customer', 'pmwh3.view_menu_general_password'),
  ('Customer', 'pmwh3.view_menu_general_traffic'),
  ('Reseller', 'pmwh3.change_permissions'),
  ('Reseller', 'pmwh3.create_customer'),
  ('Reseller', 'pmwh3.create_domain'),
  ('Reseller', 'pmwh3.create_group'),
  ('Reseller', 'pmwh3.delete_customer'),
  ('Reseller', 'pmwh3.delete_domain'),
  ('Reseller', 'pmwh3.delete_group'),
  ('Reseller', 'pmwh3.edit_customer'),
  ('Reseller', 'pmwh3.edit_domain'),
  ('Reseller', 'pmwh3.edit_email'),
  ('Reseller', 'pmwh3.view_customer_limits'),
  ('Reseller', 'pmwh3.view_customers'),
  ('Reseller', 'pmwh3.view_menu_customers'),
  ('Reseller', 'pmwh3.view_menu_customers_overview'),
  ('Reseller', 'pmwh3.view_menu_databases'),
  ('Reseller', 'pmwh3.view_menu_databases_overview'),
  ('Reseller', 'pmwh3.view_menu_databases_phpmyadmin'),
  ('Reseller', 'pmwh3.view_menu_domains'),
  ('Reseller', 'pmwh3.view_menu_domains_overview'),
  ('Reseller', 'pmwh3.view_menu_email'),
  ('Reseller', 'pmwh3.view_menu_email_catchall'),
  ('Reseller', 'pmwh3.view_menu_email_email'),
  ('Reseller', 'pmwh3.view_menu_email_filtering'),
  ('Reseller', 'pmwh3.view_menu_email_forward'),
  ('Reseller', 'pmwh3.view_menu_email_overview'),
  ('Reseller', 'pmwh3.view_menu_ftp'),
  ('Reseller', 'pmwh3.view_menu_ftp_accounts'),
  ('Reseller', 'pmwh3.view_menu_ftp_overview'),
  ('Reseller', 'pmwh3.view_menu_general_overview'),
  ('Reseller', 'pmwh3.view_menu_general_traffic'),
  ('Reseller', 'pmwh3.view_menu_tools'),
  ('Reseller', 'pmwh3.view_menu_tools_applications'),
  ('Reseller', 'pmwh3.view_menu_tools_backup'),
  ('Reseller', 'pmwh3.view_menu_tools_errorlog'),
  ('Reseller', 'pmwh3.view_menu_tools_news'),
  ('Reseller', 'pmwh3.view_menu_tools_objgroups'),
  ('Reseller', 'pmwh3.view_menu_tools_overview'),
  ('Reseller', 'pmwh3.view_permissions')
;

INSERT IGNORE INTO role_perms (roleID, permID, value)
SELECT r.id, p.id, 1
  FROM _rbac_seed s
  JOIN roles r ON r.module = "pmwh3" AND r.roleName = s.roleName
  JOIN permissions p ON p.module = "pmwh3" AND p.permKey = s.permKey;

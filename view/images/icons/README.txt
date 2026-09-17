Icon themes
===========

This directory holds icon-theme subfolders. The ICON_THEME option
(Options > Layout) picks which subfolder is used; the menu helper
loads each menu row's `icon` filename (e.g. menu_databases.png)
from the active theme folder.

Themes that came from pmwh2:
   16x16-CC/      Creative Commons icon set (default)
   16x16-kde/     KDE icon set

The PNGs themselves are NOT shipped in this zip. Copy them from
your old pmwh2 install:

   cp -r /path/to/pmwh2/images/icons/16x16-CC/*.png \
         /vhome/ckvsoft/ckvsoft.at/service/cevian/modules/pmwh3/view/images/icons/16x16-CC/

   cp -r /path/to/pmwh2/images/icons/16x16-kde/*.png \
         /vhome/ckvsoft/ckvsoft.at/service/cevian/modules/pmwh3/view/images/icons/16x16-kde/

Used filenames (from pmwh3_menu.icon column on existing installs):
   menu_overview.png            menu_password.png
   menu_traffic.png             menu_message.png   menu_messages.png
   menu_sessions.png            menu_packages.png   menu_groups.png
   menu_customers.png           menu_domains.png   menu_domaincheck.png
   menu_databases.png           menu_phpmyadmin.png
   menu_email.png               menu_forward.png   menu_catchall.png
   menu_filtering.png           menu_blacklist.png
   menu_ftp.png                 menu_ftp_accounts.png
   menu_tools.png               menu_errorlog.png   menu_backup.png
   menu_news.png                menu_applications.png
   menu_objgroups.png           menu_options.png   menu_layout.png
   menu_dns.png                 menu_logout.png     menu_session_list.png

If a row's icon file is missing in the active theme, the helper
falls back to the icons/ root (which has the flat menu_logout.png)
and otherwise renders the menu row without an icon -- so the UI
keeps working even before the icons are copied over.

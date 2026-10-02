# Hostinger setup

The original package fell back to localhost/XAMPP credentials (`root`, blank password, database `inventory_forecasting_system`) because `config/config.local.php` was not present. That causes the message: **The inventory database is unavailable.**

## Install / repair

1. In Hostinger hPanel, open **Databases > MySQL Databases** and note the database name, database user, and password. The database host is normally `localhost` unless hPanel shows another host.
2. Open phpMyAdmin for that database.
3. Import `database/inventory_forecasting_system_hostinger.sql`. This is your supplied localhost database dump, adjusted for shared hosting by removing the `root@localhost` stored-procedure definer.
4. Upload the application files to your domain/subdomain document root.
5. Visit `/setup_database.php` in your browser, enter the Hostinger database credentials, and click **Test Connection & Save Configuration**.
6. When the connection and required tables are valid, the setup page creates `config/config.local.php` and redirects to the login page.
7. After successful setup, rename or delete `setup_database.php` if desired.

If the application is installed in a folder rather than the document root, set Base URL to the folder, e.g. `/inventory/`.

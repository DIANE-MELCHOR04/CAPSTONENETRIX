# MariaDB 10.4 Import Fix

Target database: MariaDB 10.4.x.

The import runtime now performs read-only schema verification, uses a MariaDB-compatible PDO prepare mode, and uses simpler duplicate-reference SQL with PHP-side prefix checks. No database migration is required if `database/import_repair_upgrade.sql` has already been imported successfully.

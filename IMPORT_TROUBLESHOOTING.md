# Import Troubleshooting — MariaDB 10.4 compatibility build

This build targets the MariaDB 10.4 server shown by the supplied database dump.

## What changed
- Normal import requests no longer execute CREATE TABLE or ALTER TABLE.
- Import pages use PDO emulated prepares for MariaDB 10.4 compatibility. Bound values are still quoted by PDO.
- Stock duplicate-reference checks no longer place parameter markers inside LEFT(), CHAR_LENGTH(), CONCAT(), or ABS() expressions.
- Schema verification uses SHOW COLUMNS only.
- If MariaDB still returns error 1064, the visible error now includes the relevant `near: ...` fragment so the exact query can be identified immediately.

## Required schema
Run `database/import_repair_upgrade.sql` once in phpMyAdmin if the required import columns/tables are missing. After that, the website only verifies the schema and does not try to modify it.

## If an error remains
Copy the full red error message. In this build, a 1064 error should say `MariaDB rejected one import query near: ...`; that fragment identifies the remaining incompatible SQL.


## MariaDB 10.4 error near `row_number`

MariaDB 10.4 recognizes `ROW_NUMBER` as a window-function keyword. Earlier builds of the importer created the staging columns correctly with quoted identifiers, but some runtime INSERT/ORDER BY statements referenced `row_number` without backticks. This build quotes the identifier in both `bulk_import_rows` and `inventory_import_rows`. No database migration is required for this fix.

# Bulk XLSX / CSV Import Guide

The system now includes bulk spreadsheet import panels inside the relevant module pages.

## Included imports

- Materials
- Sites
- Locations
- Suppliers
- Stock Counts
- Material Requests
- Purchase Requests
- Transfers
- Returns
- Adjustments
- Damaged Materials
- Supplier Deliveries

Each import panel provides a **Download Template** button and accepts `.xlsx` or `.csv` files up to 5 MB / 1,000 data rows.

## Safe workflow

1. Download the template from the module page.
2. Keep the header names unchanged.
3. Fill or replace the sample row(s).
4. Upload the spreadsheet.
5. Review the validation preview.
6. Confirm only when every row is valid.

The system validates material codes, site/location codes, supplier/unit/category references, quantities, statuses, workflow grouping, purchase-order remaining quantities, and duplicate material lines before confirmation.

## Workflow behavior

- Materials: uses the existing material approval rules for the current user role.
- Stock Counts: submitted for review; variance is calculated against system quantity.
- Material Requests: created as drafts grouped by `request_group`.
- Purchase Requests: created as drafts grouped by `request_group`.
- Transfers: submitted for manager approval.
- Returns: submitted; inventory is not changed until the normal return posting action.
- Adjustments: submitted for approval; inventory is not changed by the import itself.
- Damaged Materials: submitted for approval; no write-off occurs during import.
- Supplier Deliveries: validated against open PO quantities and posted only after the preview is confirmed.

## Existing databases

The system attempts to create its new `bulk_import_rows` staging table automatically when the importer is first used. A manual, safe-to-run migration is also included at:

`database/bulk_import_upgrade.sql`

If XLSX upload reports that PHP ZIP or XMLReader is unavailable, enable the PHP `zip` and XML extensions in the local/server PHP configuration. CSV import remains available.

## Duplicate protection

- Exact re-imports are blocked after a batch has already been posted. Bulk-import fingerprints are based on spreadsheet content, so renaming or re-saving the same data does not bypass the check.
- Stock-In and Stock-Out block exact duplicates using reference number + material + location + quantity. The same reference may be reused for different material lines, but it is shown as a warning for review.
- Sites, locations, suppliers, and materials reject matching master-data duplicates case-insensitively.
- Grouped imports reject the same material twice inside one request/transfer/return/adjustment/damage/delivery group.
- Supplier deliveries reject a delivery reference already posted against the same purchase order, and reject the same PO/reference across multiple groups in one spreadsheet.
- Duplicate checks are repeated when posting where inventory could change, protecting against a record being added after spreadsheet preview.

# Duplicate Protection

This build adds duplicate handling to both manual entry and spreadsheet imports without changing the existing database structure.

## Manual entry

- **Materials**: existing exact material duplicate rules remain in place; possible variants still require confirmation.
- **Sites**: same site name is blocked case-insensitively.
- **Locations**: same location name is blocked case-insensitively.
- **Suppliers**: same company name is blocked case-insensitively instead of silently updating the old record.
- **Stock-In / Stock-Out**:
  - exact same reference + material + location + quantity is blocked;
  - reusing a reference for a different legitimate line requires the user to tick the intentional-reference-reuse confirmation;
  - the exact duplicate cannot be bypassed with that checkbox.
- **Supplier Deliveries**: a delivery reference cannot be posted twice against the same purchase order.
- **Material Requests, Purchase Requests, Transfers, Stock Counts, Returns, Adjustments, Damage Reports**: the same material cannot appear twice inside one manual transaction.

## Excel / CSV imports

- Stock-In / Stock-Out detect exact duplicate rows inside the uploaded file.
- Stock-In / Stock-Out compare reference + material + location + quantity against already-posted inventory movements.
- Reused references for different material lines are shown as warnings in the preview rather than automatically rejected.
- Stock duplicate checks run again at final confirmation to prevent a race between preview and posting.
- Materials, sites, locations, suppliers, and stock counts already reject duplicate row identities in one spreadsheet.
- Grouped imports reject a repeated material inside the same request/transfer/return/adjustment/damage/delivery group.
- Supplier-delivery imports reject an already-used delivery reference for the same PO and reject the same PO/reference assigned to multiple groups in one spreadsheet.
- Bulk imports store a normalized content fingerprint. Re-uploading already-posted spreadsheet content is blocked even when the file has been renamed or re-saved.

## Intent

Exact duplicates are blocked. Potentially legitimate repeated references are warned/confirmed rather than silently rejected, so multi-line delivery receipts and issue slips remain usable.

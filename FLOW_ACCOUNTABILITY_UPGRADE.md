# METRICCON Inventory — Flow & Accountability Upgrade

This build upgrades the inventory workflow from request monitoring into an end-to-end accountable stock flow. QR/barcode features were intentionally not added.

## Important: database upgrade for an existing installation

Before using the new workflow on your existing Hostinger database:

1. Back up the current database.
2. Import `database/flow_accountability_upgrade.sql` **once** into the same database used by the website.
3. Upload/replace the PHP system files.
4. Log out and log back in so the latest role permissions are reflected in the session.

For a completely fresh installation, `database/inventory_forecasting_system_hostinger.sql` already contains the upgrade at the end of the schema.

The upgrade SQL is designed for MariaDB 10.4+ / Hostinger. Legacy purchase orders that do not yet have an intended receiving warehouse are backfilled to the default active warehouse when possible.

## What changed

### 1. Company-wide warehouse sourcing before procurement

Material Request approval now checks every active `warehouse` location. The default warehouse is used first, then other warehouses with available stock. Procurement only receives the true company-wide shortage.

A request can therefore be partially reserved across more than one warehouse. Warehouse Personnel releases only against the actual reservation/location records.

### 2. Site Inventory

When the Site Engineer confirms a material release, the confirmed quantity is posted into the project's site-stock location. Site Inventory can then be used for:

- Site consumption
- Return to warehouse
- Site/source transfers
- Damage reporting
- Recent site-stock movement history

A Site Engineer account must have a valid `site_id`, and that site must have an active location with `type = 'site'`.

### 3. Receipt discrepancies

If released quantity and actually received quantity differ, the system creates a receipt discrepancy. Warehouse Manager can resolve it as:

- Later received
- Returned to source warehouse
- Damage in transit / replacement required
- Authorized write-off / replacement required

Repeated receipt confirmation posts only the new difference, preventing double-counting.

### 4. Strict state transitions

Material Request approval/rejection is now enforced server-side. A stale URL cannot re-approve an already reserved/released request.

The same principle is applied to transfer, count, exception and posting actions: the record must be in the expected current state before the action is accepted.

### 5. Controlled exception workflows

`Workflow Control` now governs:

- Cancellation / Void requests
- Return to Supplier
- Disposal / Write-Off

Operational inventory effects happen at the controlled posting/shipping step. Self-approval is blocked where applicable.

### 6. Emergency Stock-Out

Unrestricted manual stock-out is intentionally replaced by an exception workflow:

1. Warehouse Personnel submits an Emergency Stock-Out request and reason/reference.
2. Warehouse Manager approves or rejects it.
3. Stock is deducted only after approval.

The old bulk/manual `post_stock_out` path is intentionally not granted to normal roles because it would bypass this approval control.

### 7. Material Request procurement traceability

Shortage lines can now show linked procurement information directly in the request:

- PR number/status
- PO number
- Supplier
- Ordered quantity
- Received quantity
- Expected delivery

Duplicate active PR creation for the same request shortage remains blocked.

### 8. PO receiving warehouse control

A Purchase Order now has an intended receiving warehouse. Supplier Delivery inherits and locks that warehouse so the receiving location cannot casually be changed during receipt.

Supplier-delivered stock purchased for a Material Request is automatically allocated/reserved back to the originating request shortage. Excess delivery remains ordinary available warehouse stock.

### 9. Stock Count reject/recount

Warehouse Manager can reject a submitted stock count and require a recount. The recount references the rejected count so both records remain traceable.

### 10. Evidence / attachments

Supporting files can be attached to relevant operational records, including:

- Material Requests
- Purchase Orders
- Supplier Deliveries
- Damage Reports
- Material Returns
- Stock Counts

Allowed types: PDF, JPG/JPEG, PNG, XLS, XLSX. Maximum size: 8 MB per file.

Attachment downloads are permission-checked. Site Engineers are restricted to records belonging to their own site/ownership scope.

Ensure the PHP process can create/write `storage/attachments/` on the server.

### 11. Alerts and operational queues

The dashboard and notification rules now surface items such as:

- Receipt discrepancies
- Site receipts overdue more than 24 hours
- Emergency Stock-Out approvals
- Cancellation/void decisions
- Supplier returns awaiting approval or shipment
- Write-offs awaiting approval or posting
- Existing request, procurement, transfer, stock-count, damage, adjustment and low-stock queues

## Recommended end-to-end flow

Site Engineer creates Material Request
→ Warehouse Manager approves
→ system checks all warehouses
→ reserve available company stock
→ Procurement receives only true shortage
→ PR approval
→ PO with intended receiving warehouse
→ supplier delivery
→ shortage automatically reserved to original MR
→ Warehouse Personnel releases reserved stock
→ Site Engineer confirms receipt
→ Site Inventory
→ consumption / return / transfer / damage

Receipt variance branches to a Warehouse Manager discrepancy-resolution workflow.

## Role summary

### Site Engineer
- Create and monitor own requests
- Confirm material receipts
- View/manage own Site Inventory
- Record site consumption
- Create site-stock transfers
- Create returns from own site
- Report site material damage

### Warehouse Personnel
- Release actual reserved stock
- Receive supplier deliveries
- Create/dispatch/receive operational transfers as permitted
- Post material returns
- Submit Emergency Stock-Out
- Submit/post governed workflow exceptions as permitted

### Warehouse Manager
- Approve Material Requests
- Approve transfers/damage/adjustments/counts
- Reject/recount stock counts
- Approve Emergency Stock-Out
- Resolve receipt discrepancies
- Review cancellation/void, supplier-return and write-off decisions

### Procurement
- Create/manage PRs and POs
- Select intended PO receiving warehouse
- Track supplier/ETA/order/receipt progress

### President
- Existing procurement approvals remain in place for both PR and PO in this build
- Can participate in governed workflow-control decisions

## Notes on the approval model

The existing President approval checkpoints for both Purchase Requests and Purchase Orders were preserved rather than removed. This avoids silently changing the company's governance policy. If the company later decides that PO re-approval is only necessary when supplier, price, quantity or total differs from the approved PR, that can be implemented as a separate policy change.

## Suggested smoke test after deployment

Use a test material and small quantities:

1. Put stock into two active warehouses.
2. Submit an MR larger than Warehouse A stock but smaller than A+B combined.
3. Approve the MR and confirm reservations are split before any shortage is created.
4. Release one reserved portion and confirm Site Inventory increases after Site Engineer receipt.
5. Confirm a short receipt and resolve its discrepancy.
6. Create an MR that truly exceeds all warehouse stock; create PR → approve → PO → receive supplier delivery; verify the new stock is allocated back to the MR.
7. Submit an Emergency Stock-Out and verify stock does not change until manager approval.
8. Submit a stock count, reject it, and create a recount.
9. Submit a Return to Supplier / Write-Off and verify inventory changes only at the controlled posting step.
10. Upload and download evidence using the appropriate role and confirm unauthorized site access is denied.

## Validation performed in this package

- PHP syntax validation across all PHP files.
- Static consistency checks for the new permissions, migration objects and workflow endpoints.
- ZIP integrity validation before delivery.

A live production database was not modified or executed from this build environment. Import the migration on a backup/test copy first when possible.

# Material Request / Procurement Workflow Fixes

Implemented in this build based on the identified Material Request inconsistencies.

## Corrected behavior

1. **Mixed reservation status**
   - A line with some stock reserved and some shortage is now `partially_reserved`.
   - The UI displays this as **Partially Reserved — Procurement Needed**.
   - A fully reserved line is displayed as **Ready for Release**.

2. **Release action visibility**
   - **Release Reserved Portion** only appears when an active `material_reservations` record has `reserved_qty > released_qty`.

3. **No empty releases**
   - The backend requires at least one positive, valid release quantity before creating the release header.

4. **Reservation location is authoritative**
   - Warehouse Personnel can no longer release reserved material from an arbitrary warehouse.
   - The release page only offers locations that actually contain active reservations for the request.
   - Release quantities are keyed to individual `material_reservations` rows and update each reservation's `released_qty` and status.

5. **Procurement In Progress**
   - Material Request shortage lines are linked to their active Purchase Request using the existing `purchase_request_items.source_item_id` relationship.
   - The MR screen displays **Procurement In Progress — PR-...** while an active linked PR exists.

6. **Duplicate Purchase Request protection**
   - The backend rejects a second active PR for the same Material Request shortage.
   - The Purchase Request creation screen disables lines already under active procurement.

7. **Supplier delivery returns to the original MR**
   - When a PO item came from a Material Request shortage, received stock is automatically allocated back to that original request line.
   - A new `material_reservations` row is created at the receiving location.
   - `reserved_qty`, `shortage_qty`, item status, request status, and inventory reserved quantity are recalculated automatically.
   - Excess delivery beyond the remaining MR shortage stays as normal available inventory.

8. **Queue consistency**
   - Ready-for-release dashboard counts now use actual active reservation balances instead of relying only on the request-level status.
   - Procurement shortage counts and shortage notifications exclude lines already under an active PR.

## Database compatibility

No new database column or table is required. The fixes use the existing:

- `material_reservations.released_qty`
- `material_reservations.status`
- `purchase_request_items.source_type`
- `purchase_request_items.source_item_id`

This keeps the build compatible with the current database schema.


## Release button visibility refinement
- Warehouse Personnel now sees **Release Reserved Portion** directly in the Material Requests table whenever a request has unreleased reserved stock.
- The Material Releases page now has a **Requests Ready for Release** queue with the same action.
- A request can remain **Partially Reserved — Procurement Needed** and still be released for its reserved portion.
- Reservation rows with remaining quantity are treated as releasable unless explicitly cancelled, preventing stale reservation status flags from hiding valid stock.

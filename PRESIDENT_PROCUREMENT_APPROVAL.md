# President Procurement Approval Update

Procurement approval now belongs to the **President / Executive** role.

## Updated workflow

1. Procurement prepares and submits a purchase request.
2. The President approves or rejects the purchase request.
3. After approval, Procurement creates a purchase order.
4. The purchase order is submitted to the President.
5. The President approves or rejects the purchase order.
6. Only an approved purchase order becomes `open` and can receive supplier deliveries.

Warehouse managers and procurement users can no longer approve procurement requests or orders. Procurement users retain responsibility for preparing requests, choosing suppliers, creating orders, and recording deliveries after approval.

## Existing installation upgrade

Before deploying the updated PHP files to an existing installation, import this file once through phpMyAdmin:

`database/president_procurement_approval_upgrade.sql`

To enable traceable AI recommendation conversion, also import:

`database/ai_recommendation_conversion_upgrade.sql`

New installations can import `database/inventory_forecasting_system_hostinger.sql` directly because it already contains the updated purchase-order schema.

## AI recommendation conversion

- **Convert to Purchase Request** opens a pre-filled request form using the recommended material and quantity.
- Procurement can edit every field before submitting.
- **Link existing Purchase Request** connects a recommendation to an active request containing the same material.
- The recommendation becomes `converted` only after a request is successfully created or linked.
- Both screens provide links between the recommendation and its Purchase Request.

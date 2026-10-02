# Notification Permission + Clean Database Update

## Notification correction

Notifications now pass through the same role-permission rules as the page linked by the **Open** button.

- A notification is not created for a user who cannot access its destination page.
- Existing notifications with inaccessible links are automatically removed from that user's notification inbox/count.
- Site Engineer record alerts are additionally restricted to their own assigned site/request where applicable.
- Purchase Order due/overdue alerts are kept with Procurement rather than Warehouse Manager.
- Stock-count recount notifications return to the person who submitted the count instead of every Warehouse Personnel account.

## Clean starter database

Use `database/inventory_system_clean_master_data.sql` for a clean flow test.

The primary fresh-install file `database/inventory_forecasting_system_hostinger.sql` is also the same clean version in this package.

### Included starter/master data

- Application settings and document prefixes
- Material categories
- Units of measure
- 2 warehouse locations
- 2 project sites + project stockrooms
- 2 sample suppliers
- 6 sample materials
- User accounts for every system role
- A second Site Engineer account for site-to-site testing
- Master code sequence floors

### Intentionally empty

Material Requests, reservations, releases, Purchase Requests, Purchase Orders, supplier deliveries, inventory balances/movements, stock counts, returns, transfers, damage reports, adjustments, write-offs, cancellations, notifications, audit logs, forecasting data, and other operational history start empty.

This means you can test the workflow from the beginning without old transactions appearing in dashboards or notifications.

## Starter logins

All starter accounts use the temporary password:

`Metriccon@123`

| Username | Role | Site |
|---|---|---|
| admin | IT / System Administrator | — |
| manager | General Warehouse Manager | — |
| engineer_a | Site Engineer | Test Project A |
| engineer_b | Site Engineer | Test Project B |
| warehouse | Warehouse Personnel | — |
| procurement | Procurement Department | — |
| president | President / Executive | — |

Change these passwords before production use.

## Suggested clean-flow test

1. Log in as Warehouse Personnel and use Stock-In to add a small quantity to Central Warehouse and/or Secondary Warehouse.
2. Log in as `engineer_a` and create a Material Request.
3. Log in as Warehouse Manager and approve it.
4. Verify the system reserves across warehouses and sends only the true shortage to Procurement.
5. Continue PR -> President approval -> PO -> President approval -> supplier delivery if there is a shortage.
6. Release the reserved quantity as Warehouse Personnel.
7. Confirm receipt as `engineer_a` and verify Site Inventory.
8. Test site return, site transfer to Project B, discrepancy, damage, stock count, and exception workflows as needed.

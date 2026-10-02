# Role Access Refinement

This build applies least-privilege access in both navigation and server-side page/action checks.

## Administrator

- Keeps user administration, system settings, backups, audit logs, master-data maintenance, and workflow configuration.
- Cannot create or approve materials and cannot manage suppliers.

## General Warehouse Manager

- Keeps master data, request/transfer/count approvals, operational monitoring, and full warehouse reports.
- Does not execute transfers, returns, stock counts, adjustments, damage actions, procurement, or AI recommendation actions.

## Site Engineer

- Creates and views only their own site requests and confirms receipts for those requests.
- Views inventory only for their assigned site.
- Creates and views only their own site returns; cannot post returns into inventory.
- Calendar is limited to request-needed dates for the assigned site.

## Warehouse Personnel

- Handles stock-in, releases, counts, adjustments, damage records, transfers, returns, and supplier delivery receiving.
- Direct stock-out is removed; material issue must use the approved request/release workflow.
- Transfer creation, dispatch, and receipt are distinct capabilities.
- A transfer dispatcher cannot receive the same transfer; assigned-site checks apply to dispatch and receipt.
- Posts submitted material returns and receives approved supplier purchase orders.

## Procurement

- Manages suppliers, purchase requests/orders, procurement intelligence, and AI recommendation actions.
- Cannot receive supplier deliveries into inventory.
- Reports are limited to inventory/low-stock, procurement, forecasts, and AI recommendations.

## President

- Approves purchase requests and purchase orders and uses executive/procurement intelligence.
- Reports are limited to inventory/low-stock, procurement, forecasts, AI recommendations, and the executive report.
- Has no warehouse execution permissions.

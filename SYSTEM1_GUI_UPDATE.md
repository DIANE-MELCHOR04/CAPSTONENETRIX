# SYSTEM 1 GUI Update

This package keeps the existing inventory database, permissions, workflows, imports, forecasting, and page routes intact while applying the visual language of `SYSTEM 1`.

## Updated interface
- Dark navy SYSTEM 1-style sidebar with teal active states
- Cleaner navigation groups and quick actions
- Sticky white command/header bar
- Soft rounded cards and dashboard metrics
- Teal gradient primary buttons and simplified secondary buttons
- Cleaner filters, forms, status badges, and tables
- Responsive/mobile-friendly layout retained
- Login page rebuilt to match SYSTEM 1's split-screen experience
- Existing Notiflix, sidebar collapse, command search, and role-based navigation retained

## Theme file
The SYSTEM 1 look is isolated in:

`assets/css/system1-theme.css`

It is loaded last by `partials/assets.php`, making it safer to maintain without rewriting the existing operational CSS.

- Follow-up UI refresh: enlarged sidebar labels and added soft teal card/header/table accents.


## Follow-up refinement
- Sidebar navigation text changed to plain white; teal is reserved for icons, hover, and active accents.
- Procurement Intelligence now uses a dedicated permission and is visible/accessible only to Procurement and President roles.
- President access to Procurement Intelligence is view-only for Draft PR actions.
- Chart cards/canvases refreshed with soft teal backgrounds, borders, grid lines, shadows, and a teal-forward palette.

## Light teal table refinement
- Strengthened table headers with a soft teal gradient and darker teal labels.
- Added subtle alternating row backgrounds and a clearer teal hover state.
- Updated sortable-column indicators for visibility on light headers.
- Matched pagination/table controls to the light teal table component.
- Improved table borders, spacing, and editable line-item field focus states.

## AI Summary readability fix
- Fixed legacy white-on-light text conflict in AI Summary cards.
- AI Summary cards now use a soft light-teal gradient with dark navy/teal text.
- Updated title, eyebrow, summary text, bold text, and pulse/action-count colors for consistent contrast.

## President procurement approval workflow
- Removed procurement approval authority from the Warehouse Manager and Procurement roles.
- Added purchase-request and purchase-order approval authority to the President role.
- New purchase orders remain submitted until the President approves them.
- Supplier receiving is blocked until the purchase order has presidential approval.
- Existing databases must run `database/president_procurement_approval_upgrade.sql` once.

## Traceable AI conversion
- Replaced the status-only Converted action with **Convert to Purchase Request**.
- The conversion form is pre-filled with the recommended material, quantity, urgency, and AI reasoning.
- Procurement can link a recommendation to an existing active Purchase Request containing the same material.
- A recommendation is marked converted only after a Purchase Request is created or linked successfully.
- Existing databases must run `database/ai_recommendation_conversion_upgrade.sql` once.

## Warehouse Manager access refinement
- Kept Manage Master Data for the Warehouse Manager.
- Removed material creation and Audit Log access from the Warehouse Manager.
- Separated transfer approval from transfer creation, dispatch, and receiving.
- Separated stock-count approval from physical count entry and bulk import.
- Made AI Recommendations read-only for the Warehouse Manager; Procurement owns recommendation actions.
- Restricted Workflow Control to a dedicated Administrator permission instead of general report access.

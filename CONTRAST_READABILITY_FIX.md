# Contrast / Readability Fix

This update corrects legacy dark-theme text colors that remained after SYSTEM 1 converted shared panels to light/white surfaces.

## Fixed shared document summary
Affected pages include:
- `request_view.php`
- `release_view.php`

The shared `.ops-document-summary` component now uses dark readable text on the light SYSTEM 1 background for:
- Document title
- Remarks / description
- Eyebrow / document number
- Metadata labels
- Metadata values

## Additional audit
Checked the shared styling for:
- Monitoring/page hero cards
- Tables and table headers
- Status badges
- Alerts
- Primary / secondary / danger buttons
- Sidebar/navigation
- AI summary cards
- Forms and inputs

A light-surface contrast guard was also added for legacy hero `small` text so future content does not inherit white text on a light hero background.

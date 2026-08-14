# ARA Tech Admin — SaaS Architecture Refactor

## Audit summary

The admin surface contains 15 PHP entry points under `admin/`, but several are views of the same domain: Hotspot users/active sessions/vouchers/profiles, inventory/CSV, finances/reports/ads, and network status/logs. The current code already proves that Hotspot uses a tabbed workspace in `hotspot.php`, while the dashboard provides the target shell and KPI pattern.

## Target workspaces

- `/admin/index.php` — Executive dashboard: network health + business KPIs.
- `/admin/monitoring.php` — System Monitoring: network status and logs as tabs.
- `/admin/operations.php` — Operations: Hotspot and inventory as one operational workspace.
- `/admin/business.php` — Business: finances, sales reports and ads as one commercial workspace.
- `/admin/settings.php` — System configuration.

Legacy specialist pages remain available during migration so existing workflows do not break. New navigation points users to the canonical workspace instead of exposing every implementation page as a top-level destination.

## Component model

### Layout

`admin/header.php` and `admin/footer.php` are the only layout owners. Every authenticated workspace must use them.

### Reusable UI components to extract next

- `MetricCard`: KPI value, label, trend, optional status.
- `StatusPill`: ONLINE/OFFLINE/UNKNOWN state.
- `DataTable`: search, filters, pagination, empty/loading/error states.
- `FilterBar`: shared period/profile/status filters.
- `ActionBar`: primary action + secondary actions.
- `Timeline`: logs and operational events.
- `CommandStatus`: queued/processing/executed/failed Hotspot command state.

### Data patterns

Keep Supabase/PostgreSQL as the business/application source of truth for new functionality. Keep the existing MikroTik synchronization model: RouterOS pushes snapshots; mutations travel through `hotspot_commands`; the web UI must not depend on direct RouterOS connectivity.

## Migration sequence

1. Canonical workspaces and navigation (this branch).
2. Extract PHP sections from legacy specialist pages into reusable partials/components.
3. Convert Hotspot tables into one universal data-table component.
4. Merge status + logs into a real monitoring command center with health cards and event timeline.
5. Merge finances + reports + ads into the Business workspace with shared period filters and KPI cards.
6. Move inventory + CSV import into Operations as tabs/drawers.
7. Turn legacy pages into compatibility redirects only after the new workspaces have feature parity.
8. Delete obsolete entry points after a full regression pass.

## Non-regression constraints

- Preserve existing API routes and RouterOS synchronization contracts.
- Do not reintroduce direct Render -> RouterOS connections.
- Do not change database ownership/source-of-truth semantics during UI consolidation.
- Keep current authentication/session behaviour.

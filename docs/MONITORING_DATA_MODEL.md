# ARA Tech WiFi — Monitoring data sources

## Canonical sources

- Network/router state: `hotspot_snapshots`, populated by `api.php?route=push-status` from MikroTik.
- System logs: `router_logs`, populated by `api.php?route=push-logs` from MikroTik.

`hotspot_snapshots.received_at` is authoritative for freshness; snapshots older than 360 seconds are considered OFFLINE. Missing or unparseable timestamps are UNKNOWN.

The Monitoring UI reads these PostgreSQL/Supabase tables directly. It does not make an HTTP call from PHP back to its own `api.php` for rendering.

## Real vs unavailable data

The UI only displays metrics actually carried by the snapshot payload: identity, RouterOS version, uptime, CPU, memory, active sessions, users, snapshot age and synchronization time.

Internet, PoE switch and AP health are not inferred from a MikroTik heartbeat. They remain `NON_MONITORED` until a real telemetry source is added.

# Backup Snapshot — 2026-08-22

This folder is a point-in-time backup of the live TaskManager files.
Source of truth remains the files at their normal paths; this is a labeled restore point.

## Files & source commit hashes at time of backup

| File | Live path | Commit |
|------|-----------|--------|
| api_index.php | api/index.php | 350d89a23b28 |
| app_dashboard.html | app/dashboard.html | 7852f2482c56 |
| index.html | index.html | 490f6c14fbbb |
| app_login.html | app/login.html | 6b36b0197807 |

Flutter (separate repo taskmanager-tech-flutter): lib/main.dart @ 9654fc8a5bf5

## State captured
- Outstation claims: pin-to-pin travel (From/To per leg, auto-fill, round-trip auto-completion), one-way/round trip, customer-paid with net-payable computation, clean submit (respond-first + close), tech self-delete, My Claims by month, clean menu.
- Claim notifications: submit emails sales/manager/admin@bharatgps.com; approve/reject pushes technician.
- Tomorrow Assignments: tech panel (after-6PM gate IST, info popup, clean dialogs), admin Technician Assessment tab, 9PM IST nightly clear.
- Coins: stale installation task penalty (from assignment, stops on install/close, rule-start cutoff) + guidelines.
- replace_device backend endpoint (Troubleshoot device replacement) — UI pending.
- Back button delegated to web page via window.__handleAndroidBack (Flutter); anti-cache meta tags; WebView cache clear on launch.

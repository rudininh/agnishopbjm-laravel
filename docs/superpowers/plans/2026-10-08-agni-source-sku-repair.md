# Agni source SKU correction plan

- [x] Trace Gita and TikTok preview and submission paths; establish source-ID fallback defect.
- [x] Add failing planner, account-scoped resolver and frontend regressions; verify destination HTTP payloads and guards.
- [x] Implement shared source resolution/exact SKU copying and fresh submission validation.
- [x] Wire both previews, manual/empty SKU actions and Agni-only bulk generation guards.
- [x] Run affected tests, full backend/frontend suites and Vite build; review final diff.
- [x] GET-only live preview, publish assets with prior assets retained and verify HTTP hashes.
- [x] Record verified durable memory, verification docs and local commit; report operator flow.

Source context uses only the unique active Agni token shop. Target IDs are
request addresses, never source identity. No live SKU changes during verification.

# Agni destination catalog fetch plan

1. Test renderer: Agni-only controls, exact destination account calls, Agni cache reload, busy/mirror locks and truthful outcomes. Observe RED before code.
2. Implement two shortcuts in ShopeeStock using existing services and shared busy signals, preserving selected shop/filter state and existing work.
3. Run focused/full frontend tests and production build, scoped independent review, preserve assets and publish local frontend. GET-only HTTP/hash smoke; record verified memory. No live catalog fetch during tests, batch control, backend/schema changes or Git integration.

Completed:all3steps.262frontend tests,build and independent scoped review PASS. Published local index/assets with HTTP/hash checks,92prior assets/.htaccess preserved. No live catalog fetch or Git actions.

# Shopee Partner Key Expiry Redirect

## Purpose

When Shopee reports that the Live API Partner Key has expired while this application refreshes or exchanges a Shopee token, the dashboard must not expose Shopee's raw API error. It must direct the operator to the Shopee Open Platform Console, where the key can be reset.

## Scope

The change applies to Shopee Agni and any other configured Shopee account using the shared token connection flow. It covers both refresh-token and callback token-exchange responses. The `Tarik Token dari STB` action remains a token copy operation and does not call Shopee, so it cannot trigger this error or redirect.

## Design

The backend recognizes only Shopee's explicit `API partner key has expired` response. It replaces that response with a safe result: `status` and `error` are `partner_key_expired`, the message is Indonesian, and `redirect_url` points to the configured Shopee Open Platform Console. The existing dashboard token action already follows a response `redirect_url`; no frontend behavior change is needed.

The console URL is a non-secret configuration value, defaulting to `https://open.shopee.com/`. The application never constructs the normal Shopee authorization URL for this condition because it requires signing with the expired key and would fail again.

All other Shopee errors retain their present behavior. Responses do not return Shopee's raw expiry message or credential values.

## Validation

Feature tests will fake Shopee's expiry response through refresh and exchange flows, then assert a safe `partner_key_expired` response, console redirect URL, and absence of the raw API message. Existing non-expiry failures and normal authorization URL behavior remain covered by the related controller tests.

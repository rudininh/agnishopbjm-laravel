# STB Marketplace Token Sync Design

**Date:** 2026-08-16

## Goal

Make the STB the source of truth for active Shopee and TikTok credentials. The Laravel app on the PC pulls current credentials over the static LAN, stores a local working copy, and continues making marketplace API calls directly from the PC.

## Scope

- Export the current Shopee and TikTok token records from STB through a dedicated internal API.
- Pull, validate, and persist those records on the PC.
- Provide a manual dashboard action and scheduled PC-side refresh.
- Surface token-source and synchronization health without exposing credential values.

Out of scope:

- Proxying marketplace requests through STB.
- Replacing the existing Shopee/TikTok OAuth flows on STB.
- Showing raw access or refresh tokens in the browser, API responses to the frontend, or logs.

## Ownership and Data Flow

1. STB refreshes or receives marketplace tokens through its existing marketplace workflows.
2. PC calls the STB token-export endpoint with a dedicated bearer secret.
3. STB returns the newest usable records for configured Shopee accounts and TikTok shops.
4. PC validates account identity, timestamp, and expiry before updating its local token tables.
5. PC dashboard and marketplace jobs continue using the local database exactly as they do now.

The PC never deletes a usable local token merely because STB is unavailable or returns no record.

## API Contract

### STB endpoint

`GET /api/runtime/marketplace-token-sync`

The endpoint is disabled by default. When enabled, it accepts only an `Authorization: Bearer <STB_TOKEN_SYNC_TOKEN>` credential. This secret is distinct from both `STB_MAPPING_SYNC_TOKEN` and `GITASHOP_MASS_UPLOAD_STB_CONTROL_TOKEN`.

The payload contains only the token fields required to populate the PC's existing local token records, grouped by marketplace and account. Each item includes a stable account/shop identifier plus source timestamps and expiry metadata. The endpoint sends no logs that contain raw token values.

Error responses are sanitized and use `401` for invalid credentials, `403` for a disabled or unconfigured export, and `503` when the token source cannot be read.

### PC action

`POST /api/runtime/pull-stb-marketplace-tokens`

The authenticated dashboard invokes this action. It calls the STB endpoint server-to-server; raw tokens are never returned to the browser. The result reports only per-marketplace counts and a sanitized status, such as `updated`, `unchanged`, `skipped_stale`, or `failed`.

## Persistence and Conflict Rules

- Match Shopee records by configured account key and shop ID; match TikTok records by configured shop/account identifier.
- Accept an incoming record when it is newer than the local record or has a later valid expiry.
- Do not overwrite a newer usable PC credential with an older STB record.
- Preserve the local record on malformed payloads, account mismatches, missing required credential fields, and STB connectivity failures.
- Record synchronization metadata (last attempt, last success, source `stb`, and sanitized failure message) separately from raw credentials.

## Configuration

STB `.env`:

```dotenv
STB_TOKEN_SYNC_ENABLED=false
STB_TOKEN_SYNC_TOKEN=<long-random-secret>
```

PC `.env`:

```dotenv
STB_TOKEN_SYNC_URL=http://<static-stb-ip>:8088/api/runtime/marketplace-token-sync
STB_TOKEN_SYNC_TOKEN=<same-long-random-secret>
STB_TOKEN_SYNC_ENABLED=true
STB_TOKEN_SYNC_INTERVAL_MINUTES=5
```

The STB firewall must allow the endpoint only from the PC's static IP. Prefer HTTPS when a trusted LAN certificate or reverse proxy is available; otherwise the endpoint remains limited to the private LAN and bearer token secret.

## User Experience

The Marketplace Token panel gains:

- a `Tarik Token dari STB` button;
- source status (`STB`, last success time, and expiry summary);
- sanitized error text when STB is unavailable;
- no access-token or refresh-token values in the browser payload.

The scheduler runs the same server-side sync every five minutes. Manual and scheduled runs share a lock to prevent concurrent imports.

## Verification

- STB endpoint rejects missing, invalid, and wrong-purpose bearer secrets.
- STB endpoint does not include token values in logs or status responses.
- PC imports valid Shopee and TikTok records into the current token storage.
- PC refuses stale or mismatched records and retains the latest local usable copy.
- A failed STB request preserves local credentials and records a sanitized status.
- Dashboard manual action returns only summaries; it never returns credential values.
- Scheduler obeys the configured interval and avoids overlapping runs.

## Rollout

1. Deploy the same backend changes to STB and PC.
2. Set a new long random `STB_TOKEN_SYNC_TOKEN` on both hosts; do not reuse existing integration secrets.
3. Configure the PC with the STB static IP and enable the feature on both hosts.
4. Restrict STB firewall access to the PC static IP.
5. Trigger one manual pull and verify the PC dashboard shows a successful STB source status.
6. Enable the scheduled pull after the manual import is confirmed.

# TikTok Bulk Submit Observability Design

## Problem

On August 2, 2026, the bulk TikTok page sent a successful HTTP request for a
selected product, but the expected Shopee seller SKU remained absent from the
TikTok cache. The page clears its submit notification while it refreshes the
preview, and the backend does not persist per-SKU bulk request results. The
operator therefore cannot see whether Shopee refresh, TikTok image upload, or
TikTok product mutation stopped the run.

## Goal

Make every bulk TikTok variant attempt observable and keep its final result
visible after the candidate preview refreshes. TikTok is the source of truth:
the application must not mark a SKU as added from the submit response alone.
A user can retry an explicitly selected SKU after seeing the recorded result;
previewing never mutates TikTok.

## Backend Design

- Reuse `sku_variant_actions` for each bulk target SKU with
  `target_channel = tiktok` and `action_type = bulk_create_variant`.
- Locate the Stock Master row using the Shopee item ID and model ID so each
  action record has a durable, SKU-specific key.
- Record every terminal outcome: skipped during preflight, failed Shopee
  synchronization, failed TikTok detail retrieval, rejected image upload,
  rejected TikTok mutation, and completed mutation.
- Store the safe request context and returned payload in the action `payload`.
  Redact access tokens, signatures, app credentials, and authorization headers
  before writing the payload.
- Return a result row for every selected SKU. A product-level preflight failure
  becomes individual failed rows with the same specific reason, so the frontend
  has no invisible failures.
- After a TikTok mutation returns success, fetch that TikTok product again with
  `syncTiktokProductToDatabase()` and verify the normalized seller SKU exists
  in the freshly returned catalogue before marking the row `updated`.
- Do not insert a TikTok SKU, mapping, or local success state directly from the
  mutation response. Only `storeTiktokProductPayload()` fed by the fresh TikTok
  GET response may update the local TikTok cache.
- When the mutation response is successful but the forced TikTok GET fails or
  does not contain the seller SKU, record and return
  `submitted_unverified`. This state is not a success, does not remove the
  candidate, and retains the real response for follow-up.
- For an existing TikTok product, fail closed before the PUT request when its
  current TikTok detail cannot be read. The request must never be constructed
  without the existing SKU list, because it could otherwise omit and alter
  existing marketplace variants.
- Preserve the existing sequential product processing, live duplicate recheck,
  price choice, image refresh, and no-overwrite behavior for existing TikTok
  variants.

## Frontend Design

- Keep the current result list when refreshing the candidate preview after a
  submit.
- Do not clear the final submit message during that refresh.
- Replace the generic completion copy with a persistent summary containing the
  exact counts: `Berhasil`, `Belum terverifikasi`, `Gagal`, and `Dilewati`.
- Render per-SKU reasons in the existing result table. Successful, unverified,
  failed, and skipped items remain visible in that same table for the latest
  run.
- Do not automatically submit or retry a TikTok mutation after deployment.
  Retrying stays an explicit action through the confirmation modal.

## Verification

- Add controller regression tests proving a failed bulk SKU produces a failed
  result row and audit record with no secret query values, and a successful
  submit is `submitted_unverified` until a fresh TikTok catalogue response
  contains its seller SKU.
- Add a frontend regression check for preserving the submit result message and
  result rows through the preview refresh.
- Run targeted backend tests, the complete backend test suite, and the
  production frontend build.
- Publish the built Vite assets and verify the local bulk page and API preview.
- Automated verification does not send a live TikTok mutation. The current
  endpoint selects a product group, and product `1735621806681065406` contains
  both `INT-55307930257-SOFT-GREY` and
  `INT-55307930257-ROSE-GOLD`; a later marketplace retry therefore remains an
  explicit operator action through the confirmation modal.

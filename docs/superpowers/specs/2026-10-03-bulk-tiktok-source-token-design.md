# Bulk TikTok Source Token Resolution Design

## Goal

Make the bulk Shopee-to-TikTok variant flow resolve the Shopee token from the
source product's `shop_id`, and retain safe diagnostics when that resolution
cannot be made.

## Problem

The bulk submission records all variants as failed with "Token Shopee aktif
untuk toko produk ini tidak ditemukan" before it contacts TikTok. The same
product can be synchronized successfully through the Shopee HTTP endpoint,
which shows the source product and its authorized shop remain available.

## Design

`syncShopeeProductToDatabase()` will use a dedicated source-token resolver.
It accepts the source product's exact `shop_id`, finds an active non-expired
Shopee token for that shop across registered Shopee accounts, and refreshes
only that token's owning account when it is within the normal refresh window.
It never falls back to a token belonging to another shop.

If no usable exact-shop token is found, the returned sync result will include
a redacted `source_token_context`: required shop ID, candidate count, and
candidate account keys/statuses. The existing per-SKU action audit persists
this context through the already-redacted audit payload. Access tokens,
refresh tokens, signatures, ciphers, and raw API responses remain excluded.

## Error Handling

- The bulk operation remains fail-closed when no exact-shop token can be
  resolved.
- A matching but refresh-failed token reports the refresh error without
  exposing credentials.
- A valid matching token continues into the existing Shopee image refresh and
  TikTok mutation workflow unchanged.

## Testing

- A focused controller test seeds tokens for two shops and proves the resolver
  chooses the token whose shop ID matches the source product, independent of
  the controller's requested marketplace account context.
- A focused test proves missing source tokens return only the safe diagnostic
  fields and do not contain credential values.
- Existing bulk audit redaction coverage remains part of the focused suite.

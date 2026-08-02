# TikTok Bulk Description Payload Design

## Problem

Bulk TikTok variant mutation now submits valid main images and structured SKU
prices, but TikTok rejects it because `description` is required and missing
from the PUT body. The fresh TikTok product detail already contains the
product's existing description.

## Goal

Preserve and submit the existing TikTok description whenever adding a missing
variant to an existing TikTok product.

## Design

- Add a pure helper that reads and trims `description` from fresh TikTok
  product detail.
- In `submitTiktokVariantMutation()`, fail closed before sending a PUT when
  the existing product detail has no usable description.
- Include the preserved description in the mutation body alongside title,
  main images, and SKU rows.
- Do not use Shopee description, product title, or generated text as a
  fallback for an existing TikTok product.

## Safety

- The mutation retains the exact TikTok-managed description instead of
  overwriting it with a marketplace copy from another channel.
- An incomplete TikTok detail causes no live PUT request.
- No automatic retry is sent during implementation or verification.

## Verification

- Add a failing unit test proving a detail description is returned unchanged
  for the mutation builder and blank detail produces an empty value.
- Run the focused test and full backend PHPUnit suite.
- Inspect the generated body field list through unit-tested helper behavior;
  do not send a live TikTok request.

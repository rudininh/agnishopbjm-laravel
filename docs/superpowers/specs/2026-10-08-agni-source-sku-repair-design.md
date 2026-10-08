# Agni is the source of destination seller SKUs

The operator requires Shopee Agni seller SKUs to be the single reference for
Shopee Gita and TikTok. Destination product and variant IDs address the update;
they must never generate replacement seller SKUs. Existing source-side Agni
template generation remains available.

Destination previews copy the actual saved Agni variant SKU, preserving custom
SKUs as well as INT SKUs. Prove a unique source product through account-scoped
listing links, TikTok mappings, known creation identities or existing exact
source SKUs/source-owned prefixes. Titles alone cannot prove identity. Within
that product use an explicit variant link or a unique matching variant name;
ambiguous, missing or duplicate source SKUs/variants block. Existing exact source
SKUs remain valid even when destination display names differ.

Before a destination update, re-read source variants from the Agni account and
validate the proposed SKU against that fresh source. Repair previews retain
expected destination SKU/name checks. Gita repair also reads fresh destination
variants. No fallback may synthesize a Gita/TikTok ID-based SKU. Empty-SKU bulk
generation stays Agni-only; Gita uses the source-aware correction preview.

Both stock screens display source recommendations and correction blockers.
Unknown source metadata cannot advertise a generated destination template.
Only an operator confirmation submits a marketplace update. Automated tests
and live diagnostics perform no product/SKU/stock writes. Existing stock,
price, variant preservation, catalog lease and TikTok readback remain intact.

Verification covers copied INT/custom SKUs, unchanged correct SKUs, no source
identity fallback, mixed/conflicting links, duplicate variant/SKU evidence,
source changes between preview and submit, destination ownership, frontend
preview behavior and the legacy Agni template path.

# TikTok duplicate seller-SKU repair

The repair reader validates immutable IDs, attributes, stock, price, warehouse and package fields before planning. A seller SKU is editable during an explicit Agni repair; duplicate seller SKUs in that input must not block comparison of otherwise equivalent approved live/review versions. Ordinary addition and all outgoing repair payloads continue to require unique seller SKUs, IDs and attribute combinations.

Use a separate repair snapshot reader with only seller-SKU uniqueness relaxed. In planning, a duplicated live seller SKU cannot identify a source variant; only a unique source attribute combination or consistent explicit source-model mapping can identify it. Ambiguous correspondence and unsafe orphan deletion remain blocked. Keep final payload validation strict and retain durable once-only submission/readback guards.

The same video asset may have different signed delivery links between reads or live/review responses. When a nonempty string video ID is available and any supplied delivery links are valid HTTP(S) URLs, exclude only video.url and video.cover_url from contract comparison and revision calculation. Keep the video ID and all other metadata; missing/different IDs, malformed links and changed metadata remain subject to strict comparison. Repairs never send video changes. Ordinary addition with repeated seller SKUs exposes the existing explicit Agni repair action rather than a generic dead end.

Verification: reproduce duplicate review SKU and duplicate live SKU correction with real service/planner fixtures, assert unsafe duplicate IDs/attributes or quantity drift remains blocked, run affected suites and review, then read-only preflight the actual reported product before any explicit repair run.

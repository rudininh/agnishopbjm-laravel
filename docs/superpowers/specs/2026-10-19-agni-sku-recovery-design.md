# Agni SKU prerequisite recovery

Repair the existing authorized normalization workflow rather than requiring users to switch to a catalog batch. Saved attempted SKU writes remain readback-only. Explicit recovery checks fresh source model IDs and every attempted SKU, unchanged source/target accounts and mapping, and approved target state. If names changed and every attempted write is observed, retire only the obsolete plan as reconciled while retaining audit attempts, then allow a new keyed normalization plan. Never retire missing or uncertain writes.

Allow acyclic SKU ownership chains by updating the current holder first. Reject cycles and ambiguous generated names before mutation. Identity comparisons use model ID ordering independent of dependency ordering.

Expose source status, step, and explicit recovery through a fixed-source controller. Add a product-modal normalization panel with progress and an explicit recovery/continue action, preserving pending repair request keys. Loading saved status does no remote work. After normalization succeeds, refresh the existing repair/addition state; do not automatically submit TikTok edits.

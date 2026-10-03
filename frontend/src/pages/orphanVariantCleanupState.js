export const supportsCleanup = key => ['tiktok-agnishopbjm', 'shopee-gitacollectionbjm'].includes(key)
export const eligibleItems = run => run?.status === 'ready' ? (run.items || []).filter(i => i.status === 'eligible') : []
export const selectionFor = (run, itemId = null) => eligibleItems(run).filter(i => itemId === null || i.item_id === itemId).map(i => i.item_id)
export function canSubmit(run, ids, busy) {
  const eligible = selectionFor(run)
  return !busy && Boolean(run?.revision) && ids.length > 0 && new Set(ids).size === ids.length && ids.every(id => eligible.includes(id))
}
export async function runCleanupSteps(api, initial, onUpdate, isCurrent) {
  let run = initial
  while (isCurrent() && ['scanning', 'running'].includes(run.status)) {
    const response = run.status === 'scanning'
      ? await api.orphanVariantScan(run.run_id, run.account_key)
      : await api.orphanVariantStep(run.run_id, run.account_key)
    if (!isCurrent()) return run
    run = response.data
    onUpdate(run)
  }
  return run
}

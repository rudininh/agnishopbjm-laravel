const accounts = ['shopee-agnishopbjm', 'tiktok-agnishopbjm', 'shopee-gitacollectionbjm']
export function stockMirrorScope(type, accountKey, productId, variantId) {
  if (!accounts.includes(accountKey) || !['all', 'product', 'variant'].includes(type)) throw Error('Lingkup stok tidak valid.')
  const scope = { type, view_account_key: accountKey }
  if (type !== 'all') {
    if (!/^\d+$/.test(String(productId ?? ''))) throw Error('ID produk tidak valid.')
    scope.product_id = String(productId)
  }
  if (type === 'variant') {
    if (!/^\d+$/.test(String(variantId ?? ''))) throw Error('ID varian tidak valid.')
    scope.variant_id = String(variantId)
  }
  return scope
}
export function stockMirrorTargets(keys) {
  if (!Array.isArray(keys) || !keys.length || keys.some(key => !accounts.slice(1).includes(key))) throw Error('Pilih tujuan TikTok atau Gitashop.')
  return [...new Set(keys)]
}
export function stockMirrorSummary(run) {
  return Object.fromEntries(['checked', 'success', 'unchanged', 'skipped', 'failed', 'unverified', 'pending'].map(key => [key, Number.isInteger(run?.summary?.[key]) ? run.summary[key] : 0]))
}
export const stockMirrorCanContinue = run => Boolean(run?.can_continue && ['scanning', 'running'].includes(run.status))
export const stockMirrorQuantity = value => value == null ? 'Tidak tersedia' : value
export async function continueStockMirror(api, initial, update, alive) {
  let run = initial
  while (alive() && stockMirrorCanContinue(run)) {
    run = (await api.stepStockMirror(run.run_id)).data
    update(run)
  }
  return run
}
export async function recoverStockMirror(api, runId) {
  return (await api.stockMirrorRun(runId)).data
}

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
export function stockMirrorResultPage(items, requestedPage = 1, requestedSize = 25) {
  const results = Array.isArray(items) ? items : []
  const size = [25, 50, 100].includes(Number(requestedSize)) ? Number(requestedSize) : 25
  const pages = Math.max(1, Math.ceil(results.length / size))
  const input = Number(requestedPage)
  const page = Math.min(pages, Math.max(1, Number.isInteger(input) ? input : 1))
  const offset = (page - 1) * size
  return {
    rows: results.slice(offset, offset + size), total: results.length, page, pages, page_size: size,
    from: results.length ? offset + 1 : 0, to: Math.min(offset + size, results.length)
  }
}
export async function continueStockMirror(api, initial, update, alive) {
  let run = initial
  while (alive() && stockMirrorCanContinue(run)) {
    run = (await api.stepStockMirror(run.run_id)).data
    update(run)
  }
  return run
}
export async function recoverStockMirror(api, runId) {
  let known = null
  if (runId) {
    try {
      known = (await api.stockMirrorRun(runId)).data
      if (stockMirrorCanContinue(known)) return known
    } catch (error) {
      if (![404, 422].includes(error.response?.status)) throw error
    }
  }
  return (await api.stockMirrorActiveRun()).data.run || known
}

export function stockMirrorRequestKey(provider = globalThis.crypto) {
  try {
    const bytes = new Uint8Array(16)
    provider.getRandomValues(bytes)
    bytes[6] = (bytes[6] & 0x0f) | 0x40
    bytes[8] = (bytes[8] & 0x3f) | 0x80
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
  } catch {
    throw Error('Kunci permintaan tidak dapat dibuat. Periksa dukungan browser lalu coba lagi.')
  }
}

// Pending create requests stay in memory only, so an explicit retry uses the same identity.
export function createStockMirrorStarter(api, provider = globalThis.crypto) {
  return {
    pendingPayload: null,
    prepare(scope, targets) {
      if (this.pendingPayload) throw Error('Periksa atau ulangi permintaan sebelumnya terlebih dahulu.')
      this.pendingPayload = {
        scope: stockMirrorScope(scope.type, scope.view_account_key, scope.product_id, scope.variant_id),
        target_accounts: stockMirrorTargets(targets),
        request_key: stockMirrorRequestKey(provider)
      }
    },
    async submit() {
      if (!this.pendingPayload) throw Error('Tidak ada permintaan yang dapat diulang.')
      try {
        const response = await api.startStockMirror(this.pendingPayload)
        this.pendingPayload = null
        return { ...response, allow_follow: true }
      } catch (error) {
        if (error.response?.status === 409) {
          const existing = await recoverStockMirror(api)
          if (existing) {
            this.pendingPayload = null
            return { data: existing, allow_follow: false, notice: error.response?.data?.message || 'Proses aktif ditemukan. Lanjutkan atau batalkan proses tersebut.' }
          }
        }
        if (error.response?.status === 422) this.pendingPayload = null
        throw error
      }
    }
  }
}

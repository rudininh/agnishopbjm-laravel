import { stockMirrorRequestKey } from './marketplaceStockMirrorState.js'
import { batchActivityText } from './stockHubTiktokVariantBatchProgress.js'

const workflow = 'agni_sku_repair_then_tiktok'
const storageKey = 'stock-hub-tiktok-variant-batch:identifiers'
const statuses = ['running', 'paused', 'pausing', 'paused_error', 'completed']
const summaryFields = ['products_total', 'products_processed', 'variants_verified', 'variants_pending', 'variants_failed', 'variants_skipped', 'products_need_attention',
  'agni_skus_verified', 'tiktok_variants_repaired', 'tiktok_variants_removed', 'products_waiting_review']

function snapshot(response, nullable = false, expectedId = '') {
  const run = response?.data?.data
  if (nullable && run === null) return null
  if (!run || typeof run.run_id !== 'string' || !run.run_id || (expectedId && run.run_id !== expectedId)
    || !['legacy', workflow].includes(run.workflow) || typeof run.can_resume_attention !== 'boolean'
    || !statuses.includes(run.status) || run.can_continue !== (run.status === 'running')
    || typeof run.message !== 'string' || !Number.isFinite(run.next_step_after_ms) || run.next_step_after_ms < 0 || run.next_step_after_ms > 5000
    || !Array.isArray(run.items) || !summaryFields.every(key => Number.isInteger(run.summary?.[key]) && run.summary[key] >= 0)
    || !run.items.every(item => /^\d+$/.test(String(item?.source_product_id)) && typeof item.name === 'string' && typeof item.status === 'string')) {
    throw Error('Status tersimpan belum dapat diperiksa.')
  }
  return run
}

export function createTiktokVariantBatchController(api, options = {}) {
  let identifiers = {}
  try {
    const saved = JSON.parse(options.storage?.getItem(storageKey) || '{}')
    if (typeof saved.run_id === 'string' && saved.run_id) identifiers.run_id = saved.run_id
    if (typeof saved.request_key === 'string' && saved.request_key) identifiers.request_key = saved.request_key
  } catch {}
  const state = { busy: false, run: null, pendingKey: identifiers.request_key || '', error: '', paused: true,
    requestKind: '', requestStartedAt: null, lastResponseAt: null, activities: [] }
  const now = options.now || Date.now
  let lastActivity = ''
  const sleep = options.sleep || (ms => new Promise(resolve => setTimeout(resolve, ms)))
  let alive = true, execution = null, pausing = null, pauseRequested = false
  const notify = () => { if (alive) options.onChange?.({ ...state }) }
  const request = async (kind, action) => {
    state.requestKind = kind; state.requestStartedAt = now(); notify()
    try { return await action() }
    finally { state.requestKind = ''; state.requestStartedAt = null; notify() }
  }
  const persist = () => { try { options.storage?.setItem(storageKey, JSON.stringify(identifiers)) } catch {} }
  const busy = value => { if (state.busy === value) return; state.busy = value; if (alive) { options.onBusy?.(value); notify() } }
  const update = (run, confirmed = false, mutated = false) => {
    if (!alive) return
    state.run = run
    state.lastResponseAt = now()
    if (run) {
      const message = batchActivityText(run)
      const signature = JSON.stringify([run.run_id, run.status, run.summary.products_processed,
        run.current_source_product_id, run.items.find(item => item.source_product_id === run.current_source_product_id)?.status, message])
      if (signature !== lastActivity) {
        state.activities = [{ at: state.lastResponseAt, message }, ...state.activities].slice(0, 30)
        lastActivity = signature
      }
      identifiers.run_id = run.run_id
      if (confirmed || run.status !== 'completed') { delete identifiers.request_key; state.pendingKey = '' }
      persist(); options.onRun?.(run, { mutated })
    }
    notify()
  }
  const lookup = async () => {
    const active = snapshot(await request('status', () => api.activeTiktokVariantBatch()), true)
    if (!alive) return null
    if (active) { update(active); return active }
    const saved = identifiers.run_id ? snapshot(await request('status', () => api.tiktokVariantBatchRun(identifiers.run_id)), false, identifiers.run_id) : null
    update(saved); return saved
  }
  const recoverFailure = async () => {
    state.paused = true; state.error = 'Respons belum dapat dipastikan. Muat status tersimpan sebelum melanjutkan.'; notify()
    try { await lookup() } catch { state.error = 'Status tersimpan belum dapat dimuat. Periksa koneksi lalu muat status kembali.' }
    notify()
  }
  const mutation = async (action, expectedId = '', confirmed = false, kind = 'step') => {
    try { const run = snapshot(await request(kind, action), false, expectedId); update(run, confirmed, true); return true }
    catch { if (alive) await recoverFailure(); return false }
  }
  const follow = async () => {
    let steps = 0
    while (alive && !state.paused && state.run?.can_continue && steps < (options.maxSteps ?? 10000)) {
      if (state.run.next_step_after_ms) await sleep(state.run.next_step_after_ms)
      if (!alive || state.paused) break
      steps++
      const id = state.run.run_id
      if (!await mutation(() => api.stepTiktokVariantBatch(id), id)) break
    }
    if (state.run?.status !== 'completed') state.paused = true
    notify()
  }
  const execute = action => {
    if (!alive || state.busy) return Promise.resolve()
    busy(true); state.error = ''
    execution = (async () => {
      try { await action() } catch { if (alive) { state.paused = true; state.error = 'Status belum dapat dimuat. Periksa koneksi lalu muat status kembali.'; notify() } }
      finally { if (alive && !pauseRequested) busy(false) }
    })()
    return execution
  }
  const recover = () => execute(async () => { state.paused = true; await lookup(); notify() })
  const start = ({ retryPending = false, freshCatalog = false } = {}) => execute(async () => {
    state.paused = true
    if (freshCatalog && state.pendingKey) return
    const saved = await lookup()
    const attention = saved?.can_resume_attention || (saved?.workflow === 'legacy' && saved.summary.products_need_attention > 0)
    if (!alive || pauseRequested || (freshCatalog && state.pendingKey)
      || (saved && (saved.status !== 'completed' || (attention && !freshCatalog && !(retryPending && state.pendingKey))))) return
    if (!state.pendingKey) state.pendingKey = stockMirrorRequestKey(options.crypto ?? globalThis.crypto)
    identifiers = { request_key: state.pendingKey, ...(saved ? { run_id: saved.run_id } : {}) }
    persist(); notify()
    if (await mutation(() => api.startTiktokVariantBatch({ request_key: state.pendingKey, workflow }), '', true, 'start')
      && alive && !pauseRequested && state.run.workflow === workflow && state.run.matches_request_key === true) {
      state.paused = false; notify(); await follow()
    }
  })
  return {
    state, recover, restore: recover, start,
    startFresh: () => start({ freshCatalog: true }),
    resume() { return execute(async () => {
      state.paused = true
      const saved = await lookup()
      if (!alive || pauseRequested || !saved || (saved.status === 'completed' && !saved.can_resume_attention && saved.workflow !== 'legacy')) return
      const id = saved.run_id
      if (await mutation(() => api.resumeTiktokVariantBatch(id, { workflow }), id, false, 'resume') && alive && !pauseRequested) { state.paused = false; notify(); await follow() }
    }) },
    pause() {
      if (!alive) return Promise.resolve()
      if (pausing) return pausing
      pauseRequested = true; state.paused = true; notify(); busy(true)
      const outstanding = execution
      pausing = (async () => {
        try {
          await outstanding
          if (alive && state.run && state.run.status !== 'completed') {
            const id = state.run.run_id
            await mutation(() => api.pauseTiktokVariantBatch(id), id, false, 'pause')
          }
        } finally { pauseRequested = false; pausing = null; if (alive) busy(false) }
      })()
      return pausing
    },
    stop() { if (!alive) return; state.paused = true; busy(false); alive = false }
  }
}

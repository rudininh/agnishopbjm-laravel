import { stockMirrorRequestKey } from './marketplaceStockMirrorState.js'

export function createProductCreationController(api, config, options = {}) {
  const running = new Set(config.runningStatuses)
  const checkable = new Set(config.checkableStatuses || ['submitted_unverified'])
  const continues = run => Boolean(run?.run_id && run.can_continue && running.has(run.status))
  const retryable = run => Boolean(run?.can_retry && ['blocked', 'rejected'].includes(run.status))
  const storageKey = config.storageKey
  const sleep = options.sleep || (ms => new Promise(resolve => setTimeout(resolve, ms)))
  const state = { sourceId: '', run: null, pendingKey: '', busy: false, paused: false, error: '' }
  let alive = true, pendingPayload = null, pendingPreviousRunId = '', identifiers = {}
  try { identifiers = JSON.parse(options.storage?.getItem(storageKey) || '{}') } catch {}
  const notify = () => { if (alive) options.onChange?.({ ...state }) }
  const persist = () => { try { options.storage?.setItem(storageKey, JSON.stringify(identifiers)) } catch {} }
  const busy = value => { state.busy = value; if (alive) { options.onBusy?.(value); notify() } }
  const update = (run, confirmedStart = false) => {
    if (!alive) return
    state.run = run
    if (run) {
      if (confirmedStart || !state.pendingKey || run.run_id !== pendingPreviousRunId) {
        state.pendingKey = ''; pendingPayload = null; pendingPreviousRunId = ''
        identifiers[state.sourceId] = { run_id: run.run_id }; persist()
      }
      options.onRun?.(run)
    }
    notify()
  }
  const select = sourceId => {
    if (!/^\d+$/.test(String(sourceId))) throw Error('Produk sumber tidak valid.')
    if (state.sourceId !== String(sourceId)) {
      state.sourceId = String(sourceId); state.run = null; state.pendingKey = identifiers[state.sourceId]?.request_key || ''
      pendingPayload = null; pendingPreviousRunId = state.pendingKey ? identifiers[state.sourceId]?.run_id || '' : ''
    }
  }
  const lookup = async () => {
    const response = await api.source(state.sourceId)
    const run = response?.data?.data
    // A malformed GET is not proof that creation is safe.
    if (run === undefined || (run !== null && (!run.run_id || String(run.source_product_id) !== state.sourceId))) {
      throw Error('Status tersimpan belum dapat diperiksa.')
    }
    update(run)
    return run
  }
  const recoverFailure = async () => {
    state.paused = true
    state.error = 'Respons belum dapat dipastikan. Periksa status tersimpan sebelum melanjutkan.'
    notify()
    try { await lookup() } catch { state.error = 'Status tersimpan belum dapat dimuat. Periksa kembali saat koneksi tersedia.' }
    notify()
  }
  const mutation = async (action, confirmedStart = false) => {
    try {
      const response = await action()
      const run = response?.data?.data
      if (!run?.run_id || String(run.source_product_id) !== state.sourceId) throw Error('Respons belum dapat dipastikan.')
      update(run, confirmedStart)
      return true
    } catch {
      if (alive) await recoverFailure()
      return false
    }
  }
  const follow = async () => {
    let steps = 0
    while (alive && continues(state.run) && !state.paused && steps < (options.maxSteps ?? 10000)) {
      const delay = Number(state.run.next_step_after_ms)
      if (Number.isFinite(delay) && delay > 0) await sleep(Math.min(5000, delay))
      if (!alive || !state.busy || state.paused) break
      steps++
      if (!await mutation(() => api.step(state.run.run_id))) break
    }
    if (alive && continues(state.run)) state.paused = true
    notify()
  }
  const startRequest = async context => {
    if (!state.pendingKey) {
      state.pendingKey = stockMirrorRequestKey(options.crypto ?? globalThis.crypto)
      pendingPreviousRunId = state.run?.run_id || ''
    }
    pendingPayload ||= { source_product_id: state.sourceId, request_key: state.pendingKey, ...(context ? { context } : {}) }
    identifiers[state.sourceId] = { request_key: state.pendingKey, ...(pendingPreviousRunId ? { run_id: pendingPreviousRunId } : {}) }; persist(); notify()
    if (await mutation(() => api.start(pendingPayload), true) && alive && !state.paused) await follow()
  }
  const execute = async action => {
    if (!alive || state.busy) return
    busy(true); state.error = ''; state.paused = false
    try { await action() } catch { if (alive) { state.paused = true; state.error = 'Status belum dapat dimuat. Periksa koneksi lalu muat status kembali.'; notify() } }
    finally { if (alive) busy(false) }
  }
  return {
    state,
    start(sourceId) { return execute(async () => {
      select(sourceId)
      const saved = await lookup()
      if (!alive) return
      if (saved) await follow()
      else if (state.pendingKey) { state.paused = true; notify() }
      else await startRequest()
    }) },
    recover(sourceId = state.sourceId) { return execute(async () => { select(sourceId); await lookup(); state.paused = continues(state.run); notify() }) },
    resume() { return execute(async () => { await lookup(); if (alive) await follow() }) },
    retry(context) { return execute(async () => { await lookup(); if (alive && !state.pendingKey && retryable(state.run)) await startRequest(context) }) },
    retryPending() { return execute(async () => {
      const saved = await lookup()
      if (!alive) return
      if (state.pendingKey && (!saved || saved.run_id === pendingPreviousRunId)) {
        // Filled context is deliberately not persisted. After reload, only GET recovery is safe for a lost contextual retry.
        if (pendingPreviousRunId && !pendingPayload) { state.error = 'Permintaan sebelumnya belum ditemukan. Muat status kembali; konteks tidak disimpan setelah halaman ditutup.'; state.paused = true; notify() }
        else await startRequest()
      } else { state.paused = continues(saved); notify() }
    }) },
    checkStatus() { return execute(async () => {
      await lookup()
      if (alive && checkable.has(state.run?.status) && state.run.remote_product_id) {
        await mutation(() => api.step(state.run.run_id))
      }
    }) },
    async restore() {
      // Remember every source before the first awaited GET; identifiers are not evidence of acceptance.
      for (const [sourceId, identity] of Object.entries(identifiers)) {
        if (/^\d+$/.test(sourceId) && (identity?.run_id || identity?.request_key)) options.onRemember?.(sourceId)
      }
      for (const sourceId of Object.keys(identifiers)) { if (!alive) break; await this.recover(sourceId) }
    },
    stop() { if (!alive) return; options.onBusy?.(false); alive = false; state.busy = false }
  }
}

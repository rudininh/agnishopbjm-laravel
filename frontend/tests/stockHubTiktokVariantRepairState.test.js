import test from 'node:test'
import assert from 'node:assert/strict'
import { createTiktokVariantRepairController } from '../src/pages/stockHubTiktokVariantRepairState.js'

const response = data => ({ data: { data } })
const run = (status, extra = {}) => ({ run_id: 'repair-1', source_product_id: '10', remote_product_id: '900', status,
  can_continue: ['preparing', 'scanning', 'uploading', 'submitting', 'verifying'].includes(status),
  can_retry: ['blocked', 'rejected'].includes(status), progress: { scanned_products: 2, uploaded_images: 0, total_images: 1 },
  result: { changed: [], deleted: [], missing_count: 2 }, ...extra })
const crypto = { getRandomValues(bytes) { bytes.fill(8); return bytes } }
const controller = (api, options = {}) => createTiktokVariantRepairController(api, { crypto, ...options })

test('source SKU prerequisite blocks repair writes, preserves lost identity, and clears on read-only recovery', async () => {
  let prerequisite = { code: 'agni_sku_pending' }, starts = 0
  const values = new Map([['stock-hub-tiktok-variant-repairs:identifiers', JSON.stringify({ 10: { request_key: 'saved-key' } })]])
  const c = controller({
    tiktokVariantRepairSource: async () => ({ data: { data: null, ...(prerequisite ? { prerequisite } : {}) } }),
    startTiktokVariantRepair: async payload => { starts++; assert.equal(payload.request_key, 'saved-key'); return response(run('success')) }
  }, { storage: { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) } })
  await c.recover('10')
  assert.equal(c.state.prerequisite, 'agni_sku_pending')
  assert.match(c.state.error, /Normalisasi SKU Agni.*belum terverifikasi/)
  assert.match(c.state.error, /Periksa dan Lanjutkan/)
  assert.equal(c.state.paused, true)
  await c.start('10'); await c.retryPending(); await c.resume()
  assert.equal(starts, 0)
  assert.equal(c.state.pendingKey, 'saved-key')
  prerequisite = null
  await c.recover('10')
  assert.equal(c.state.error, '')
  assert.equal(c.state.prerequisite, '')
  assert.equal(starts, 0)
  await c.retryPending()
  assert.equal(starts, 1)
})

test('repair start rejection discovers source prerequisite instead of reporting a lost response', async () => {
  let starts = 0
  const c = controller({
    tiktokVariantRepairSource: async () => starts ? { data: { data: null, prerequisite: { code: 'agni_sku_pending' } } } : response(null),
    startTiktokVariantRepair: async () => { starts++; throw Error('409') }
  })
  await c.start('10')
  assert.match(c.state.error, /Normalisasi SKU Agni/)
  assert.ok(c.state.pendingKey)
  await c.retryPending()
  assert.equal(starts, 1)
})

for (const prerequisite of [{ code: 'unknown' }, {}, 'agni_sku_pending', null]) {
  test(`malformed or unknown repair prerequisite cannot authorize writes: ${JSON.stringify(prerequisite)}`, async () => {
    let writes = 0
    const c = controller({ tiktokVariantRepairSource: async () => ({ data: { data: null, prerequisite } }),
      startTiktokVariantRepair: async () => { writes++ } })
    await c.start('10'); await c.retryPending(); await c.resume()
    assert.equal(writes, 0)
    assert.ok(c.state.error)
  })
}

test('repair starts with only source/request identity and follows explicit server steps', async () => {
  const calls = []
  const c = controller({
    tiktokVariantRepairSource: async id => { calls.push(['GET', id]); return response(null) },
    startTiktokVariantRepair: async payload => { calls.push(['start', payload]); return response(run('scanning')) },
    stepTiktokVariantRepair: async id => { calls.push(['step', id]); return response(run('success')) }
  })
  await c.start('10')
  assert.deepEqual(calls.map(call => call[0]), ['GET', 'start', 'step'])
  assert.deepEqual(Object.keys(calls[1][1]).sort(), ['request_key', 'source_product_id'])
  assert.equal(c.state.run.status, 'success')
})

test('pending repair restore is GET-only and check status never creates another repair', async () => {
  const calls = [], remembered = []
  const values = new Map([['stock-hub-tiktok-variant-repairs:identifiers', JSON.stringify({ 10: { run_id: 'repair-1' } })]])
  const c = controller({
    tiktokVariantRepairSource: async () => { calls.push('GET'); return response(run('under_review')) },
    startTiktokVariantRepair: async () => { calls.push('start') },
    stepTiktokVariantRepair: async () => { calls.push('check'); return response(run('success')) }
  }, { storage: { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) }, onRemember: id => remembered.push(id) })
  await c.restore(); await c.start('10')
  assert.deepEqual(calls, ['GET', 'GET'])
  assert.deepEqual(remembered, ['10'])
  await c.checkStatus()
  assert.deepEqual(calls, ['GET', 'GET', 'GET', 'check'])
  assert.equal(c.state.run.status, 'success')
})

test('lost start keeps the request identity and only explicit retry can resend it', async () => {
  const payloads = [], values = new Map()
  const c = controller({ tiktokVariantRepairSource: async () => response(null),
    startTiktokVariantRepair: async payload => { payloads.push(payload); throw Error('timeout') }
  }, { storage: { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) } })
  await c.start('10')
  assert.ok(c.state.pendingKey)
  await c.recover('10')
  assert.equal(payloads.length, 1)
  await c.retryPending()
  assert.equal(payloads.length, 2)
  assert.equal(payloads[0].request_key, payloads[1].request_key)
  assert.ok(values.has('stock-hub-tiktok-variant-repairs:identifiers'))
})

for (const status of ['blocked', 'rejected']) test(`safe ${status} repair retries explicitly with a new request`, async () => {
  let starts = 0
  const c = controller({ tiktokVariantRepairSource: async () => response(run(status)),
    startTiktokVariantRepair: async () => { starts++; return response(run('success', { run_id: 'repair-2' })) } })
  await c.recover('10')
  assert.equal(starts, 0)
  await c.retry()
  assert.equal(starts, 1)
  assert.equal(c.state.run.run_id, 'repair-2')
})

test('running repair recovery requires explicit resume and failed GET never starts writes', async () => {
  let steps = 0, starts = 0
  const c = controller({ tiktokVariantRepairSource: async () => response(run('uploading')),
    stepTiktokVariantRepair: async () => { steps++; return response(run('success')) } })
  await c.recover('10')
  assert.equal(steps, 0)
  assert.equal(c.state.paused, true)
  await c.resume()
  assert.equal(steps, 1)
  const failed = controller({ tiktokVariantRepairSource: async () => { throw Error('offline') },
    startTiktokVariantRepair: async () => { starts++ } })
  await failed.start('10')
  assert.equal(starts, 0)
  assert.ok(failed.state.error)
})

test('an explicit later repair gets a new identity after a verified repair without editing on recovery', async () => {
  let saved = null, sequence = 0
  const payloads = []
  const c = controller({ tiktokVariantRepairSource: async () => response(saved),
    startTiktokVariantRepair: async payload => { payloads.push(payload); saved = run('success', { run_id: `repair-${payloads.length}`, can_retry: true }); return response(saved) }
  }, { crypto: { getRandomValues(bytes) { bytes.fill(++sequence); return bytes } } })
  await c.start('10'); await c.recover('10')
  assert.equal(payloads.length, 1)
  await c.start('10')
  assert.equal(payloads.length, 2)
  assert.notEqual(payloads[0].request_key, payloads[1].request_key)
})

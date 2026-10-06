import test from 'node:test'
import assert from 'node:assert/strict'

const { canCreateTiktokProduct, creationRowResult, creationContext, creationFieldValues,
  creationLeafCategories, createTiktokProductController } = await import('../src/pages/stockHubTiktokProductCreationState.js')
const source = '123456789012345678'
const item = { item_id: source, destination_presence: { 'tiktok-agnishopbjm': 'missing' } }
const run = (status = 'preparing', extra = {}) => ({ run_id: 'run-1', source_product_id: source,
  status, stage: status, message: 'Saved progress', can_continue: ['preparing', 'scanning', 'uploading', 'submitting', 'verifying'].includes(status),
  can_retry: ['blocked', 'rejected'].includes(status), remote_product_id: null, variant_count: 2,
  progress: { scanned_products: 0, uploaded_images: 0, total_images: 2 }, required_fields: [], context: {}, result: null, ...extra })
const response = value => ({ data: { data: value } })
const crypto = { getRandomValues(bytes) { bytes.fill(7); return bytes } }
const controller = (api, options = {}) => createTiktokProductController(api, { crypto, ...options })

test('only missing TikTok rows on the unified primary account can create', () => {
  assert.equal(typeof canCreateTiktokProduct, 'function')
  const base = { unified: true, accountKey: 'shopee-agnishopbjm', item, busy: false }
  assert.equal(canCreateTiktokProduct(base), true)
  for (const override of [{ unified: false }, { accountKey: 'shopee-gitacollectionbjm' }, { busy: true },
    { item: { ...item, destination_presence: { 'tiktok-agnishopbjm': 'unknown' } } },
    { item: { ...item, destination_presence: { 'tiktok-agnishopbjm': 'present' } } }, { item: {} }]) {
    assert.equal(canCreateTiktokProduct({ ...base, ...override }), false)
  }
  assert.equal(canCreateTiktokProduct({ ...base, run: run('success') }), false)
  assert.equal(canCreateTiktokProduct({ ...base, run: run('submitted_unverified') }), false)
})

test('one click GETs source before POST and automatically advances bounded steps with Axios envelopes', async () => {
  const calls = [], busy = [], saved = []
  const c = controller({ tiktokProductCreationSource: async id => { calls.push(['GET source', id]); return response(null) },
    startTiktokProductCreation: async payload => { calls.push(['POST start', payload]); return response(run()) },
    stepTiktokProductCreation: async id => { calls.push(['POST step', id]); return response(run(calls.length === 3 ? 'scanning' : 'under_review', { result: { product_id: 'target', skus: [{ id: 'sku-1', seller_sku: 'SKU-A', source_variant_id: 'v1' }], published: false } })) }
  }, { onBusy: value => busy.push(value), onRun: value => saved.push(value) })
  await c.start(source)
  assert.deepEqual(calls.map(call => call[0]), ['GET source', 'POST start', 'POST step', 'POST step'])
  assert.equal(calls[1][1].source_product_id, source)
  assert.match(calls[1][1].request_key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
  assert.equal(c.state.run.status, 'under_review')
  assert.equal(saved.at(-1).result.skus[0].id, 'sku-1')
  assert.deepEqual(busy, [true, false])
})

test('double clicks serialize even before the first GET settles', async () => {
  let finish; const calls = []
  const c = controller({ tiktokProductCreationSource: async () => { calls.push('GET'); return new Promise(resolve => { finish = resolve }) },
    startTiktokProductCreation: async () => { calls.push('POST'); return response(run('success')) } })
  const first = c.start(source)
  await c.start(source)
  await c.start('999')
  finish(response(null)); await first
  assert.deepEqual(calls, ['GET', 'POST'])
})

test('a failed read-only lookup never authorizes a create', async () => {
  let posts = 0
  const c = controller({ tiktokProductCreationSource: async () => { throw Error('offline') }, startTiktokProductCreation: async () => { posts++ } })
  await c.start(source)
  assert.equal(posts, 0)
  assert.ok(c.state.error)
  assert.equal(c.state.busy, false)
})

test('accepted recovery prevents creation with stale catalog absence after reload', async () => {
  const calls = []
  const c = controller({ tiktokProductCreationSource: async () => { calls.push('GET'); return response(run('under_review')) }, startTiktokProductCreation: async () => calls.push('POST') })
  await c.start(source)
  assert.deepEqual(calls, ['GET'])
  assert.equal(canCreateTiktokProduct({ unified: true, accountKey: 'shopee-agnishopbjm', item, run: c.state.run }), false)
})

test('missing context is shown without automatically retrying a blocked run', async () => {
  const required_fields = [{ key: 'category_id', label: 'Kategori', type: 'category' }]
  const calls = []
  const c = controller({ tiktokProductCreationSource: async () => response(run('blocked', { required_fields })), startTiktokProductCreation: async () => calls.push('POST') })
  await c.start(source)
  assert.deepEqual(c.state.run.required_fields, required_fields)
  assert.deepEqual(calls, [])
})

test('safe explicit retry GETs latest permission and uses a new request identity and context', async () => {
  const calls = []; let keys = 0
  const blocked = run('blocked')
  const c = controller({ tiktokProductCreationSource: async () => { calls.push('GET'); return response(blocked) },
    startTiktokProductCreation: async payload => { calls.push(payload); return response(run('success')) }
  }, { crypto: { getRandomValues(bytes) { bytes.fill(++keys); return bytes } } })
  await c.start(source)
  await c.retry({ category_id: '600' })
  assert.equal(calls[1], 'GET')
  assert.equal(calls[2].context.category_id, '600')
})

test('lost start response only recovers by GET and pauses automatic writes', async () => {
  const calls = []; let gets = 0
  const c = controller({ tiktokProductCreationSource: async () => { calls.push('GET'); return response(++gets === 1 ? null : run()) },
    startTiktokProductCreation: async () => { calls.push('POST start'); throw Error('lost') },
    stepTiktokProductCreation: async () => { calls.push('POST step'); return response(run('success')) } })
  await c.start(source)
  assert.deepEqual(calls, ['GET', 'POST start', 'GET'])
  assert.equal(c.state.paused, true)
  await c.resume()
  assert.deepEqual(calls, ['GET', 'POST start', 'GET', 'GET', 'POST step'])
})

test('unknown start outcome retains the UUID and explicit retry reuses it after safe GET', async () => {
  const payloads = [], calls = []
  const c = controller({ tiktokProductCreationSource: async () => { calls.push('GET'); return response(null) },
    startTiktokProductCreation: async payload => { calls.push('POST'); payloads.push(structuredClone(payload)); if (payloads.length === 1) throw Error('lost'); return response(run('success')) } })
  await c.start(source)
  const key = c.state.pendingKey
  assert.ok(key)
  assert.deepEqual(calls, ['GET', 'POST', 'GET'])
  await c.retryPending()
  assert.equal(payloads[1].request_key, key)
  assert.deepEqual(payloads[1], payloads[0])
  assert.equal(c.state.pendingKey, '')
})

test('step network error uses GET-only recovery even when recovered run can continue', async () => {
  const calls = []
  const c = controller({ tiktokProductCreationSource: async () => { calls.push('GET'); return response(run()) },
    stepTiktokProductCreation: async () => { calls.push('POST step'); throw Error('lost') } })
  await c.start(source)
  assert.deepEqual(calls, ['GET', 'POST step', 'GET'])
  assert.equal(c.state.paused, true)
})

test('unmount stops continuation and busy propagation after an in-flight request settles', async () => {
  let finish; const calls = [], busy = []
  const c = controller({ tiktokProductCreationSource: async () => response(run()),
    stepTiktokProductCreation: async () => { calls.push('POST'); return new Promise(resolve => { finish = resolve }) }
  }, { onBusy: value => busy.push(value) })
  const pending = c.start(source)
  await new Promise(resolve => setImmediate(resolve))
  c.stop(); finish(response(run('scanning'))); await pending
  assert.deepEqual(calls, ['POST'])
  assert.deepEqual(busy, [true, false])
})

test('bounded automatic continuation pauses for explicit resume', async () => {
  let steps = 0
  const c = controller({ tiktokProductCreationSource: async () => response(run()), stepTiktokProductCreation: async () => { steps++; return response(run('scanning')) } }, { maxSteps: 2 })
  await c.start(source)
  assert.equal(steps, 2)
  assert.equal(c.state.paused, true)
})

test('normal continuation completes a catalog with more than two hundred bounded steps', async () => {
  let steps = 0
  const c = controller({ tiktokProductCreationSource: async () => response(run()),
    stepTiktokProductCreation: async () => response(run(++steps === 205 ? 'success' : 'scanning')) })
  await c.start(source)
  assert.equal(c.state.run.status, 'success')
  assert.equal(steps, 205)
})

test('check status is explicit readback only and unknown remote IDs never POST', async () => {
  const calls = []; let known = false
  const c = controller({ tiktokProductCreationSource: async () => { calls.push('GET'); return response(run('submitted_unverified', { remote_product_id: known ? 'remote' : null })) },
    stepTiktokProductCreation: async () => { calls.push('POST readback'); return response(run('under_review')) } })
  await c.start(source); await c.checkStatus()
  assert.deepEqual(calls, ['GET', 'GET'])
  known = true; await c.checkStatus()
  assert.deepEqual(calls, ['GET', 'GET', 'GET', 'POST readback'])
})

test('retry cannot bypass a newer accepted or uncertain source run', async () => {
  let latest = run('blocked'), posts = 0
  const c = controller({ tiktokProductCreationSource: async () => response(latest), startTiktokProductCreation: async () => { posts++ } })
  await c.start(source); latest = run('submitted_unverified'); await c.retry({ category_id: '600' })
  assert.equal(posts, 0)
})

test('form seeds only valid known values and sends complete fixed-unit package objects', () => {
  const saved = { category_id: '', warehouse_id: 'wh', package_weight: { value: '0.08', unit: 'KILOGRAM' }, package_dimensions: { length: '0', width: '20', height: '', unit: 'CENTIMETER' } }
  assert.deepEqual(creationFieldValues(saved), { category_id: '', warehouse_id: 'wh', 'package_weight.value': '0.08', 'package_dimensions.length': '', 'package_dimensions.width': '20', 'package_dimensions.height': '' })
  const fields = [{ key: 'category_id', type: 'category' }, { key: 'package_dimensions.length', type: 'number' }, { key: 'package_dimensions.height', type: 'number' }]
  assert.deepEqual(creationContext(saved, fields, { category_id: '600', 'package_dimensions.length': '10', 'package_dimensions.height': '3', warehouse_id: 'ignored' }),
    { category_id: '600', warehouse_id: 'wh', package_weight: { value: '0.08', unit: 'KILOGRAM' }, package_dimensions: { length: '10', width: '20', height: '3', unit: 'CENTIMETER' } })
  assert.throws(() => creationContext(saved, fields, { category_id: '', 'package_dimensions.length': '10', 'package_dimensions.height': '3' }), /Kategori|Lengkapi/)
  assert.throws(() => creationContext(saved, fields, { category_id: '600', 'package_dimensions.length': '0', 'package_dimensions.height': '3' }), /Lengkapi/)
})

test('category search uses named leaf paths without choosing a default', () => {
  const nodes = [{ id: '1', name: 'Fashion', parent_id: '', is_leaf: false }, { id: '2', name: 'Tas', parent_id: '1', is_leaf: true }, { id: '3', name: 'Kecantikan', parent_id: '', is_leaf: true }]
  assert.deepEqual(creationLeafCategories(nodes, 'fashion'), [{ id: '2', name: 'Fashion / Tas' }])
  assert.deepEqual(creationLeafCategories(nodes, 'unrelated'), [])
})

test('row results preserve accepted IDs and distinguish review, live, exists and uncertain', () => {
  const result = { product_id: 'remote', skus: [{ id: 'sku', seller_sku: 'SKU', source_variant_id: 'v' }], published: false }
  assert.equal(creationRowResult(run('under_review', { result })).label, 'Menunggu peninjauan TikTok')
  assert.deepEqual(creationRowResult(run('under_review', { result })).result, result)
  assert.equal(creationRowResult(run('success', { result: { ...result, published: true } })).label, 'Terverifikasi di TikTok')
  assert.equal(creationRowResult(run('submitted_unverified', { remote_product_id: 'remote', result })).label, 'Terkirim, belum terverifikasi')
  assert.equal(creationRowResult(run('exists', { result })).label, 'Sudah ditemukan di TikTok')
})

test('lost retry response retains its UUID even when GET still returns the previous blocked run', async () => {
  const payloads = []
  const c = controller({ tiktokProductCreationSource: async () => response(run('blocked')),
    startTiktokProductCreation: async payload => { payloads.push(structuredClone(payload)); throw Error('lost retry') } })
  await c.start(source); await c.retry({ category_id: '600' })
  assert.ok(c.state.pendingKey)
  await c.retry({ category_id: '601' })
  assert.equal(payloads.length, 1, 'a second retry cannot replace an ambiguous request')
  await c.retryPending()
  assert.deepEqual(payloads[1], payloads[0])
})

test('identifier-only reload restores accepted results without POST even with stale catalog', async () => {
  const values = new Map(), calls = []
  const storage = { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) }
  const api = { tiktokProductCreationSource: async () => { calls.push('GET'); return response(run('success', { result: { product_id: 'remote', skus: [], published: true } })) } }
  const first = controller(api, { storage }); await first.start(source)
  assert.ok([...values.values()].every(value => !value.includes('published') && !value.includes('category_id')))
  const restored = []
  const second = controller(api, { storage, onRun: value => restored.push(value) }); await second.restore()
  assert.equal(restored[0].status, 'success')
  assert.deepEqual(calls, ['GET', 'GET'])
})

test('unmount during the initial GET prevents starting any product', async () => {
  let finish, posts = 0
  const c = controller({ tiktokProductCreationSource: async () => new Promise(resolve => { finish = resolve }), startTiktokProductCreation: async () => { posts++ } })
  const pending = c.start(source); c.stop(); finish(response(null)); await pending
  assert.equal(posts, 0)
})

test('malformed GET responses and unavailable UUID generation never permit a POST', async () => {
  for (const lookup of [{ data: {} }, response({ ...run(), source_product_id: 'foreign' }), response(null)]) {
    let posts = 0
    const c = controller({ tiktokProductCreationSource: async () => lookup, startTiktokProductCreation: async () => { posts++ } }, { crypto: {} })
    await c.start(source)
    assert.equal(posts, 0)
    assert.ok(c.state.error)
  }
})

test('reload after an ambiguous filled-context retry only recovers identifiers without rebuilding a changed request', async () => {
  const records = new Map(), storage = { getItem: key => records.get(key), setItem: (key, value) => records.set(key, value) }
  let posts = 0
  const api = { tiktokProductCreationSource: async () => response(run('blocked')), startTiktokProductCreation: async () => { posts++; throw Error('lost') } }
  const first = controller(api, { storage }); await first.start(source); await first.retry({ category_id: '600' }); first.stop()
  const second = controller(api, { storage }); await second.restore(); await second.retryPending()
  assert.equal(posts, 1)
  assert.ok(second.state.pendingKey)
  assert.ok(second.state.error)
})

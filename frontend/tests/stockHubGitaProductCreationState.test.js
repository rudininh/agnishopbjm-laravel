import test from 'node:test'
import assert from 'node:assert/strict'

const { canCreateGitaProduct, creationRowResult, creationContext, creationFieldValues,
  creationLeafCategories, createGitaProductController } = await import('../src/pages/stockHubGitaProductCreationState.js')
const source = '123456789012345678'
const item = { item_id: source, destination_presence: { 'shopee-gitacollectionbjm': 'missing' } }
const run = (status = 'preparing', extra = {}) => ({ run_id: 'run-1', source_product_id: source,
  status, stage: status, next_step_after_ms: 0, message: 'Saved progress', can_continue: ['preparing', 'scanning', 'uploading', 'submitting', 'verifying', 'initializing_variants', 'publishing', 'verifying_publication'].includes(status),
  can_retry: ['blocked', 'rejected'].includes(status), remote_product_id: null, variant_count: 2,
  progress: { scanned_products: 0, uploaded_images: 0, total_images: 2 }, required_fields: [], context: {}, result: null, ...extra })
const response = value => ({ data: { data: value } })
const crypto = { getRandomValues(bytes) { bytes.fill(7); return bytes } }
const controller = (api, options = {}) => createGitaProductController(api, { crypto, ...options })

test('only missing Gitashop rows on the unified primary account can create', () => {
  assert.equal(typeof canCreateGitaProduct, 'function')
  const base = { unified: true, accountKey: 'shopee-agnishopbjm', item, busy: false }
  assert.equal(canCreateGitaProduct(base), true)
  for (const override of [{ unified: false }, { accountKey: 'shopee-gitacollectionbjm' }, { busy: true },
    { item: { ...item, destination_presence: { 'shopee-gitacollectionbjm': 'unknown' } } },
    { item: { ...item, destination_presence: { 'shopee-gitacollectionbjm': 'present' } } }, { item: {} }]) {
    assert.equal(canCreateGitaProduct({ ...base, ...override }), false)
  }
  assert.equal(canCreateGitaProduct({ ...base, run: run('success') }), false)
  assert.equal(canCreateGitaProduct({ ...base, run: run('submitted_unverified') }), false)
})

test('one click GETs source before POST and automatically advances bounded steps with Axios envelopes', async () => {
  const calls = [], busy = [], saved = []
  const c = controller({ gitaProductCreationSource: async id => { calls.push(['GET source', id]); return response(null) },
    startGitaProductCreation: async payload => { calls.push(['POST start', payload]); return response(run()) },
    stepGitaProductCreation: async id => { calls.push(['POST step', id]); return response(run(calls.length === 3 ? 'scanning' : 'under_review', { result: { product_id: 'target', skus: [{ id: 'sku-1', seller_sku: 'SKU-A', source_variant_id: 'v1' }], published: false } })) }
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
  const c = controller({ gitaProductCreationSource: async () => { calls.push('GET'); return new Promise(resolve => { finish = resolve }) },
    startGitaProductCreation: async () => { calls.push('POST'); return response(run('success')) } })
  const first = c.start(source)
  await c.start(source)
  await c.start('999')
  finish(response(null)); await first
  assert.deepEqual(calls, ['GET', 'POST'])
})

test('a failed read-only lookup never authorizes a create', async () => {
  let posts = 0
  const c = controller({ gitaProductCreationSource: async () => { throw Error('offline') }, startGitaProductCreation: async () => { posts++ } })
  await c.start(source)
  assert.equal(posts, 0)
  assert.ok(c.state.error)
  assert.equal(c.state.busy, false)
})

test('accepted recovery prevents creation with stale catalog absence after reload', async () => {
  const calls = []
  const c = controller({ gitaProductCreationSource: async () => { calls.push('GET'); return response(run('under_review')) }, startGitaProductCreation: async () => calls.push('POST') })
  await c.start(source)
  assert.deepEqual(calls, ['GET'])
  assert.equal(canCreateGitaProduct({ unified: true, accountKey: 'shopee-agnishopbjm', item, run: c.state.run }), false)
})

test('missing context is shown without automatically retrying a blocked run', async () => {
  const required_fields = [{ key: 'category_id', label: 'Kategori', type: 'category' }]
  const calls = []
  const c = controller({ gitaProductCreationSource: async () => response(run('blocked', { required_fields })), startGitaProductCreation: async () => calls.push('POST') })
  await c.start(source)
  assert.deepEqual(c.state.run.required_fields, required_fields)
  assert.deepEqual(calls, [])
})

test('safe explicit retry GETs latest permission and uses a new request identity and context', async () => {
  const calls = []; let keys = 0
  const blocked = run('blocked')
  const c = controller({ gitaProductCreationSource: async () => { calls.push('GET'); return response(blocked) },
    startGitaProductCreation: async payload => { calls.push(payload); return response(run('success')) }
  }, { crypto: { getRandomValues(bytes) { bytes.fill(++keys); return bytes } } })
  await c.start(source)
  await c.retry({ category_id: '600' })
  assert.equal(calls[1], 'GET')
  assert.equal(calls[2].context.category_id, '600')
})

test('lost start response only recovers by GET and pauses automatic writes', async () => {
  const calls = []; let gets = 0
  const c = controller({ gitaProductCreationSource: async () => { calls.push('GET'); return response(++gets === 1 ? null : run()) },
    startGitaProductCreation: async () => { calls.push('POST start'); throw Error('lost') },
    stepGitaProductCreation: async () => { calls.push('POST step'); return response(run('success')) } })
  await c.start(source)
  assert.deepEqual(calls, ['GET', 'POST start', 'GET'])
  assert.equal(c.state.paused, true)
  await c.resume()
  assert.deepEqual(calls, ['GET', 'POST start', 'GET', 'GET', 'POST step'])
})

test('unknown start outcome retains the UUID and explicit retry reuses it after safe GET', async () => {
  const payloads = [], calls = []
  const c = controller({ gitaProductCreationSource: async () => { calls.push('GET'); return response(null) },
    startGitaProductCreation: async payload => { calls.push('POST'); payloads.push(structuredClone(payload)); if (payloads.length === 1) throw Error('lost'); return response(run('success')) } })
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
  const c = controller({ gitaProductCreationSource: async () => { calls.push('GET'); return response(run()) },
    stepGitaProductCreation: async () => { calls.push('POST step'); throw Error('lost') } })
  await c.start(source)
  assert.deepEqual(calls, ['GET', 'POST step', 'GET'])
  assert.equal(c.state.paused, true)
})

test('unmount stops continuation and busy propagation after an in-flight request settles', async () => {
  let finish; const calls = [], busy = []
  const c = controller({ gitaProductCreationSource: async () => response(run()),
    stepGitaProductCreation: async () => { calls.push('POST'); return new Promise(resolve => { finish = resolve }) }
  }, { onBusy: value => busy.push(value) })
  const pending = c.start(source)
  await new Promise(resolve => setImmediate(resolve))
  c.stop(); finish(response(run('scanning'))); await pending
  assert.deepEqual(calls, ['POST'])
  assert.deepEqual(busy, [true, false])
})

test('bounded automatic continuation pauses for explicit resume', async () => {
  let steps = 0
  const c = controller({ gitaProductCreationSource: async () => response(run()), stepGitaProductCreation: async () => { steps++; return response(run('scanning')) } }, { maxSteps: 2 })
  await c.start(source)
  assert.equal(steps, 2)
  assert.equal(c.state.paused, true)
})

test('normal continuation completes a catalog with more than two hundred bounded steps', async () => {
  let steps = 0
  const c = controller({ gitaProductCreationSource: async () => response(run()),
    stepGitaProductCreation: async () => response(run(++steps === 205 ? 'success' : 'scanning')) })
  await c.start(source)
  assert.equal(c.state.run.status, 'success')
  assert.equal(steps, 205)
})

test('check status is explicit readback only and unknown remote IDs never POST', async () => {
  const calls = []; let known = false
  const c = controller({ gitaProductCreationSource: async () => { calls.push('GET'); return response(run('submitted_unverified', { remote_product_id: known ? 'remote' : null })) },
    stepGitaProductCreation: async () => { calls.push('POST readback'); return response(run('under_review')) } })
  await c.start(source); await c.checkStatus()
  assert.deepEqual(calls, ['GET', 'GET'])
  known = true; await c.checkStatus()
  assert.deepEqual(calls, ['GET', 'GET', 'GET', 'POST readback'])
})

test('retry cannot bypass a newer accepted or uncertain source run', async () => {
  let latest = run('blocked'), posts = 0
  const c = controller({ gitaProductCreationSource: async () => response(latest), startGitaProductCreation: async () => { posts++ } })
  await c.start(source); latest = run('submitted_unverified'); await c.retry({ category_id: '600' })
  assert.equal(posts, 0)
})

test('lost retry response retains its UUID even when GET still returns the previous blocked run', async () => {
  const payloads = []
  const c = controller({ gitaProductCreationSource: async () => response(run('blocked')),
    startGitaProductCreation: async payload => { payloads.push(structuredClone(payload)); throw Error('lost retry') } })
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
  const api = { gitaProductCreationSource: async () => { calls.push('GET'); return response(run('success', { result: { product_id: 'remote', skus: [], published: true } })) } }
  const first = controller(api, { storage }); await first.start(source)
  assert.ok([...values.values()].every(value => !value.includes('published') && !value.includes('category_id')))
  const restored = []
  const second = controller(api, { storage, onRun: value => restored.push(value) }); await second.restore()
  assert.equal(restored[0].status, 'success')
  assert.deepEqual(calls, ['GET', 'GET'])
})

test('unmount during the initial GET prevents starting any product', async () => {
  let finish, posts = 0
  const c = controller({ gitaProductCreationSource: async () => new Promise(resolve => { finish = resolve }), startGitaProductCreation: async () => { posts++ } })
  const pending = c.start(source); c.stop(); finish(response(null)); await pending
  assert.equal(posts, 0)
})

test('malformed GET responses and unavailable UUID generation never permit a POST', async () => {
  for (const lookup of [{ data: {} }, response({ ...run(), source_product_id: 'foreign' }), response(null)]) {
    let posts = 0
    const c = controller({ gitaProductCreationSource: async () => lookup, startGitaProductCreation: async () => { posts++ } }, { crypto: {} })
    await c.start(source)
    assert.equal(posts, 0)
    assert.ok(c.state.error)
  }
})

test('reload after an ambiguous filled-context retry only recovers identifiers without rebuilding a changed request', async () => {
  const records = new Map(), storage = { getItem: key => records.get(key), setItem: (key, value) => records.set(key, value) }
  let posts = 0
  const api = { gitaProductCreationSource: async () => response(run('blocked')), startGitaProductCreation: async () => { posts++; throw Error('lost') } }
  const first = controller(api, { storage }); await first.start(source); await first.retry({ category_id: '600' }); first.stop()
  const second = controller(api, { storage }); await second.restore(); await second.retryPending()
  assert.equal(posts, 1)
  assert.ok(second.state.pendingKey)
  assert.ok(second.state.error)
})

test('remembered source identities prevent creation during and after failed restore without claiming a run outcome', async () => {
  let rejectLookup; const remembered = [], runs = [], calls = []
  const storage = { getItem: () => JSON.stringify({ [source]: { run_id: 'saved-accepted-run' }, '999': { request_key: 'saved-request' } }) }
  const c = controller({ gitaProductCreationSource: async id => { calls.push(['GET', id]); if (id === source) return new Promise((resolve, reject) => { rejectLookup = reject }); throw Error('offline') },
    startGitaProductCreation: async () => calls.push(['POST']) },
  { storage, onRemember: sourceId => remembered.push(sourceId), onRun: saved => runs.push(saved) })
  const restoring = c.restore()
  assert.deepEqual([...remembered].sort(), [source, '999'])
  assert.equal(canCreateGitaProduct({ unified: true, accountKey: 'shopee-agnishopbjm', item, remembered: remembered.includes(source) }), false)
  await new Promise(resolve => setImmediate(resolve))
  rejectLookup(Error('offline')); await restoring
  assert.equal(canCreateGitaProduct({ unified: true, accountKey: 'shopee-agnishopbjm', item, remembered: remembered.includes(source) }), false)
  assert.deepEqual(runs, [])
  assert.deepEqual(calls.map(call => call[0]), ['GET', 'GET'])
})


test('waiting initialization serializes all publication stages and honors bounded asynchronous delay', async () => {
  const calls = [], waits = []
  const stages = ['initializing_variants', 'verifying', 'publishing', 'verifying_publication', 'success']
  const c = controller({ gitaProductCreationSource: async () => response(null),
    startGitaProductCreation: async () => response(run('submitting', { next_step_after_ms: 5000 })),
    stepGitaProductCreation: async () => { calls.push('step'); return response(run(stages.shift())) }
  }, { sleep: async ms => { waits.push(ms); calls.push('wait') } })
  await c.start(source)
  assert.deepEqual(waits, [5000])
  assert.deepEqual(calls, ['wait', 'step', 'step', 'step', 'step', 'step'])
  assert.equal(c.state.run.status, 'success')
})

test('unmount during the parent delay prevents every later mutation', async () => {
  let finish, steps = 0
  const c = controller({ gitaProductCreationSource: async () => response(run('initializing_variants', { next_step_after_ms: 5000 })),
    stepGitaProductCreation: async () => { steps++; return response(run('success')) }
  }, { sleep: () => new Promise(resolve => { finish = resolve }) })
  const pending = c.start(source)
  await new Promise(resolve => setImmediate(resolve)); c.stop(); finish(); await pending
  assert.equal(steps, 0)
})

for (const status of ['partial_unverified', 'under_review']) {
  test(`known ${status} checks exactly one step with fresh GET and never starts a new parent`, async () => {
    const calls = []
    const saved = run(status, { remote_product_id: '200', can_retry: true, result: { product_id: '200', skus: [], published: false } })
    const c = controller({ gitaProductCreationSource: async () => { calls.push('GET'); return response(saved) },
      stepGitaProductCreation: async () => { calls.push('check'); return response(run('publishing', { remote_product_id: '200', result: saved.result })) },
      startGitaProductCreation: async () => calls.push('create') })
    await c.start(source); await c.retry({ category_id: '10' }); await c.checkStatus()
    assert.deepEqual(calls, ['GET', 'GET', 'GET', 'check'])
    assert.equal(c.state.run.remote_product_id, '200')
    assert.equal(c.state.run.result.product_id, '200')
    assert.equal(creationRowResult(saved).accepted, false)
  })
}

test('Gita corrections retain the entire known context and validate grouped integer cm and verified options', () => {
  const saved = { category_id: '10', weight: 0.5, dimension: { package_length: 10, package_width: 20, package_height: 3 },
    logistic_ids: ['1'], location_id: 'loc', size_chart_image_url: 'https://cdn.example/chart.jpg' }
  const fields = [{ key: 'dimension', type: 'number', unit: 'cm' }, { key: 'logistic_ids', type: 'multiselect', options: [{ id: '2', name: 'Courier' }] },
    { key: 'location_id', type: 'select', options: [{ id: 'new', name: 'Warehouse' }] }]
  const values = { ...creationFieldValues(saved), 'dimension.package_length': '12', logistic_ids: ['2'], location_id: 'new' }
  assert.deepEqual(creationContext(saved, fields, values), { ...saved, dimension: { package_length: 12, package_width: 20, package_height: 3 }, logistic_ids: ['2'], location_id: 'new' })
  for (const value of ['0', '1.5', '-1', 'NaN']) assert.throws(() => creationContext(saved, fields, { ...values, 'dimension.package_length': value }))
  assert.throws(() => creationContext(saved, fields, { ...values, logistic_ids: ['999'] }))
  assert.throws(() => creationContext(saved, fields, { ...values, location_id: 'unknown' }))
  assert.throws(() => creationContext(saved, [{ key: 'size_chart_image_url', type: 'url' }], { size_chart_image_url: 'http://cdn.example/a.jpg' }))
})

test('category leaf search includes parent names and partial result retains actual identifiers without acceptance', () => {
  assert.deepEqual(creationLeafCategories([{ id: '1', name: 'Fashion', is_leaf: false }, { id: '2', name: 'Tas', parent_id: '1', is_leaf: true }], 'fashion'), [{ id: '2', name: 'Fashion / Tas' }])
  const result = { product_id: '200', skus: [{ id: '300', seller_sku: 'INT-1', source_variant_id: '1' }], published: false }
  assert.deepEqual(creationRowResult(run('partial_unverified', { result })).result, result)
  assert.equal(creationRowResult(run('partial_unverified', { result })).accepted, false)
  assert.equal(creationRowResult(run('success', { result: { ...result, published: true } })).accepted, true)
})

test('Agni package mode discards historical package overrides and retains other validated context', () => {
  const saved = { category_id: '10', weight: 5, dimension: { package_length: 100, package_width: 100, package_height: 5 }, logistic_ids: ['999'], location_id: 'loc' }
  const fields = [{ key: 'weight', type: 'number' }, { key: 'dimension', type: 'number' }, { key: 'logistic_ids', type: 'multiselect', options: [] }, { key: 'location_id', type: 'select', options: [{ id: 'new', name: 'Warehouse' }] }]
  assert.deepEqual(creationContext(saved, fields, { location_id: 'new' }, true), { category_id: '10', location_id: 'new', use_agni_shipping: true })
  assert.throws(() => creationContext(saved, fields, { location_id: 'new' }, false))
  assert.throws(() => creationContext(saved, fields, { location_id: 'unknown' }, true))
})

test('Gita and TikTok persist distinct identifier namespaces', async () => {
  const { createTiktokProductController } = await import('../src/pages/stockHubTiktokProductCreationState.js')
  const records = new Map(), storage = { getItem: key => records.get(key), setItem: (key, value) => records.set(key, value) }
  await controller({ gitaProductCreationSource: async () => response(run('success')) }, { storage }).start(source)
  await createTiktokProductController({ tiktokProductCreationSource: async () => response(run('blocked')) }, { storage }).start(source)
  assert.deepEqual([...records.keys()].sort(), ['stock-hub-gita-creation:identifiers', 'stock-hub-tiktok-creation:identifiers'])
})

test('verified exists proves destination presence even without a publication result', () => {
  assert.equal(creationRowResult(run('exists', { result: { product_id: '200', skus: [], published: false } })).accepted, true)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { createTiktokVariantBatchController } from '../src/pages/stockHubTiktokVariantBatchState.js'

const response = data => ({ data: { data } })
const run = (status = 'running', extra = {}) => ({ run_id: 'batch-1', matches_request_key: true, workflow: 'agni_sku_repair_then_tiktok', can_resume_attention: false, status, message: 'Progres tersimpan', can_continue: status === 'running', next_step_after_ms: 0,
  summary: { products_total: 2, products_processed: status === 'completed' ? 2 : 0, variants_verified: 1, variants_pending: 1, variants_failed: 0, variants_skipped: 0, products_need_attention: 1,
    agni_skus_verified: 0, tiktok_variants_repaired: 0, tiktok_variants_removed: 0, products_waiting_review: 0 },
  items: [], current_source_product_id: null, ...extra })
const crypto = { getRandomValues(bytes) { bytes.fill(7); return bytes } }
const setup = (api = {}, options = {}) => createTiktokVariantBatchController({ activeTiktokVariantBatch: async () => response(null), ...api }, { crypto, ...options })
const storage = initial => { let value = initial ? JSON.stringify(initial) : null; return { getItem: () => value, setItem: (key, next) => { assert.equal(key, 'stock-hub-tiktok-variant-batch:identifiers'); value = next } } }

test('request timing stays visible while a step is pending and response progress drives bounded history', async () => {
  let finish, now = 1000
  const item = { source_product_id: '10', name: 'Azara', status: 'scanning', workflow_stage: 'repair', message: 'Checking', progress: { scanned_products: 7, total_products: 68 } }
  const c = setup({ startTiktokVariantBatch: async () => response(run('running', { items: [item], current_source_product_id: '10' })),
    stepTiktokVariantBatch: async () => new Promise(resolve => { finish = resolve })
  }, { now: () => now })
  const pending = c.start(); await new Promise(resolve => setImmediate(resolve))
  assert.equal(c.state.requestKind, 'step'); assert.equal(c.state.requestStartedAt, 1000)
  assert.equal(c.state.lastResponseAt, 1000); assert.equal(c.state.activities.length, 1)
  assert.match(c.state.activities[0].message, /7\/68/)
  now = 46000; finish(response(run('completed', { items: [{ ...item, status: 'success' }] }))); await pending
  assert.equal(c.state.requestKind, ''); assert.equal(c.state.lastResponseAt, now)
  assert.equal(c.state.activities.length, 2); assert.equal(c.state.activities[0].at, now)
  const noWrites = setup({ activeTiktokVariantBatch: async () => response(run('running', { items: [item], current_source_product_id: '10' })) }, { now: () => now })
  for (let i = 0; i < 35; i++) { item.progress.scanned_products++; await noWrites.recover() }
  assert.equal(noWrites.state.activities.length, 30)
  await noWrites.recover(); assert.equal(noWrites.state.activities.length, 30)
  assert.equal(noWrites.state.paused, true)
})

test('GET-only restore then explicit catalog start persists identifiers before POST and follows delays serially', async () => {
  const saved = storage(), calls = [], delays = []; let steps = 0
  const c = setup({ startTiktokVariantBatch: async payload => { calls.push(['start', payload]); assert.deepEqual(JSON.parse(saved.getItem()), { request_key: payload.request_key }); return response(run('running', { next_step_after_ms: 1000 })) },
    stepTiktokVariantBatch: async id => { calls.push(['step', id]); return response(++steps === 1 ? run('running', { next_step_after_ms: 5000 }) : run('completed')) }
  }, { storage: saved, sleep: async ms => delays.push(ms) })
  await c.restore(); assert.deepEqual(calls, [])
  await c.start()
  assert.deepEqual(Object.keys(calls[0][1]), ['request_key', 'workflow'])
  assert.equal(calls[0][1].workflow, 'agni_sku_repair_then_tiktok')
  assert.match(calls[0][1].request_key, /^[a-f0-9-]{36}$/)
  assert.deepEqual(calls.slice(1), [['step', 'batch-1'], ['step', 'batch-1']])
  assert.deepEqual(delays, [1000, 5000]); assert.equal(c.state.busy, false)
  assert.deepEqual(JSON.parse(saved.getItem()), { run_id: 'batch-1' })
})

test('active or saved running recovery opens progress without POST and requires explicit resume', async () => {
  const calls = []
  const c = setup({ activeTiktokVariantBatch: async () => response(run('paused_error')),
    resumeTiktokVariantBatch: async (id, payload) => { calls.push(['resume', id, payload]); return response(run()) },
    stepTiktokVariantBatch: async id => { calls.push(['step', id]); return response(run('completed')) } })
  await c.restore(); await c.start(); assert.deepEqual(calls, []); assert.equal(c.state.paused, true)
  await c.resume(); assert.deepEqual(calls, [['resume', 'batch-1', { workflow: 'agni_sku_repair_then_tiktok' }], ['step', 'batch-1']])
})

test('failed and malformed GET never authorize start or resume', async () => {
  for (const get of [async () => { throw Error('offline') }, async () => ({}), async () => response({ run_id: 'bad' })]) {
    let writes = 0
    const c = setup({ activeTiktokVariantBatch: get, startTiktokVariantBatch: async () => { writes++ }, resumeTiktokVariantBatch: async () => { writes++ } })
    await c.restore(); await c.start(); await c.resume()
    assert.equal(writes, 0); assert.ok(c.state.error); assert.equal(c.state.busy, false)
  }
})

test('lost start retains UUID across reload and retries only explicitly after GET absence', async () => {
  const saved = storage(), keys = []
  const c = setup({ startTiktokVariantBatch: async payload => { keys.push(payload.request_key); throw Error('lost') } }, { storage: saved })
  await c.start(); assert.ok(c.state.pendingKey); assert.equal(c.state.paused, true)
  const restored = setup({ startTiktokVariantBatch: async payload => { keys.push(payload.request_key); return response(run('completed')) } }, { storage: saved })
  await restored.restore(); assert.equal(keys.length, 1)
  await restored.start(); assert.equal(keys.length, 2); assert.equal(keys[1], keys[0])
})

test('lost or malformed step recovers by GET without continuing and validates same run identity', async () => {
  for (const step of [async () => { throw Error('lost') }, async () => response(run('completed', { run_id: 'wrong' }))]) {
    let gets = 0, steps = 0
    const c = setup({ activeTiktokVariantBatch: async () => response(++gets === 1 ? null : run('paused')),
      startTiktokVariantBatch: async () => response(run()), stepTiktokVariantBatch: async () => { steps++; return step() } })
    await c.start(); assert.equal(steps, 1); assert.equal(gets, 2); assert.equal(c.state.run.status, 'paused'); assert.equal(c.state.paused, true)
  }
})

test('pause immediately stops scheduling, serializes POST after outstanding step and holds busy through pause', async () => {
  let finishStep, finishPause; const calls = [], busy = []
  const c = setup({ startTiktokVariantBatch: async () => response(run()),
    stepTiktokVariantBatch: async () => { calls.push('step'); return new Promise(resolve => { finishStep = resolve }) },
    pauseTiktokVariantBatch: async () => { calls.push('pause'); return new Promise(resolve => { finishPause = resolve }) }
  }, { onBusy: value => busy.push(value) })
  const starting = c.start(); await new Promise(resolve => setImmediate(resolve))
  const pausing = c.pause(); assert.equal(c.state.paused, true); assert.equal(c.state.busy, true); assert.deepEqual(calls, ['step'])
  finishStep(response(run())); await starting; await new Promise(resolve => setImmediate(resolve))
  assert.deepEqual(calls, ['step', 'pause']); assert.deepEqual(busy, [true]); assert.equal(c.state.busy, true)
  finishPause(response(run('paused'))); await pausing; assert.deepEqual(busy, [true, false])
})

test('saved completed history remains readable and deliberately starting chooses a fresh identity', async () => {
  const saved = storage({ run_id: 'batch-1' }); let payload
  const c = setup({ tiktokVariantBatchRun: async id => { assert.equal(id, 'batch-1'); return response(run('completed')) },
    startTiktokVariantBatch: async value => { payload = value; return response(run('completed', { run_id: 'batch-2' })) }
  }, { storage: saved })
  await c.restore(); assert.equal(c.state.run.status, 'completed'); assert.equal(payload, undefined)
  await c.start(); assert.deepEqual(Object.keys(payload), ['request_key', 'workflow']); assert.equal(c.state.run.run_id, 'batch-2')
})

test('stop during delay or outstanding response prevents further steps and retains saved identity', async () => {
  let release; let steps = 0; const saved = storage()
  const c = setup({ startTiktokVariantBatch: async () => response(run('running', { next_step_after_ms: 1000 })), stepTiktokVariantBatch: async () => { steps++; return response(run()) } },
    { storage: saved, sleep: () => new Promise(resolve => { release = resolve }) })
  const pending = c.start(); await new Promise(resolve => setImmediate(resolve)); c.stop(); release(); await pending
  assert.equal(steps, 0); assert.deepEqual(JSON.parse(saved.getItem()), { run_id: 'batch-1' })
})


test('completed attention header restores the saved run and only explicit continuation resumes its identity', async () => {
  const saved = storage({ run_id: 'batch-1' }), calls = []
  const c = setup({ tiktokVariantBatchRun: async () => response(run('completed', { can_resume_attention: true })),
    startTiktokVariantBatch: async () => { calls.push('unexpected start') },
    resumeTiktokVariantBatch: async (id, payload) => { calls.push(['resume', id, payload]); return response(run()) },
    stepTiktokVariantBatch: async () => { calls.push('step'); return response(run('completed')) }
  }, { storage: saved })
  await c.restore(); await c.start()
  assert.deepEqual(calls, []); assert.equal(c.state.run.run_id, 'batch-1'); assert.equal(c.state.paused, true)
  await c.resume()
  assert.deepEqual(calls, [['resume', 'batch-1', { workflow: 'agni_sku_repair_then_tiktok' }], 'step'])
})

test('legacy restore never upgrades and explicit resume requests the new workflow even for completed history', async () => {
  for (const status of ['paused', 'completed']) {
    const calls = [], saved = storage({ run_id: 'batch-1' })
    const c = setup({ tiktokVariantBatchRun: async () => response(run(status, { workflow: 'legacy' })),
      resumeTiktokVariantBatch: async (id, payload) => { calls.push([id, payload]); return response(run('completed')) }
    }, { storage: saved })
    await c.restore(); assert.deepEqual(calls, [])
    await c.resume(); assert.deepEqual(calls, [['batch-1', { workflow: 'agni_sku_repair_then_tiktok' }]])
  }
})

test('an active legacy run returned after a lost create race waits for explicit upgrade without stepping', async () => {
  let steps = 0
  const c = setup({ startTiktokVariantBatch: async () => response(run('running', { workflow: 'legacy' })),
    stepTiktokVariantBatch: async () => { steps++; return response(run('completed')) }
  })
  await c.start(); assert.equal(steps, 0); assert.equal(c.state.paused, true)
})

test('unknown workflow or malformed attention eligibility cannot authorize a fresh start or continuation', async () => {
  for (const extra of [{ workflow: 'future-flow' }, { workflow: undefined }, { can_resume_attention: 'true' }, { can_resume_attention: undefined }]) {
    const writes = []
    const c = setup({ activeTiktokVariantBatch: async () => response(run('completed', extra)),
      startTiktokVariantBatch: async () => writes.push('start'), resumeTiktokVariantBatch: async () => writes.push('resume') })
    await c.restore(); await c.start(); await c.resume()
    assert.deepEqual(writes, []); assert.ok(c.state.error)
  }
})

test('lost create key alongside older attention history stays recoverable only through explicit same-request retry', async () => {
  const saved = storage({ run_id: 'batch-1', request_key: 'saved-request-key' }), calls = []
  const c = setup({ tiktokVariantBatchRun: async () => response(run('completed', { can_resume_attention: true })),
    startTiktokVariantBatch: async payload => { calls.push(payload); return response(run('completed', { run_id: 'batch-2' })) }
  }, { storage: saved })
  await c.restore(); await c.start()
  assert.deepEqual(calls, []); assert.equal(c.state.pendingKey, 'saved-request-key')
  await c.start({ retryPending: true })
  assert.deepEqual(calls, [{ request_key: 'saved-request-key', workflow: 'agni_sku_repair_then_tiktok' }])
  assert.equal(c.state.run.run_id, 'batch-2'); assert.equal(c.state.pendingKey, '')
})

test('completed legacy attention history opens for explicit upgrade while clean legacy history may start fresh', async () => {
  for (const attention of [1, 0]) {
    let starts = 0
    const c = setup({ activeTiktokVariantBatch: async () => response(run('completed', { workflow: 'legacy', summary: { ...run().summary, products_need_attention: attention } })),
      startTiktokVariantBatch: async () => { starts++; return response(run('completed')) } })
    await c.start()
    assert.equal(starts, attention ? 0 : 1)
    if (attention) assert.equal(c.state.run.workflow, 'legacy')
  }
})

test('explicit fresh catalog escapes permanent attention and includes new cached products', async () => {
  const saved = storage({ run_id: 'batch-1' }), writes = []
  const old = run('completed', { can_resume_attention: true, items: [{ source_product_id: '10', name: 'Missing destination', status: 'missing_product' }] })
  const c = setup({ tiktokVariantBatchRun: async () => response(old),
    startTiktokVariantBatch: async payload => { writes.push(payload); return response(run('completed', { run_id: 'batch-2', items: [...old.items, { source_product_id: '30', name: 'New cached product', status: 'success' }] })) }
  }, { storage: saved })
  await c.restore(); await c.start(); assert.deepEqual(writes, [])
  await c.startFresh()
  assert.equal(writes.length, 1); assert.equal(writes[0].workflow, 'agni_sku_repair_then_tiktok')
  assert.equal(c.state.run.run_id, 'batch-2'); assert.deepEqual(c.state.run.items.map(item => item.source_product_id), ['10', '30'])
})

test('fresh catalog cannot bypass an active catalog claim or unresolved create key', async () => {
  for (const status of ['running', 'paused', 'paused_error', 'completed']) {
    const saved = storage({ run_id: 'batch-1', ...(status === 'completed' ? { request_key: 'lost-key' } : {}) }), writes = []
    const c = setup({ activeTiktokVariantBatch: async () => response(status === 'completed' ? null : run(status)),
      tiktokVariantBatchRun: async () => response(run('completed', { can_resume_attention: true })), startTiktokVariantBatch: async payload => { writes.push(payload); return response(run('completed', { run_id: 'batch-2' })) }
    }, { storage: saved })
    await c.restore(); await c.startFresh(); assert.deepEqual(writes, [])
    if (status === 'completed') {
      assert.equal(c.state.pendingKey, 'lost-key'); await c.start({ retryPending: true })
      assert.deepEqual(writes, [{ request_key: 'lost-key', workflow: 'agni_sku_repair_then_tiktok' }])
    }
  }
})

test('GET to POST catalog claim race restores the competing workflow and waits for explicit resume', async () => {
  const writes = [], saved = storage({ run_id: 'batch-1' })
  let active = null
  const c = setup({ activeTiktokVariantBatch: async () => response(active),
    tiktokVariantBatchRun: async () => response(run('completed', { can_resume_attention: true })),
    startTiktokVariantBatch: async payload => { writes.push(['start', payload]); active = run('running', { run_id: 'competing-batch', matches_request_key: false }); return response(active) },
    resumeTiktokVariantBatch: async id => { writes.push(['resume', id]); return response(active) },
    stepTiktokVariantBatch: async id => { writes.push(['step', id]); return response(run('completed', { run_id: id })) }
  }, { storage: saved })
  await c.restore(); await c.startFresh()
  assert.equal(writes.length, 1); assert.equal(c.state.run.run_id, 'competing-batch'); assert.equal(c.state.paused, true)
  assert.equal(c.state.pendingKey, ''); await c.resume()
  assert.deepEqual(writes.slice(1), [['resume', 'competing-batch'], ['step', 'competing-batch']])
})

test('missing or malformed start provenance never authorizes automatic steps', async () => {
  for (const matches_request_key of [undefined, false, 'true']) {
    let steps = 0
    const c = setup({ startTiktokVariantBatch: async () => response(run('running', { matches_request_key })), stepTiktokVariantBatch: async () => { steps++; return response(run('completed')) } })
    await c.start(); assert.equal(steps, 0); assert.equal(c.state.paused, true)
  }
})

test('fresh action cannot discard a pending create key when a competing catalog appears', async () => {
  let active = null, starts = 0
  const saved = storage({ run_id: 'batch-1', request_key: 'lost-key' })
  const c = setup({ activeTiktokVariantBatch: async () => response(active),
    tiktokVariantBatchRun: async () => response(run('completed', { can_resume_attention: true })), startTiktokVariantBatch: async () => { starts++ }
  }, { storage: saved })
  await c.restore(); active = run('paused', { run_id: 'other-batch' }); await c.startFresh()
  assert.equal(starts, 0); assert.equal(c.state.pendingKey, 'lost-key'); assert.equal(JSON.parse(saved.getItem()).request_key, 'lost-key')
})

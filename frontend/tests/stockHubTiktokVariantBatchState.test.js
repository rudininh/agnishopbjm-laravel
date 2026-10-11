import test from 'node:test'
import assert from 'node:assert/strict'
import { createTiktokVariantBatchController } from '../src/pages/stockHubTiktokVariantBatchState.js'

const response = data => ({ data: { data } })
const run = (status = 'running', extra = {}) => ({ run_id: 'batch-1', status, message: 'Progres tersimpan', can_continue: status === 'running', next_step_after_ms: 0,
  summary: { products_total: 2, products_processed: status === 'completed' ? 2 : 0, variants_verified: 1, variants_pending: 1, variants_failed: 0, variants_skipped: 0, products_need_attention: 1 },
  items: [], current_source_product_id: null, ...extra })
const crypto = { getRandomValues(bytes) { bytes.fill(7); return bytes } }
const setup = (api = {}, options = {}) => createTiktokVariantBatchController({ activeTiktokVariantBatch: async () => response(null), ...api }, { crypto, ...options })
const storage = initial => { let value = initial ? JSON.stringify(initial) : null; return { getItem: () => value, setItem: (key, next) => { assert.equal(key, 'stock-hub-tiktok-variant-batch:identifiers'); value = next } } }

test('GET-only restore then explicit catalog start persists identifiers before POST and follows delays serially', async () => {
  const saved = storage(), calls = [], delays = []; let steps = 0
  const c = setup({ startTiktokVariantBatch: async payload => { calls.push(['start', payload]); assert.deepEqual(JSON.parse(saved.getItem()), { request_key: payload.request_key }); return response(run('running', { next_step_after_ms: 1000 })) },
    stepTiktokVariantBatch: async id => { calls.push(['step', id]); return response(++steps === 1 ? run('running', { next_step_after_ms: 5000 }) : run('completed')) }
  }, { storage: saved, sleep: async ms => delays.push(ms) })
  await c.restore(); assert.deepEqual(calls, [])
  await c.start()
  assert.deepEqual(Object.keys(calls[0][1]), ['request_key'])
  assert.match(calls[0][1].request_key, /^[a-f0-9-]{36}$/)
  assert.deepEqual(calls.slice(1), [['step', 'batch-1'], ['step', 'batch-1']])
  assert.deepEqual(delays, [1000, 5000]); assert.equal(c.state.busy, false)
  assert.deepEqual(JSON.parse(saved.getItem()), { run_id: 'batch-1' })
})

test('active or saved running recovery opens progress without POST and requires explicit resume', async () => {
  const calls = []
  const c = setup({ activeTiktokVariantBatch: async () => response(run('paused_error')),
    resumeTiktokVariantBatch: async id => { calls.push(['resume', id]); return response(run()) },
    stepTiktokVariantBatch: async id => { calls.push(['step', id]); return response(run('completed')) } })
  await c.restore(); await c.start(); assert.deepEqual(calls, []); assert.equal(c.state.paused, true)
  await c.resume(); assert.deepEqual(calls, [['resume', 'batch-1'], ['step', 'batch-1']])
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
  await c.start(); assert.deepEqual(Object.keys(payload), ['request_key']); assert.equal(c.state.run.run_id, 'batch-2')
})

test('stop during delay or outstanding response prevents further steps and retains saved identity', async () => {
  let release; let steps = 0; const saved = storage()
  const c = setup({ startTiktokVariantBatch: async () => response(run('running', { next_step_after_ms: 1000 })), stepTiktokVariantBatch: async () => { steps++; return response(run()) } },
    { storage: saved, sleep: () => new Promise(resolve => { release = resolve }) })
  const pending = c.start(); await new Promise(resolve => setImmediate(resolve)); c.stop(); release(); await pending
  assert.equal(steps, 0); assert.deepEqual(JSON.parse(saved.getItem()), { run_id: 'batch-1' })
})

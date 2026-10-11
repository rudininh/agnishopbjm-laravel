import test from 'node:test'
import assert from 'node:assert/strict'
import { canAddTiktokVariants, createTiktokVariantController, variantPriceContext, variantRowResult } from '../src/pages/stockHubTiktokVariantState.js'

const source = '10'
const item = { item_id: source, destination_variant_coverage: { 'tiktok-agnishopbjm': {
  state: 'missing_variants', target_product_id: '900', missing_count: 2, missing_model_ids: ['102', '103']
} } }
const response = data => ({ data: { data } })
const running = ['preparing', 'scanning', 'uploading', 'submitting', 'verifying']
const run = (status = 'preparing', extra = {}) => ({ run_id: 'run-1', source_product_id: source,
  remote_product_id: '900', status, can_continue: running.includes(status), can_retry: ['blocked', 'rejected', 'success', 'partial', 'exists'].includes(status),
  progress: { scanned_products: 0, uploaded_images: 0, total_images: 2 }, required_fields: [], context: {}, ...extra })
const crypto = { getRandomValues(bytes) { bytes.fill(7); return bytes } }
const controller = (api, options = {}) => createTiktokVariantController(api, { crypto, ...options })

test('addition is limited to unambiguous missing variants of a source Agni product', () => {
  const base = { unified: true, accountKey: 'shopee-agnishopbjm', item }
  assert.equal(canAddTiktokVariants(base), true)
  for (const override of [{ unified: false }, { accountKey: 'shopee-gitacollectionbjm' }, { busy: true }, { remembered: true },
    { item: { item_id: '10' } }, { run: run('submitted_unverified') }, { run: run('under_review') }, { run: run('blocked') }]) {
    assert.equal(canAddTiktokVariants({ ...base, ...override }), false)
  }
  assert.equal(canAddTiktokVariants({ ...base, run: run('success'), remembered: true }), true)
  assert.equal(canAddTiktokVariants({ ...base, run: run('success', { can_retry: false }) }), false)
  for (const state of ['unknown', 'complete', 'missing_product']) {
    const altered = { ...item, destination_variant_coverage: { 'tiktok-agnishopbjm': { ...item.destination_variant_coverage['tiktok-agnishopbjm'], state } } }
    assert.equal(canAddTiktokVariants({ ...base, item: altered }), false)
  }
})

test('one click starts one run and follows server delays for all missing variants', async () => {
  const calls = [], delays = []; let steps = 0
  const c = controller({
    tiktokVariantAdditionSource: async id => { calls.push(['GET', id]); return response(null) },
    startTiktokVariantAddition: async payload => { calls.push(['start', payload]); return response(run('scanning', { next_step_after_ms: 5000 })) },
    stepTiktokVariantAddition: async id => { calls.push(['step', id]); return response(++steps === 1 ? run('uploading', { next_step_after_ms: 1200 }) : run('success')) }
  }, { sleep: async ms => delays.push(ms) })
  await c.start(source)
  assert.deepEqual(calls.map(call => call[0]), ['GET', 'start', 'step', 'step'])
  assert.equal(calls[1][1].source_product_id, '10')
  assert.deepEqual(calls.slice(2), [['step', 'run-1'], ['step', 'run-1']])
  assert.deepEqual(delays, [5000, 1200])
})

test('a later deliberate addition may start after a resolved previous batch', async () => {
  let starts = 0, gets = 0
  const c = controller({
    tiktokVariantAdditionSource: async () => { gets++; return response(run('success')) },
    startTiktokVariantAddition: async () => { starts++; return response(run('exists', { run_id: 'run-2' })) }
  })
  await c.start(source)
  assert.equal(gets, 1)
  assert.equal(starts, 1)
  assert.equal(c.state.run.run_id, 'run-2')
})

test('read-only restore and unresolved batches never start a second edit', async () => {
  const values = new Map([['stock-hub-tiktok-variants:identifiers', JSON.stringify({ 10: { run_id: 'run-1' } })]])
  const writes = [], remembered = []
  const c = controller({
    tiktokVariantAdditionSource: async () => response(run('under_review')),
    startTiktokVariantAddition: async () => writes.push('start'),
    stepTiktokVariantAddition: async id => { writes.push(['check', id]); return response(run('success')) }
  }, { storage: { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) }, onRemember: id => remembered.push(id) })
  await c.restore()
  await c.start(source)
  assert.deepEqual(remembered, ['10'])
  assert.deepEqual(writes, [])
  await c.checkStatus()
  assert.deepEqual(writes, [['check', 'run-1']])
})

test('lost step response recovers by GET and waits for explicit continuation', async () => {
  let gets = 0; const calls = []
  const c = controller({
    tiktokVariantAdditionSource: async () => { calls.push('GET'); return response(++gets === 1 ? null : run('submitted_unverified')) },
    startTiktokVariantAddition: async () => { calls.push('start'); return response(run('submitting')) },
    stepTiktokVariantAddition: async () => { calls.push('step'); throw Error('lost response') }
  })
  await c.start(source)
  assert.deepEqual(calls, ['GET', 'start', 'step', 'GET'])
  assert.equal(c.state.paused, true)
  assert.equal(c.state.run.status, 'submitted_unverified')
  assert.equal(canAddTiktokVariants({ unified: true, accountKey: 'shopee-agnishopbjm', item, run: c.state.run }), false)
})

test('manual price completion requires a positive whole IDR price', () => {
  assert.deepEqual(variantPriceContext('27500'), { manual_price: 27500 })
  for (const invalid of ['', '0', '-1', '2.5', 'NaN', 'Infinity', '25000abc']) assert.throws(() => variantPriceContext(invalid))
})

test('pending review is distinguished from a verified live batch in row feedback', () => {
  assert.equal(variantRowResult(run('under_review')).pending, true)
  assert.equal(variantRowResult(run('submitted_unverified')).accepted, false)
  assert.equal(variantRowResult(run('success')).accepted, true)
})

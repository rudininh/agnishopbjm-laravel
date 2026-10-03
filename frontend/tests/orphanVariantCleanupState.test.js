import test from 'node:test'
import assert from 'node:assert/strict'
import { supportsCleanup, selectionFor, canSubmit, runCleanupSteps } from '../src/pages/orphanVariantCleanupState.js'

const run = { run_id: 'run', account_key: 'tiktok-agnishopbjm', revision: 'r', status: 'ready', items: [
  { item_id: 'a', status: 'eligible' }, { item_id: 'b', status: 'blocked' }, { item_id: 'c', status: 'eligible' }
] }

test('only downstream accounts offer cleanup', () => {
  assert.equal(supportsCleanup('shopee-agnishopbjm'), false)
  assert.equal(supportsCleanup('shopee-gitacollectionbjm'), true)
  assert.equal(supportsCleanup('tiktok-agnishopbjm'), true)
})
test('bulk and single deletion exclude blocked rows and unfinished previews', () => {
  assert.deepEqual(selectionFor(run), ['a', 'c'])
  assert.deepEqual(selectionFor(run, 'c'), ['c'])
  assert.deepEqual(selectionFor(run, 'b'), [])
  assert.deepEqual(selectionFor({ ...run, status: 'scanning' }), [])
  assert.equal(canSubmit(run, ['a'], false), true)
  for (const ids of [[], ['b'], ['a', 'a'], ['foreign']]) assert.equal(canSubmit(run, ids, false), false)
  assert.equal(canSubmit(run, ['a'], true), false)
  assert.equal(canSubmit({ ...run, status: 'completed' }, ['a'], false), false)
})
test('driver scans and steps only the bound account and never submits automatically', async () => {
  const calls = []
  const api = { orphanVariantScan: async (id, account) => {
    calls.push([id, account]); return { data: { ...run, status: 'ready' } }
  } }
  const result = await runCleanupSteps(api, { ...run, status: 'scanning' }, () => {}, () => true)
  assert.equal(result.status, 'ready')
  assert.deepEqual(calls, [['run', 'tiktok-agnishopbjm']])
})
test('switching account drops stale responses and stops subsequent calls', async () => {
  let current = true
  const updates = []
  const api = { orphanVariantStep: async () => { current = false; return { data: { ...run, status: 'running' } } } }
  await runCleanupSteps(api, { ...run, status: 'running' }, r => updates.push(r), () => current)
  assert.deepEqual(updates, [])
})
test('uncertain network outcome throws without replaying a mutation', async () => {
  let calls = 0
  await assert.rejects(runCleanupSteps({ orphanVariantStep: async () => { calls++; throw Error('network') } },
    { ...run, status: 'running' }, () => {}, () => true), /network/)
  assert.equal(calls, 1)
})

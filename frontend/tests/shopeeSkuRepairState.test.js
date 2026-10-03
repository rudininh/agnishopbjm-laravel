import test from 'node:test'
import assert from 'node:assert/strict'
import { shopeeTemplateSku, shopeeSkuRepairRows, shopeeAllSkuRepairRows } from '../src/pages/shopeeSkuRepairState.js'

test('all-product repair keeps each item identity and excludes correct SKUs across the whole catalog', () => {
  const rows = shopeeAllSkuRepairRows([
    { item_id: '42', nama: 'Product A', models: [{ model_id: '1', name: 'Americano', model_sku: 'OLD' }] },
    { item_id: '43', nama: 'Product B', models: [
      { model_id: '1', name: 'Americano', model_sku: 'OLD' },
      { model_id: '2', name: 'Biru', model_sku: 'INT-43-BIRU' }
    ] }
  ])
  assert.deepEqual(rows.map(row => [row.itemId, row.modelId, row.target]), [
    ['42', '1', 'INT-42-AMERICANO'], ['43', '1', 'INT-43-AMERICANO']
  ])
  assert.ok(rows.every(row => !row.blocked))
})

test('Americano template comes from variant name even when an old INT SKU is present', () => {
  assert.equal(shopeeTemplateSku({ item_id: '54256579274' }, {
    name: 'Americano', model_sku: 'INT-54256579274-PUTIH', kode_variasi: 'INT-54256579274-PUTIH'
  }), 'INT-54256579274-AMERICANO')
})

test('repair includes wrong and empty SKUs, excluding already correct rows', () => {
  const rows = shopeeSkuRepairRows({ item_id: '42', models: [
    { model_id: '1', name: 'Americano', model_sku: 'INT-42-PUTIH' },
    { model_id: '2', name: 'Biru', model_sku: '' },
    { model_id: '3', name: 'Merah', model_sku: 'INT-42-MERAH' }
  ] })
  assert.deepEqual(rows.map(row => row.target), ['INT-42-AMERICANO', 'INT-42-BIRU'])
  assert.ok(rows.every(row => !row.blocked))
})

test('all normalized duplicate targets are blocked, including collision with correct sibling', () => {
  const rows = shopeeSkuRepairRows({ item_id: '42', models: [
    { model_id: '1', name: 'Soft Pink', model_sku: 'OLD' },
    { model_id: '2', name: 'Soft-Pink', model_sku: 'INT-42-SOFT-PINK' }
  ] })
  assert.equal(rows.length, 1)
  assert.match(rows[0].blocked, /duplikat/)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { tiktokTemplateSku, tiktokSkuRepairRows } from '../src/pages/tiktokSkuRepairState.js'

test('TikTok template uses server recommendation instead of stale real SKU', () => {
  assert.equal(tiktokTemplateSku({}, { seller_sku: 'INT-42-PUTIH', kode_variasi: 'INT-42-AMERICANO' }), 'INT-42-AMERICANO')
})

test('all-product repair keeps product and SKU identity and only includes anomalies', () => {
  const products = [
    { product_id: '10', skus: [{ sku_id: '1', seller_sku: 'OLD', kode_variasi: 'INT-42-MERAH' }] },
    { product_id: '20', skus: [
      { sku_id: '1', seller_sku: '', kode_variasi: 'INT-43-BIRU' },
      { sku_id: '2', seller_sku: 'INT-43-HITAM', kode_variasi: 'INT-43-HITAM' }
    ] }
  ]
  assert.deepEqual(tiktokSkuRepairRows(products).map(row => [row.productId, row.skuId]), [['10', '1'], ['20', '1']])
  assert.deepEqual(tiktokSkuRepairRows(products, true).map(row => row.productId), ['20'])
})

test('missing metadata and ambiguous source stay blocked in preview', () => {
  const rows = tiktokSkuRepairRows([{ product_id: '10', skus: [
    { sku_id: '1', seller_sku: 'OLD' },
    { sku_id: '2', seller_sku: 'OLD', kode_variasi: 'INT-42-MERAH', sku_repair_blocked: 'Prefix berbeda' }
  ] }])
  assert.ok(rows.every(row => row.blocked))
})

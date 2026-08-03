import test from 'node:test'
import assert from 'node:assert/strict'
import {
  firstReconciliationAnomalyKey,
  reconciliationProductOptionLabel,
  sortReconciliationProducts
} from '../src/pages/reconciliationProductState.js'

test('prioritizes detected anomaly products and selects the first one', () => {
  const products = sortReconciliationProducts([
    { shopee_item_id: '100', tiktok_product_id: '900', product_name: 'Produk Terhubung' },
    { shopee_item_id: '200', tiktok_product_id: '800', product_name: 'Produk Anomali B', anomaly_candidate: true, detected_variant_count: 2 },
    { shopee_item_id: '300', tiktok_product_id: '700', product_name: 'Produk Anomali A', anomaly_candidate: true, detected_variant_count: 4 }
  ])

  assert.deepEqual(products.map(item => item.shopee_item_id), ['300', '200', '100'])
  assert.equal(firstReconciliationAnomalyKey(products), '300:700')
})

test('labels a detected anomaly with its variant count', () => {
  assert.equal(
    reconciliationProductOptionLabel({
      product_name: 'Produk Anomali',
      shopee_item_id: '200',
      tiktok_product_id: '800',
      anomaly_candidate: true,
      detected_variant_count: 4
    }),
    'Produk Anomali | Shopee 200 | TikTok 800 | Anomali terdeteksi: 4 varian'
  )
})

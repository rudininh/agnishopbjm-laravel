import test from 'node:test'
import assert from 'node:assert/strict'
import { batchProgressText, elapsedText } from '../src/pages/stockHubTiktokVariantBatchProgress.js'

test('unknown catalog totals remain unknown and display changes by actual stage', () => {
  assert.equal(batchProgressText({ status: 'scanning', progress: { scanned_products: 7, total_products: null } }), '7 produk TikTok diperiksa · total belum diketahui')
  assert.equal(batchProgressText({ status: 'uploading', progress: { uploaded_images: 2, total_images: 8 } }), '2/8 gambar diproses')
  assert.equal(batchProgressText({ status: 'submitting', progress: { scanned_products: 68, total_products: 68 } }), '')
  assert.equal(batchProgressText({ workflow_stage: 'source_sku', progress: { verified_skus: 2, total_skus: 4 } }), '2/4 perubahan SKU Agni terverifikasi')
  assert.equal(batchProgressText({ status: 'scanning', progress: { scanned_products: -1 } }), '')
})

test('elapsed request time handles minute boundaries and backward clock adjustment', () => {
  assert.equal(elapsedText(1000, 62000), '1 menit 1 detik')
  assert.equal(elapsedText(1000, 0), '0 detik')
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { buildBulkSubmitFeedback, mergeBulkPreviewState } from '../src/pages/bulkTiktokSubmitState.js'

test('keeps submit feedback while candidate preview refreshes', () => {
  const next = mergeBulkPreviewState(
    {
      message: 'Berhasil 1 | Belum terverifikasi 1 | Gagal 0 | Dilewati 0',
      messageTone: 'warning',
      selectedProductIds: ['1']
    },
    { items: [{ tiktok_product_id: '1' }], mapping_only_items: [] },
    { preserveFeedback: true }
  )

  assert.equal(next.message, 'Berhasil 1 | Belum terverifikasi 1 | Gagal 0 | Dilewati 0')
  assert.equal(next.messageTone, 'warning')
  assert.deepEqual(next.selectedProductIds, ['1'])
})

test('includes unverified count in submit feedback', () => {
  assert.equal(
    buildBulkSubmitFeedback({ updated: 1, unverified: 2, failed: 3, skipped: 4 }),
    'Proses tambah varian TikTok selesai. Berhasil 1 | Belum terverifikasi 2 | Gagal 3 | Dilewati 4'
  )
})

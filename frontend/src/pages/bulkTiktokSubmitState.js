export const buildBulkSubmitFeedback = ({ updated = 0, unverified = 0, failed = 0, skipped = 0 } = {}) =>
  `Proses tambah varian TikTok selesai. Berhasil ${Number(updated)} | Belum terverifikasi ${Number(unverified)} | Gagal ${Number(failed)} | Dilewati ${Number(skipped)}`

export const mergeBulkPreviewState = (current, payload, { preserveFeedback = false } = {}) => ({
  candidates: payload.items || [],
  mappingOnlyCandidates: payload.mapping_only_items || [],
  selectedProductIds: (current.selectedProductIds || []).filter((id) => (payload.items || []).some((group) => group.tiktok_product_id === id)),
  message: preserveFeedback ? current.message : '',
  messageTone: preserveFeedback ? current.messageTone : 'info'
})

import { createProductCreationController } from './stockHubProductCreationController.js'

const completed = ['success', 'partial', 'exists']
const labels = {
  preparing: 'Menyiapkan varian TikTok', scanning: 'Memeriksa SKU katalog TikTok', uploading: 'Mengunggah gambar varian',
  submitting: 'Mengirim semua varian baru', verifying: 'Memverifikasi varian TikTok',
  blocked: 'Perlu dilengkapi atau diperbaiki', rejected: 'Ditolak TikTok', exists: 'Semua varian sudah ada',
  success: 'Varian TikTok terverifikasi', partial: 'Sebagian varian terverifikasi',
  under_review: 'Varian menunggu peninjauan TikTok', submitted_unverified: 'Terkirim, belum terverifikasi'
}

export function canAddTiktokVariants({ unified, accountKey, item, busy = false, run, remembered = false }) {
  const coverage = item?.destination_variant_coverage?.['tiktok-agnishopbjm']
  return Boolean(unified && accountKey === 'shopee-agnishopbjm' && !busy && /^\d+$/.test(String(item?.item_id || ''))
    && coverage?.state === 'missing_variants' && coverage.missing_count > 0 && /^\d+$/.test(String(coverage.target_product_id || ''))
    && (run ? completed.includes(run.status) && run.can_retry : !remembered))
}

export function variantRowResult(run) {
  return run ? { label: labels[run.status] || 'Periksa status varian TikTok',
    accepted: completed.includes(run.status), pending: run.status === 'under_review', result: run.result } : null
}

export function variantPriceContext(value) {
  const text = String(value ?? '').trim()
  if (!/^[0-9]+$/.test(text) || !Number.isSafeInteger(Number(text)) || Number(text) <= 0) {
    throw Error('Isi harga varian baru dalam rupiah, berupa bilangan bulat lebih dari nol.')
  }
  return { manual_price: Number(text) }
}

export function createTiktokVariantController(api, options = {}) {
  return createProductCreationController({
    start: api.startTiktokVariantAddition?.bind(api), source: api.tiktokVariantAdditionSource?.bind(api), step: api.stepTiktokVariantAddition?.bind(api)
  }, {
    runningStatuses: ['preparing', 'scanning', 'uploading', 'submitting', 'verifying'],
    checkableStatuses: ['submitted_unverified', 'under_review'],
    newRunStatuses: completed, retryStatuses: ['blocked', 'rejected', ...completed],
    storageKey: 'stock-hub-tiktok-variants:identifiers'
  }, options)
}

import { createProductCreationController } from './stockHubProductCreationController.js'

export const tiktokVariantRepairVerified = run => ['success', 'exists'].includes(run?.status)

export function tiktokVariantRepairLabel(run) {
  return ({ preparing: 'Menyiapkan perbaikan mengikuti Agni', scanning: 'Memeriksa SKU katalog TikTok',
    uploading: 'Mengunggah gambar perbaikan', submitting: 'Mengirim perbaikan TikTok', verifying: 'Memverifikasi perbaikan TikTok',
    success: 'Perbaikan TikTok terverifikasi', exists: 'Identitas TikTok sudah mengikuti Agni',
    blocked: 'Perbaikan diblokir', rejected: 'Perbaikan ditolak TikTok', under_review: 'Perbaikan menunggu peninjauan TikTok',
    submitted_unverified: 'Perbaikan terkirim, belum terverifikasi' })[run?.status] || 'Periksa status perbaikan TikTok'
}

export function createTiktokVariantRepairController(api, options = {}) {
  return createProductCreationController({ start: api.startTiktokVariantRepair?.bind(api),
    source: api.tiktokVariantRepairSource?.bind(api), step: api.stepTiktokVariantRepair?.bind(api) }, {
    runningStatuses: ['preparing', 'scanning', 'uploading', 'submitting', 'verifying'],
    checkableStatuses: ['submitted_unverified', 'under_review'],
    newRunStatuses: ['success', 'exists'], retryStatuses: ['blocked', 'rejected', 'success', 'exists'],
    prerequisiteMessages: {
      agni_sku_pending: 'Normalisasi SKU Agni masih berjalan atau belum terverifikasi. Gunakan Periksa dan Lanjutkan SKU Agni untuk memeriksa kiriman lama dan melanjutkan mengikuti nama Agni terbaru.'
    },
    storageKey: 'stock-hub-tiktok-variant-repairs:identifiers'
  }, options)
}

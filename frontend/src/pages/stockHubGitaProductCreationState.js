import { createProductCreationController } from './stockHubProductCreationController.js'

const running = ['preparing', 'scanning', 'uploading', 'submitting', 'initializing_variants', 'verifying', 'publishing', 'verifying_publication']
const labels = {
  preparing: 'Menyiapkan produk', scanning: 'Memeriksa katalog Gitashop', uploading: 'Mengunggah gambar', submitting: 'Mengirim produk',
  initializing_variants: 'Menyiapkan varian Gitashop', verifying: 'Memverifikasi produk', publishing: 'Mempublikasikan produk', verifying_publication: 'Memverifikasi publikasi',
  blocked: 'Perlu dilengkapi atau diperbaiki', rejected: 'Ditolak Gitashop', exists: 'Sudah ditemukan di Gitashop', success: 'Terverifikasi di Gitashop',
  under_review: 'Menunggu peninjauan Gitashop', submitted_unverified: 'Terkirim, belum terverifikasi', partial_unverified: 'Produk Gitashop belum terverifikasi lengkap'
}
const dimensionKeys = ['package_length', 'package_width', 'package_height']
export function canCreateGitaProduct({ unified, accountKey, item, busy = false, run, remembered = false }) {
  return Boolean(unified && accountKey === 'shopee-agnishopbjm' && !busy && /^\d+$/.test(String(item?.item_id || ''))
    && item?.destination_presence?.['shopee-gitacollectionbjm'] === 'missing' && !run && !remembered)
}
export function creationRowResult(run) {
  return run ? { label: labels[run.status] || 'Periksa status produk', result: run.result,
    accepted: run.status === 'exists' || (run.status === 'success' && run.result?.published === true) } : null
}
export function creationFieldValues(context = {}) {
  const positive = value => Number.isFinite(Number(value)) && Number(value) > 0 ? String(value) : ''
  return { category_id: String(context.category_id || ''), weight: positive(context.weight),
    ...Object.fromEntries(dimensionKeys.map(key => [`dimension.${key}`, positive(context.dimension?.[key])])),
    logistic_ids: [...(context.logistic_ids || [])].map(String), location_id: String(context.location_id || ''), size_chart_image_url: String(context.size_chart_image_url || '') }
}
export function creationContext(context = {}, requiredFields = [], values = {}) {
  const result = { ...context, ...(context.dimension ? { dimension: { ...context.dimension } } : {}), ...(context.logistic_ids ? { logistic_ids: [...context.logistic_ids] } : {}) }
  const invalid = field => { throw Error(`Lengkapi ${field.label || field.key} dengan nilai yang valid.`) }
  for (const field of requiredFields) {
    if (field.key === 'dimension') {
      const dimension = {}
      for (const key of dimensionKeys) {
        const value = Number(values[`dimension.${key}`])
        if (!Number.isSafeInteger(value) || value <= 0) invalid(field)
        dimension[key] = value
      }
      result.dimension = dimension; continue
    }
    if (field.type === 'multiselect') {
      const value = values[field.key]
      if (!Array.isArray(value) || !value.length) invalid(field)
      const selected = [...new Set(value.map(String))]
      if (selected.some(id => !/^\d+$/.test(id) || !field.options?.some(option => String(option.id) === id))) invalid(field)
      result[field.key] = selected; continue
    }
    const value = String(values[field.key] ?? '').trim()
    if (!value) invalid(field)
    if (field.type === 'number' && (!Number.isFinite(Number(value)) || Number(value) <= 0)) invalid(field)
    if (field.type === 'category' && !/^\d+$/.test(value)) invalid(field)
    if (field.type === 'select' && !field.options?.some(option => String(option.id) === value)) invalid(field)
    if (field.type === 'url') {
      try { const url = new URL(value); if (url.protocol !== 'https:' || url.username || url.password) invalid(field) } catch { invalid(field) }
    }
    result[field.key] = field.type === 'number' ? Number(value) : value
  }
  return result
}

export function creationLeafCategories(nodes = [], search = '') {
  const byId = new Map(nodes.map(node => [String(node.id), node]))
  const query = search.trim().toLocaleLowerCase('id')
  return nodes.filter(node => node.is_leaf === true).map(node => {
    const names = [node.name], seen = new Set([String(node.id)])
    let parent = byId.get(String(node.parent_id))
    while (parent && !seen.has(String(parent.id))) {
      seen.add(String(parent.id)); names.unshift(parent.name); parent = byId.get(String(parent.parent_id))
    }
    return { id: String(node.id), name: names.join(' / ') }
  }).filter(node => node.name.toLocaleLowerCase('id').includes(query))
}


export function createGitaProductController(api, options = {}) {
  return createProductCreationController({ start: api.startGitaProductCreation?.bind(api), source: api.gitaProductCreationSource?.bind(api), step: api.stepGitaProductCreation?.bind(api) },
    { runningStatuses: running, storageKey: 'stock-hub-gita-creation:identifiers', checkableStatuses: ['submitted_unverified', 'partial_unverified', 'under_review'] }, options)
}

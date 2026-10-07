import { createProductCreationController } from './stockHubProductCreationController.js'

const running = new Set(['preparing', 'scanning', 'uploading', 'submitting', 'verifying'])
const labels = {
  preparing: 'Menyiapkan produk', scanning: 'Memeriksa katalog TikTok', uploading: 'Mengunggah gambar',
  submitting: 'Mengirim produk', verifying: 'Memverifikasi produk', blocked: 'Perlu dilengkapi atau diperbaiki',
  rejected: 'Ditolak TikTok', exists: 'Sudah ditemukan di TikTok', success: 'Terverifikasi di TikTok',
  under_review: 'Menunggu peninjauan TikTok', submitted_unverified: 'Terkirim, belum terverifikasi'
}
const storageKey = 'stock-hub-tiktok-creation:identifiers'

export function canCreateTiktokProduct({ unified, accountKey, item, busy = false, run, remembered = false }) {
  return Boolean(unified && accountKey === 'shopee-agnishopbjm' && !busy && /^\d+$/.test(String(item?.item_id || ''))
    && item?.destination_presence?.['tiktok-agnishopbjm'] === 'missing' && !run && !remembered)
}

export function creationRowResult(run) {
  return run ? { label: labels[run.status] || 'Periksa status produk', result: run.result,
    accepted: ['success', 'under_review', 'exists'].includes(run.status) } : null
}

export function creationFieldValues(context = {}) {
  const positive = value => Number(value) > 0 && Number.isFinite(Number(value)) ? String(value) : ''
  return { category_id: String(context.category_id || ''), warehouse_id: String(context.warehouse_id || ''),
    'package_weight.value': positive(context.package_weight?.value),
    'package_dimensions.length': positive(context.package_dimensions?.length),
    'package_dimensions.width': positive(context.package_dimensions?.width),
    'package_dimensions.height': positive(context.package_dimensions?.height) }
}

export function creationContext(context, requiredFields, values) {
  const fields = creationFieldValues(context)
  for (const field of requiredFields) {
    const value = String(values[field.key] ?? '').trim()
    if (!value || (field.type === 'number' && (!Number.isFinite(Number(value)) || Number(value) <= 0))) {
      throw Error(`Lengkapi ${field.label || field.key} dengan nilai yang valid.`)
    }
    fields[field.key] = value
  }
  const result = {}
  for (const key of ['category_id', 'warehouse_id']) if (fields[key]) result[key] = fields[key]
  if (fields['package_weight.value']) result.package_weight = { value: fields['package_weight.value'], unit: 'KILOGRAM' }
  const dimensions = ['length', 'width', 'height']
  if (dimensions.every(key => fields[`package_dimensions.${key}`])) {
    result.package_dimensions = Object.fromEntries(dimensions.map(key => [key, fields[`package_dimensions.${key}`]]))
    result.package_dimensions.unit = 'CENTIMETER'
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

export function createTiktokProductController(api, options = {}) {
  return createProductCreationController({ start: api.startTiktokProductCreation?.bind(api), source: api.tiktokProductCreationSource?.bind(api), step: api.stepTiktokProductCreation?.bind(api) },
    { runningStatuses: running, storageKey }, options)
}

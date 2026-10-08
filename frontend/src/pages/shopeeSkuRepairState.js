export function shopeeTemplateSku(item, model, accountKey = 'shopee-agnishopbjm') {
  if (accountKey === 'shopee-gitacollectionbjm') return model?.sku_repair_blocked ? '' : String(model?.kode_variasi || '')
  const itemId = String(item?.item_id || '').trim()
  if (!itemId) return ''
  const fragment = String(model?.name ?? '').trim().toUpperCase()
    .replace(/[^A-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30) || 'X'
  return `INT-${itemId}-${fragment}`
}

export function shopeeSkuRepairRows(item, accountKey = 'shopee-agnishopbjm') {
  const rows = (item?.models || []).map(model => ({
    itemId: String(item?.item_id ?? ''),
    productName: String(item?.nama ?? ''),
    modelId: String(model.model_id ?? ''),
    name: String(model.name ?? ''),
    current: String(model.model_sku ?? ''),
    target: shopeeTemplateSku(item, model, accountKey),
    sourceBlocked: accountKey === 'shopee-gitacollectionbjm' ? model.sku_repair_blocked || (!model.kode_variasi ? 'Hubungan varian Agni belum jelas. Periksa mapping sumber Agni.' : '') : ''
  }))
  return rows.filter(row => row.current !== row.target || row.sourceBlocked).map(row => ({
    ...row,
    blocked: row.sourceBlocked || (!row.modelId || !row.name.trim() || row.target.length > 100
      ? 'Data varian tidak lengkap atau SKU terlalu panjang.'
      : rows.filter(other => other.target === row.target).length > 1
        ? 'Nama varian menghasilkan SKU duplikat. Periksa nama varian dahulu.' : ''),
    status: ''
  }))
}

export function shopeeAllSkuRepairRows(items, accountKey = 'shopee-agnishopbjm') {
  return (items || []).flatMap(item => shopeeSkuRepairRows(item, accountKey))
}

export function shopeeTemplateSku(item, model) {
  const itemId = String(item?.item_id || '').trim()
  if (!itemId) return ''
  const fragment = String(model?.name ?? '').trim().toUpperCase()
    .replace(/[^A-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30) || 'X'
  return `INT-${itemId}-${fragment}`
}

export function shopeeSkuRepairRows(item) {
  const rows = (item?.models || []).map(model => ({
    itemId: String(item?.item_id ?? ''),
    productName: String(item?.nama ?? ''),
    modelId: String(model.model_id ?? ''),
    name: String(model.name ?? ''),
    current: String(model.model_sku ?? ''),
    target: shopeeTemplateSku(item, model)
  }))
  return rows.filter(row => row.current !== row.target).map(row => ({
    ...row,
    blocked: !row.modelId || !row.name.trim() || row.target.length > 100
      ? 'Data varian tidak lengkap atau SKU terlalu panjang.'
      : rows.filter(other => other.target === row.target).length > 1
        ? 'Nama varian menghasilkan SKU duplikat. Periksa nama varian dahulu.' : '',
    status: ''
  }))
}

export function shopeeAllSkuRepairRows(items) {
  return (items || []).flatMap(shopeeSkuRepairRows)
}

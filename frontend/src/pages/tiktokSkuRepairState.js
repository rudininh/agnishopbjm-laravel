export const tiktokTemplateSku = (item, sku) => sku?.sku_repair_blocked ? '' : String(sku?.kode_variasi || '')

export function tiktokSkuRepairRows(items, emptyOnly = false) {
  return (items || []).flatMap(item => (item.skus || []).map(sku => ({
    productId: String(item.product_id), productName: item.product_name,
    skuId: String(sku.sku_id || ''), name: String(sku.sku_name || ''),
    current: String(sku.seller_sku || ''), target: tiktokTemplateSku(item, sku),
    blocked: sku.sku_repair_blocked || (!sku.kode_variasi || !sku.sku_id ? 'Data template / SKU ID belum tersedia.' : ''),
    status: ''
  })).filter(row => (!emptyOnly || !row.current.trim() || row.current.trim() === '-')
    && (row.current !== row.target || row.blocked)))
}

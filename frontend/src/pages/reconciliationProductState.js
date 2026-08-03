export const reconciliationProductKey = item => `${item.shopee_item_id}:${item.tiktok_product_id}`

export const sortReconciliationProducts = items => [...(items || [])].sort((left, right) => {
  const candidateComparison = Number(Boolean(right.anomaly_candidate)) - Number(Boolean(left.anomaly_candidate))
  if (candidateComparison !== 0) return candidateComparison

  return [left.product_name || '', left.shopee_item_id || '', left.tiktok_product_id || '']
    .join('|')
    .localeCompare([right.product_name || '', right.shopee_item_id || '', right.tiktok_product_id || ''].join('|'))
})

export const firstReconciliationAnomalyKey = items => {
  const candidate = (items || []).find(item => item.anomaly_candidate)
  return candidate ? reconciliationProductKey(candidate) : ''
}

export const reconciliationProductOptionLabel = item => {
  const label = `${item.product_name || '-'} | Shopee ${item.shopee_item_id} | TikTok ${item.tiktok_product_id}`
  return item.anomaly_candidate
    ? `${label} | Anomali terdeteksi: ${Math.max(0, Number(item.detected_variant_count) || 0)} varian`
    : label
}

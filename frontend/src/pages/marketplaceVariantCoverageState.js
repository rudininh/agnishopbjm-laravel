const destinations = [
  ['tiktok-agnishopbjm', 'TikTok'],
  ['shopee-gitacollectionbjm', 'Gitashop']
]

const coverage = (item, account) => item?.destination_variant_coverage?.[account]

export function matchesVariantCoverageFilter(item, account) {
  if (!account || account === 'all') return true
  const state = coverage(item, account)
  return state?.state === 'missing_variants' && Boolean(state.target_product_id) && Number(state.missing_count) > 0
}

export function variantCoverageInfo(item) {
  return destinations.flatMap(([account, name]) => {
    const state = coverage(item, account)
    if (!state) return []
    const missing = matchesVariantCoverageFilter(item, account)
    const label = missing ? `kurang ${state.missing_count} varian`
      : state.state === 'complete' ? 'varian lengkap'
        : state.state === 'missing_product' ? 'produk belum ditemukan' : 'Perlu diperiksa'
    return [{ account, missing, label: `${name}: ${label}` }]
  })
}

export function missingVariantEligible(item, model, account) {
  return matchesVariantCoverageFilter(item, account) && Array.isArray(coverage(item, account)?.missing_model_ids) &&
    coverage(item, account).missing_model_ids.some(id => String(id) === String(model?.model_id))
}

export function modelVariantCoverageInfo(item, model) {
  return destinations.filter(([account]) => missingVariantEligible(item, model, account))
    .map(([account, name]) => ({ account, label: `${name}: varian belum ditemukan` }))
}

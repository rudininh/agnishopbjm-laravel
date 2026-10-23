const count = value => Number.isSafeInteger(value) && value >= 0 ? value : null

export function batchProgressText(item) {
  const p = item?.progress
  if (!p) return ''
  if (['source_sku', 'recover_source_sku'].includes(item.workflow_stage) && count(p.total_skus) !== null) {
    return `${count(p.verified_skus) ?? 0}/${p.total_skus} perubahan SKU Agni terverifikasi`
  }
  if (item.status === 'scanning' && count(p.scanned_products) !== null) {
    const total = count(p.total_products)
    return `${p.scanned_products}${total !== null ? `/${total}` : ''} produk TikTok diperiksa${total === null ? ' · total belum diketahui' : ''}`
  }
  if (item.status === 'uploading' && count(p.total_images) !== null) return `${count(p.uploaded_images) ?? 0}/${p.total_images} gambar diproses`
  return ''
}

export function elapsedText(since, now) {
  const seconds = Math.max(0, Math.floor((now - since) / 1000))
  return seconds < 60 ? `${seconds} detik` : `${Math.floor(seconds / 60)} menit ${seconds % 60} detik`
}

export function batchActivityText(run) {
  const item = run.items.find(item => item.source_product_id === run.current_source_product_id)
  const detail = batchProgressText(item)
  return item ? `${item.name} · ${item.message}${detail ? ` · ${detail}` : ''}` : run.message
}

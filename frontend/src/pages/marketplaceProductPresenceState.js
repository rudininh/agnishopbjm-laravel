const destinations = [
  ['tiktok-agnishopbjm', 'TikTok Agni'],
  ['shopee-gitacollectionbjm', 'Gitashop']
]

export function productPresenceInfo(item) {
  const states = item?.destination_presence || {}
  const missing = destinations.filter(([key]) => states[key] === 'missing').map(([, name]) => name)
  const unknown = destinations.filter(([key]) => states[key] === 'unknown').map(([, name]) => name)
  return {
    missingCount: missing.length,
    message: [
      missing.length ? `Belum ditemukan: ${missing.join(', ')}` : '',
      unknown.length ? `Belum dapat diperiksa: ${unknown.join(', ')}` : ''
    ].filter(Boolean).join(' · ')
  }
}

export function compareProductPresence(a, b) {
  return productPresenceInfo(b).missingCount - productPresenceInfo(a).missingCount
}

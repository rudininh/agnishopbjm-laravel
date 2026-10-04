import test from 'node:test'
import assert from 'node:assert/strict'
import * as presence from '../src/pages/marketplaceProductPresenceState.js'

const missing = { destination_presence: { 'tiktok-agnishopbjm': 'missing', 'shopee-gitacollectionbjm': 'missing' } }
const partial = { destination_presence: { 'tiktok-agnishopbjm': 'present', 'shopee-gitacollectionbjm': 'missing' } }

test('availability labels identify missing stores and distinguish unavailable data', () => {
  assert.equal(presence.productPresenceInfo(missing).message, 'Belum ditemukan: TikTok Agni, Gitashop')
  assert.equal(presence.productPresenceInfo(partial).message, 'Belum ditemukan: Gitashop')
  assert.equal(presence.productPresenceInfo({ destination_presence: { 'tiktok-agnishopbjm': 'unknown' } }).message, 'Belum dapat diperiksa: TikTok Agni')
  assert.equal(presence.productPresenceInfo({}).message, '')
  assert.equal(presence.productPresenceInfo({ destination_presence: { 'tiktok-agnishopbjm': 'present', 'shopee-gitacollectionbjm': 'present' } }).missingCount, 0)
})

test('missing destinations are sorted across the whole catalog before pagination', () => {
  const items = Array.from({ length: 45 }, (_, id) => ({ item_id: String(id), ...(!id ? partial : {}) }))
  items[44] = { item_id: '44', ...missing }
  const sorted = items.slice().sort((a, b) => presence.compareProductPresence(a, b) || Number(a.item_id) - Number(b.item_id))
  assert.equal(sorted.slice(0, 20)[0].item_id, '44')
  assert.equal(sorted[1].item_id, '0')
  assert.equal(sorted[2].item_id, '1')
  assert.equal(items[0].item_id, '0')
  assert.equal(presence.compareProductPresence({}, { destination_presence: { 'tiktok-agnishopbjm': 'unknown' } }), 0)
})

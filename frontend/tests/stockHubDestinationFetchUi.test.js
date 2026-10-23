import test from 'node:test'
import assert from 'node:assert/strict'
import { loadComponent, mount, tick } from './vueRendererSupport.js'

const agni = 'shopee-agnishopbjm'
const tiktok = 'tiktok-agnishopbjm'
const gita = 'shopee-gitacollectionbjm'
const cached = () => ({ data: { items: [] } })
const api = {
  activeTiktokVariantBatch: async () => ({ data: { data: null } }),
  shopeeItems: async () => cached(),
}

test('destination fetch controls appear only in the unified Shopee Agni view', async () => {
  for (const [accountKey, unified, visible] of [[agni, true, true], [gita, true, false], [agni, false, false]]) {
    const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { accountKey, unified })
    try {
      await tick()
      assert.equal(Boolean(view.button('Ambil Produk TikTok')), visible)
      assert.equal(Boolean(view.button('Ambil Produk Gitashop')), visible)
    } finally { view.app.unmount() }
  }
})

test('destination fetch uses the fixed account and reloads Agni cache without fetching Agni remotely', async () => {
  for (const [label, target] of [['TikTok', tiktok], ['Gitashop', gita]]) {
    const calls = [], busy = []; let finish
    const remote = (channel, sync, params) => {
      calls.push([channel, sync, params])
      return new Promise(resolve => { finish = resolve })
    }
    const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', { ...api,
      tiktokItems: (sync, params) => remote('tiktok', sync, params),
      shopeeItems: (sync, params) => sync ? remote('shopee', sync, params) : (calls.push(['shopee', sync, params]), cached()),
    }), { accountKey: agni, unified: true, onBusy: value => busy.push(value) })
    try {
      await tick(); calls.length = 0
      const pending = view.button(`Ambil Produk ${label}`).props.onClick(); await tick()
      assert.deepEqual(calls, [[label === 'TikTok' ? 'tiktok' : 'shopee', true, { account_key: target }]])
      assert.equal(busy.at(-1), true)
      assert.ok(view.button(`Mengambil Produk ${label}...`))
      assert.equal(view.button(`Ambil Produk ${label === 'TikTok' ? 'Gitashop' : 'TikTok'}`).props.disabled, true)
      assert.equal(view.button('Ambil Produk').props.disabled, true)
      finish({ data: { items: [{ item_id: 'target-only' }], sync: { status: 'ok' } } }); await pending; await tick()
      assert.deepEqual(calls.at(-1), ['shopee', false, { account_key: agni }])
      assert.match(view.text(view.root), new RegExp(`Produk ${label} berhasil diambil`))
      assert.doesNotMatch(view.text(view.root), /target-only/)
      assert.equal(busy.at(-1), false)
    } finally { view.app.unmount() }
  }
})

test('failed or partial destination fetch is reported truthfully and releases busy', async () => {
  for (const outcome of ['error', 'partial', 'throw', 'unknown']) {
    const busy = []
    const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', { ...api,
      tiktokItems: async () => { if (outcome === 'throw') throw Error('private transport detail'); return { data: { sync: { status: outcome } } } },
    }), { accountKey: agni, unified: true, onBusy: value => busy.push(value) })
    try {
      await tick(); await view.button('Ambil Produk TikTok').props.onClick(); await tick()
      assert.doesNotMatch(view.text(view.root), /Produk TikTok berhasil diambil|private transport detail/)
      assert.match(view.text(view.root), outcome === 'partial' ? /sebagian/ : /belum berhasil/)
      assert.equal(busy.at(-1), false)
      assert.equal(view.button('Ambil Produk TikTok').props.disabled, false)
    } finally { view.app.unmount() }
  }
})

test('mirror activity blocks destination fetch even if the handler is invoked directly', async () => {
  let calls = 0
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', { ...api, tiktokItems: async () => { calls++ } }), { accountKey: agni, unified: true, mirrorBusy: true })
  try {
    await tick(); assert.equal(view.button('Ambil Produk TikTok').props.disabled, true)
    await view.button('Ambil Produk TikTok').props.onClick(); assert.equal(calls, 0)
  } finally { view.app.unmount() }
})

test('hub keeps Agni selected and preserves search while destination fetch locks the picker', async () => {
  let finish; const calls = []
  const view = mount(await loadComponent('../src/pages/MarketplaceStockHub.vue', { ...api,
    marketplaceAccounts: async () => ({ data: { accounts: [{ account_key: agni, name: 'Agni', channel: 'shopee', enabled: true }] } }),
    stockMirrorActiveRun: async () => ({ data: { run: null } }),
    tiktokItems: async () => { calls.push('tiktok'); return new Promise(resolve => { finish = resolve }) },
  }), {})
  try {
    await tick(); await tick()
    const picker = view.all().find(el => el.type === 'select' && el.parent.props.class === 'account-picker')
    const search = view.all().find(el => el.type === 'input' && el.props.type === 'search')
    search.props['onUpdate:modelValue']('keep my filter'); await tick()
    const pending = view.button('Ambil Produk TikTok').props.onClick(); await tick()
    assert.equal(picker.props.disabled, true)
    await view.button('Ambil Produk Gitashop').props.onClick(); assert.deepEqual(calls, ['tiktok'])
    finish({ data: { sync: { status: 'ok' } } }); await pending; await tick()
    // Custom renderer selects the option via selectedIndex; it has no native select.value reflection.
    assert.equal(picker.options[picker.selectedIndex]?.value, agni)
    assert.equal(picker.props.disabled, false)
    assert.equal(search.value, 'keep my filter')
  } finally { view.app.unmount() }
})

test('a failed Agni cache reload does not claim indicators were updated', async () => {
  let reads = 0
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', { ...api,
    shopeeItems: async () => { if (++reads > 1) throw Error('cache unavailable'); return cached() },
    tiktokItems: async () => ({ data: { sync: { status: 'ok' } } }),
  }), { accountKey: agni, unified: true })
  try {
    await tick(); await view.button('Ambil Produk TikTok').props.onClick(); await tick()
    assert.match(view.text(view.root), /daftar Agni belum berhasil dimuat ulang/)
    assert.doesNotMatch(view.text(view.root), /Indikator pada daftar Agni sudah diperbarui/)
    assert.equal(view.button('Ambil Produk TikTok').props.disabled, false)
  } finally { view.app.unmount() }
})

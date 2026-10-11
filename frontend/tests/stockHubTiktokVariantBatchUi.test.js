import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { loadComponent, mount, tick } from './vueRendererSupport.js'

const response = data => ({ data: { data } })
const batch = (status = 'running', extra = {}) => ({ run_id: 'catalog-ui', status, message: 'Progres katalog tersimpan', can_continue: status === 'running', next_step_after_ms: 0,
  summary: { products_total: 2, products_processed: status === 'completed' ? 2 : 0, variants_verified: 1, variants_pending: 1, variants_failed: 1, variants_skipped: 0, products_need_attention: 1 },
  current_source_product_id: '10', items: [{ source_product_id: '10', name: 'Produk di luar filter', status: 'blocked', child_run_id: 'child-1', missing_count: 2, message: 'Harga perlu dilengkapi',
    result: { variants: [{ source_variant_id: '102', name: 'Biru', seller_sku: 'AGNI-BLUE', status: 'updated' }, { source_variant_id: '103', name: 'Hijau', seller_sku: 'AGNI-GREEN', status: 'under_review' }] } }], ...extra })
const item = { item_id: '10', nama: 'Produk Agni', is_live: true, models: [{ model_id: '101', model_sku: '', stock: 7, price: 25000 }],
  destination_presence: { 'tiktok-agnishopbjm': 'present', 'shopee-gitacollectionbjm': 'missing' },
  destination_variant_coverage: { 'tiktok-agnishopbjm': { state: 'missing_variants', missing_count: 2, target_product_id: '900' } } }
const baseApi = { activeTiktokVariantBatch: async () => response(null), tiktokVariantAdditionSource: async () => response(null),
  tiktokProductCreationSource: async () => response(null), gitaProductCreationSource: async () => response(null), shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }) }

test('catalog dialog shows the shared fallback measurements from the server on GET-only recovery', async () => {
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(batch('paused', { package_template: {
      weight: { value: '0.2', unit: 'KILOGRAM' }, dimensions: { length: '25', width: '25', height: '10', unit: 'CENTIMETER' }
    } }))
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.match(view.text(view.root), /25 × 25 × 10 cm/)
    assert.match(view.text(view.root), /0,2 kg/)
    assert.ok(view.button('Lanjutkan'))
  } finally { view.app.unmount() }
})

test('source toolbar keeps catalog action between reset and empty SKU repair despite filters and sends only UUID', async () => {
  let finish; const payloads = [], busy = []
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', { ...baseApi,
    startTiktokVariantBatch: async payload => { payloads.push(payload); return new Promise(resolve => { finish = resolve }) }
  }), { unified: true, accountKey: 'shopee-agnishopbjm', onBusy: value => busy.push(value) })
  try {
    await tick()
    const toolbar = view.all().find(el => el.props.class === 'header-actions')
    const buttons = toolbar.children.filter(el => el.type === 'button')
    assert.equal(view.text(buttons[0]), 'Reset Filter')
    assert.equal(view.text(buttons[1]), 'Tambah Semua Varian TikTok')
    assert.match(view.text(buttons[2]), /Isi SKU Kosong/)
    const search = view.all().find(el => el.type === 'input' && el.props.type === 'search')
    search.props['onUpdate:modelValue']('tidak ada produk'); await tick()
    const execution = view.button('Tambah Semua Varian TikTok').props.onClick(); await tick()
    assert.equal(payloads.length, 1); assert.deepEqual(Object.keys(payloads[0]), ['request_key'])
    assert.equal(busy.at(-1), true)
    assert.equal(view.button('Ambil Produk').props.disabled, true)
    assert.equal(view.all().find(el => el.props.class === 'page-shell').props.inert, true)
    finish(response(batch('completed'))); await execution; await tick()
    assert.match(view.text(view.root), /Selesai.*perlu perhatian/)
    assert.match(view.text(view.root), /Produk di luar filter/)
    assert.ok(view.button('Periksa Produk'))
  } finally { view.app.unmount() }
})

test('real source row locks mirrors, creation, repair and refresh while following, then refreshes cached coverage retaining results', async () => {
  let finish; let reads = 0; const busy = []
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', { ...baseApi,
    shopeeItems: async sync => { assert.equal(Boolean(sync), false); reads++; return { data: { items: [reads === 1 ? structuredClone(item) : { ...item, destination_variant_coverage: { 'tiktok-agnishopbjm': { state: 'complete', missing_count: 0 } } }] } } },
    startTiktokVariantBatch: async () => new Promise(resolve => { finish = resolve })
  }), { unified: true, accountKey: 'shopee-agnishopbjm', mirrorTargets: ['tiktok-agnishopbjm'], onBusy: value => busy.push(value) })
  try {
    await tick()
    view.button('Show').props.onClick(); await tick()
    const request = view.button('Tambah Semua Varian TikTok').props.onClick(); await tick()
    for (const label of ['Buat di Gitashop', 'Samakan Stok Produk', 'Samakan Stok Varian', 'Isi SKU Kosong (1)', 'Perbaiki SKU Semua Produk & Varian', 'Tambah Semua Varian TikTok (2)']) {
      assert.equal(view.button(label)?.props.disabled, true, label)
    }
    assert.equal(view.all().find(el => el.props.title === 'Refresh produk ini').props.disabled, true)
    finish(response(batch('completed'))); await request; await tick(); await tick()
    assert.equal(reads, 2); assert.equal(busy.at(-1), false)
    assert.equal(view.button('Tambah Semua Varian TikTok (2)'), undefined)
    assert.match(view.text(view.root), /Harga perlu dilengkapi/)
    assert.ok(view.button('Tambah Semua Varian TikTok'))
  } finally { view.app.unmount() }
})

test('recovered batch opens GET-only with explicit resume and pause remains available while a step is pending', async () => {
  let finish; const calls = [], busy = []
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => { calls.push('GET'); return response(batch('paused')) },
    resumeTiktokVariantBatch: async () => { calls.push('resume'); return response(batch()) },
    stepTiktokVariantBatch: async () => { calls.push('step'); return new Promise(resolve => { finish = resolve }) },
    pauseTiktokVariantBatch: async () => { calls.push('pause'); return response(batch('paused')) }
  }), { onBusy: value => busy.push(value) })
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.deepEqual(calls, ['GET', 'GET']); assert.ok(view.button('Lanjutkan'))
    const resume = view.button('Lanjutkan').props.onClick(); await tick()
    assert.equal(view.button('Tutup').props.disabled, true)
    assert.equal(view.button('Jeda').props.disabled, false)
    const pause = view.button('Jeda').props.onClick(); await tick()
    assert.equal(calls.at(-1), 'step'); assert.equal(busy.at(-1), true)
    finish(response(batch())); await resume; await pause; await tick()
    assert.equal(calls.at(-1), 'pause'); assert.equal(calls.filter(call => call === 'step').length, 1)
    assert.ok(view.button('Lanjutkan')); assert.equal(view.button('Tutup').props.disabled, false)
  } finally { view.app.unmount() }
})

test('attention inspection pauses and closes parent before GET-only child lookup using saved hidden row identity', async () => {
  const calls = []
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', { ...baseApi,
    activeTiktokVariantBatch: async () => response(batch('paused')),
    pauseTiktokVariantBatch: async () => { calls.push('pause'); return response(batch('paused')) },
    tiktokVariantAdditionSource: async id => { calls.push(['childGET', id]); return response(null) }
  }), { unified: true, accountKey: 'shopee-agnishopbjm' })
  try {
    await tick()
    const search = view.all().find(el => el.type === 'input' && el.props.type === 'search')
    search.props['onUpdate:modelValue']('hidden'); await tick()
    await view.button('Status Tambah Varian TikTok').props.onClick(); await tick()
    await view.button('Periksa Produk').props.onClick(); await tick()
    assert.deepEqual(calls, ['pause', ['childGET', '10']])
    const dialogs = view.all().filter(el => el.props.role === 'dialog')
    assert.equal(dialogs.length, 1); assert.match(view.text(dialogs[0]), /Produk di luar filter/)
  } finally { view.app.unmount() }
})

test('completed results paginate twenty products and show actual variant statuses on expansion', async () => {
  const items = Array.from({ length: 21 }, (_, index) => ({ ...batch().items[0], source_product_id: String(index + 1), name: `Produk ${index + 1}` }))
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(null), startTiktokVariantBatch: async () => response(batch('completed', { items }))
  }), {})
  try {
    await tick()
    await view.app._instance.exposed.request(); await tick()
    assert.equal(view.all().filter(el => el.type === 'details').length, 20)
    assert.match(view.text(view.root), /AGNI-BLUE/); assert.match(view.text(view.root), /Terverifikasi/); assert.match(view.text(view.root), /Menunggu peninjauan/)
    await view.button('Berikutnya').props.onClick(); await tick()
    assert.equal(view.all().filter(el => el.type === 'details').length, 1)
    assert.match(view.text(view.root), /Produk 21/)
  } finally { view.app.unmount() }
})

test('batch API wrappers use exact dedicated routes and ninety second start and step timeouts', async () => {
  const sourceText = await readFile(new URL('../src/services/index.js', import.meta.url), 'utf8')
  const calls = [], api = { get: (...args) => calls.push(['GET', ...args]), post: (...args) => calls.push(['POST', ...args]) }
  const service = new Function('api', sourceText.replace(/^import api from .*$/m, '').replaceAll('export const ', 'const ') + '\nreturn omnichannelService')(api)
  assert.equal(typeof service.startTiktokVariantBatch, 'function')
  service.startTiktokVariantBatch({ request_key: 'uuid' }); service.activeTiktokVariantBatch(); service.tiktokVariantBatchRun('id'); service.stepTiktokVariantBatch('id'); service.pauseTiktokVariantBatch('id'); service.resumeTiktokVariantBatch('id')
  assert.deepEqual(calls, [['POST', '/stock-hub/tiktok-variant-batches/runs', { request_key: 'uuid' }, { timeout: 90000 }],
    ['GET', '/stock-hub/tiktok-variant-batches/active'], ['GET', '/stock-hub/tiktok-variant-batches/runs/id'],
    ['POST', '/stock-hub/tiktok-variant-batches/runs/id/step', undefined, { timeout: 90000 }],
    ['POST', '/stock-hub/tiktok-variant-batches/runs/id/pause'], ['POST', '/stock-hub/tiktok-variant-batches/runs/id/resume']])
})

test('batch busy locks the real hub account picker and mirror controls until completion settles', async () => {
  let finish
  const view = mount(await loadComponent('../src/pages/MarketplaceStockHub.vue', { ...baseApi,
    marketplaceAccounts: async () => ({ data: { accounts: [{ account_key: 'shopee-agnishopbjm', name: 'Shopee Agni', channel: 'shopee', enabled: true }] } }),
    stockMirrorActiveRun: async () => ({ data: { run: null } }),
    startTiktokVariantBatch: async () => new Promise(resolve => { finish = resolve })
  }), {})
  try {
    await tick(); await tick()
    const request = view.button('Tambah Semua Varian TikTok').props.onClick(); await tick()
    const picker = view.all().find(el => el.props.class === 'account-picker').children.find(el => el.type === 'select')
    assert.equal(picker.props.disabled, true)
    assert.equal(view.button('Samakan Semua Stok').props.disabled, true)
    finish(response(batch('completed'))); await request; await tick(); await tick()
    assert.equal(picker.props.disabled, false)
    assert.equal(view.button('Samakan Semua Stok').props.disabled, false)
  } finally { view.app.unmount() }
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { loadComponent, mount, tick } from './vueRendererSupport.js'

const response = data => ({ data: { data } })
const batch = (status = 'running', extra = {}) => ({ run_id: 'catalog-ui', matches_request_key: true, workflow: 'agni_sku_repair_then_tiktok', can_resume_attention: false, status, message: 'Progres katalog tersimpan', can_continue: status === 'running', next_step_after_ms: 0,
  summary: { products_total: 2, products_processed: status === 'completed' ? 2 : 0, variants_verified: 1, variants_pending: 1, variants_failed: 1, variants_skipped: 0, products_need_attention: 1,
    agni_skus_verified: 0, tiktok_variants_repaired: 0, tiktok_variants_removed: 0, products_waiting_review: 0 },
  current_source_product_id: '10', items: [{ source_product_id: '10', name: 'Produk di luar filter', status: 'blocked', child_run_id: 'child-1', missing_count: 2, message: 'Harga perlu dilengkapi',
    result: { variants: [{ source_variant_id: '102', name: 'Biru', seller_sku: 'AGNI-BLUE', status: 'updated' }, { source_variant_id: '103', name: 'Hijau', seller_sku: 'AGNI-GREEN', status: 'under_review' }] } }], ...extra })
const item = { item_id: '10', nama: 'Produk Agni', is_live: true, models: [{ model_id: '101', model_sku: '', stock: 7, price: 25000 }],
  destination_presence: { 'tiktok-agnishopbjm': 'present', 'shopee-gitacollectionbjm': 'missing' },
  destination_variant_coverage: { 'tiktok-agnishopbjm': { state: 'missing_variants', missing_count: 2, target_product_id: '900' } } }
const baseApi = { activeTiktokVariantBatch: async () => response(null), tiktokVariantAdditionSource: async () => response(null),
  tiktokProductCreationSource: async () => response(null), gitaProductCreationSource: async () => response(null), shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }) }

test('initial catalog start shows waiting feedback before a run identity is returned', async () => {
  let finish
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(null),
    startTiktokVariantBatch: async () => new Promise(resolve => { finish = resolve })
  }), {})
  try {
    await tick(); const pending = view.app._instance.exposed.request(); await tick()
    assert.match(view.text(view.root), /Menyiapkan katalog/)
    assert.match(view.text(view.root), /Durasi permintaan/)
    finish(response(batch('completed'))); await pending; await tick()
    assert.doesNotMatch(view.text(view.root), /Menyiapkan katalog/)
  } finally { view.app.unmount() }
})

test('running catalog shows real scan progress, response time and request activity without implying GET restore is executing', async () => {
  let finish
  const scanned = batch('running', { items: [{ ...batch().items[0], workflow_stage: 'repair', status: 'scanning', message: 'Memeriksa kepemilikan SKU.', progress: { scanned_products: 7, total_products: 68, uploaded_images: 0, total_images: 0 } }] })
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(scanned), resumeTiktokVariantBatch: async () => response(scanned),
    stepTiktokVariantBatch: async () => new Promise(resolve => { finish = resolve })
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.match(view.text(view.root), /7\/68 produk TikTok diperiksa/)
    assert.match(view.text(view.root), /Respons terakhir/)
    assert.match(view.text(view.root), /Riwayat aktivitas/)
    assert.match(view.text(view.root), /Halaman ini belum menjalankan proses/)
    const pending = view.button('Lanjutkan').props.onClick(); await tick()
    assert.match(view.text(view.root), /Menunggu respons proses/)
    // The display clock starts on mount, before this request. Allow two ticks.
    await new Promise(resolve => setTimeout(resolve, 2200)); await tick()
    assert.match(view.text(view.root), /Durasi permintaan: [1-9]\d* detik/)
    finish(response(batch('completed'))); await pending; await tick()
    assert.doesNotMatch(view.text(view.root), /Menunggu respons proses/)
  } finally { view.app.unmount() }
})

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

test('source toolbar keeps catalog action between reset and empty SKU repair despite filters and sends UUID and fixed staged workflow', async () => {
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
    assert.equal(payloads.length, 1); assert.deepEqual(Object.keys(payloads[0]), ['request_key', 'workflow']); assert.equal(payloads[0].workflow, 'agni_sku_repair_then_tiktok')
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
    assert.equal(view.all().filter(el => el.type === 'details' && el.props.class !== 'batch-activity').length, 20)
    assert.match(view.text(view.root), /AGNI-BLUE/); assert.match(view.text(view.root), /Terverifikasi/); assert.match(view.text(view.root), /Menunggu peninjauan/)
    await view.button('Berikutnya').props.onClick(); await tick()
    assert.equal(view.all().filter(el => el.type === 'details' && el.props.class !== 'batch-activity').length, 1)
    assert.match(view.text(view.root), /Produk 21/)
  } finally { view.app.unmount() }
})

test('batch API wrappers use exact dedicated routes and ninety second start and step timeouts', async () => {
  const sourceText = await readFile(new URL('../src/services/index.js', import.meta.url), 'utf8')
  const calls = [], api = { get: (...args) => calls.push(['GET', ...args]), post: (...args) => calls.push(['POST', ...args]) }
  const service = new Function('api', sourceText.replace(/^import api from .*$/m, '').replaceAll('export const ', 'const ') + '\nreturn omnichannelService')(api)
  assert.equal(typeof service.startTiktokVariantBatch, 'function')
  service.startTiktokVariantBatch({ request_key: 'uuid' }); service.activeTiktokVariantBatch(); service.tiktokVariantBatchRun('id'); service.stepTiktokVariantBatch('id'); service.pauseTiktokVariantBatch('id'); service.resumeTiktokVariantBatch('id', { workflow: 'agni_sku_repair_then_tiktok' })
  assert.deepEqual(calls, [['POST', '/stock-hub/tiktok-variant-batches/runs', { request_key: 'uuid' }, { timeout: 90000 }],
    ['GET', '/stock-hub/tiktok-variant-batches/active'], ['GET', '/stock-hub/tiktok-variant-batches/runs/id'],
    ['POST', '/stock-hub/tiktok-variant-batches/runs/id/step', undefined, { timeout: 90000 }],
    ['POST', '/stock-hub/tiktok-variant-batches/runs/id/pause'], ['POST', '/stock-hub/tiktok-variant-batches/runs/id/resume', { workflow: 'agni_sku_repair_then_tiktok' }]])
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


test('completed attention opens from the header without starting and continues explicitly with the fixed workflow', async () => {
  const calls = []
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(batch('completed', { can_resume_attention: true })),
    startTiktokVariantBatch: async () => { calls.push('start'); return response(batch('completed')) },
    resumeTiktokVariantBatch: async (id, payload) => { calls.push([id, payload]); return response(batch('completed')) }
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.deepEqual(calls, []); assert.ok(view.button('Periksa dan Lanjutkan'))
    await view.button('Periksa dan Lanjutkan').props.onClick(); await tick()
    assert.deepEqual(calls, [['catalog-ui', { workflow: 'agni_sku_repair_then_tiktok' }]])
  } finally { view.app.unmount() }
})

test('legacy status remains viewable until explicit upgrade and explains source cleanup before additions', async () => {
  const calls = []
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(batch('paused', { workflow: 'legacy' })),
    resumeTiktokVariantBatch: async (id, payload) => { calls.push([id, payload]); return response(batch('completed')) }
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.deepEqual(calls, []); assert.match(view.text(view.root), /Alur lama/)
    assert.match(view.text(view.root), /SKU Agni.*nama varian/)
    assert.match(view.text(view.root), /varian TikTok.*usang/)
    assert.ok(view.button('Tingkatkan dan Lanjutkan'))
    await view.button('Tingkatkan dan Lanjutkan').props.onClick(); await tick()
    assert.deepEqual(calls, [['catalog-ui', { workflow: 'agni_sku_repair_then_tiktok' }]])
  } finally { view.app.unmount() }
})

test('all workflow stage values show distinct current stage labels with separate source repair and addition results', async () => {
  const stages = ['recover', 'recover_addition', 'recover_repair', 'source_sku', 'repair', 'addition', 'completed']
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(batch('paused', { summary: { ...batch().summary, agni_skus_verified: 7, tiktok_variants_repaired: 6, tiktok_variants_removed: 5 }, items: stages.map((stage, index) => ({
      ...batch().items[0], source_product_id: String(index + 1), workflow_stage: stage,
      source_sku_status: 'success', repair_status: 'exists',
      source_sku_result: { changed: [{ name: 'Biru sumber', old_sku: 'OLD', seller_sku: 'SOURCE-BLUE', status: 'verified' }] },
      repair_result: { changed: [{ name: 'Biru tujuan', old_name: 'Lama', seller_sku: 'TARGET-BLUE' }], deleted: [{ name: 'Usang', seller_sku: 'OLD-TARGET' }] }
    })) }))
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    const text = view.text(view.root)
    for (const label of ['Pemulihan proses tersimpan', 'Pemulihan penambahan TikTok', 'Pemulihan perbaikan TikTok', 'Normalisasi SKU Agni', 'Perbaikan TikTok', 'Penambahan varian TikTok', 'Tahapan selesai']) assert.ok(text.includes(label), label)
    assert.match(text, /SOURCE-BLUE/); assert.match(text, /TARGET-BLUE/); assert.match(text, /OLD-TARGET/)
    assert.match(text, /Penghapusan terverifikasi/)
    for (const [label, value] of [['SKU Agni terverifikasi', '7'], ['Perbaikan TikTok terverifikasi', '6'], ['Penghapusan TikTok terverifikasi', '5']]) {
      const counter = view.all().find(el => el.type === 'div' && el.children.some(child => child.type === 'dt' && view.text(child) === label))
      assert.equal(view.text(counter.children.find(child => child.type === 'dd')), value)
    }
  } finally { view.app.unmount() }
})

test('planned source and repair rows never claim verified fixes or completed removals before exact child success', async () => {
  let repairStatus = 'under_review'
  const source = { ...batch().items[0], workflow_stage: 'repair', source_sku_status: 'submitted_unverified',
    source_sku_result: { changed: [{ name: 'Biru', old_sku: '', seller_sku: 'SOURCE-BLUE', status: 'queued' }, { name: 'Hijau', old_sku: 'OLD', seller_sku: 'SOURCE-GREEN', status: 'verified' }] },
    repair_result: { changed: [{ name: 'Biru', seller_sku: 'TARGET-BLUE' }], deleted: [{ name: 'Usang', seller_sku: 'OLD-TARGET' }] } }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(batch('paused', { summary: { ...batch().summary, agni_skus_verified: 0, tiktok_variants_repaired: 0, tiktok_variants_removed: 0, products_waiting_review: 1 },
      items: [{ ...source, repair_status: repairStatus }] }))
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    for (const status of ['under_review', 'submitted_unverified', 'submitting', 'partial', 'rejected', null]) {
      repairStatus = status
      await view.button('Muat Status Tersimpan').props.onClick(); await tick()
      const text = view.text(view.root)
      assert.match(text, /Perubahan terencana.*menunggu verifikasi/)
      assert.match(text, /Penghapusan terencana.*menunggu verifikasi/)
      assert.doesNotMatch(text, /Penghapusan terverifikasi/)
      assert.match(text, /SOURCE-GREEN.*Terverifikasi/)
    }
    for (const label of ['SKU Agni terverifikasi', 'Perbaikan TikTok terverifikasi', 'Penghapusan TikTok terverifikasi']) {
      const counter = view.all().find(el => el.type === 'div' && el.children.some(child => child.type === 'dt' && view.text(child) === label))
      assert.equal(view.text(counter.children.find(child => child.type === 'dd')), '0')
    }
    repairStatus = 'success'
    await view.button('Muat Status Tersimpan').props.onClick(); await tick()
    assert.match(view.text(view.root), /Penghapusan terverifikasi/)
    assert.doesNotMatch(view.text(view.root), /Penghapusan terencana/)
  } finally { view.app.unmount() }
})

test('same batch attention continuations each refresh the parent after mutation while status restoration emits no completion', async () => {
  const completed = [], calls = []
  const partial = batch('completed', { can_resume_attention: true, items: [{ ...batch().items[0], status: 'partial', workflow_stage: 'addition' }] })
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(partial),
    resumeTiktokVariantBatch: async (id, payload) => { calls.push([id, payload]); return response(partial) }
  }), { onCompleted: run => completed.push(run.run_id) })
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.deepEqual(completed, [])
    assert.match(view.text(view.root), /Tahap: Penambahan varian TikTok/)
    assert.match(view.text(view.root), /Sebagian terverifikasi/)
    await view.button('Periksa dan Lanjutkan').props.onClick(); await tick()
    await view.button('Periksa dan Lanjutkan').props.onClick(); await tick()
    assert.equal(calls.length, 2)
    assert.deepEqual(completed, ['catalog-ui', 'catalog-ui'])
  } finally { view.app.unmount() }
})

test('a rejected source normalization is labeled as Shopee in its stage and product outcome', async () => {
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(batch('paused', { items: [{ ...batch().items[0], status: 'rejected', workflow_stage: 'source_sku', source_sku_status: 'rejected' }] }))
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.match(view.text(view.root), /Ditolak Shopee/)
    assert.doesNotMatch(view.text(view.root), /Ditolak TikTok/)
  } finally { view.app.unmount() }
})

test('the same-request retry control recovers a lost create key beside older attention history', async () => {
  const priorStorage = globalThis.localStorage, calls = []
  let identifiers = JSON.stringify({ run_id: 'catalog-ui', request_key: 'saved-ui-key' })
  globalThis.localStorage = { getItem: () => identifiers, setItem: (key, value) => { identifiers = value } }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(null),
    tiktokVariantBatchRun: async () => response(batch('completed', { can_resume_attention: true })),
    startTiktokVariantBatch: async payload => { calls.push(payload); return response(batch('completed', { run_id: 'recovered-ui' })) }
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.deepEqual(calls, [])
    assert.equal(view.button('Mulai Katalog Baru'), undefined)
    await view.button('Ulangi Permintaan yang Sama').props.onClick(); await tick()
    assert.deepEqual(calls, [{ request_key: 'saved-ui-key', workflow: 'agni_sku_repair_then_tiktok' }])
  } finally { view.app.unmount(); if (priorStorage === undefined) delete globalThis.localStorage; else globalThis.localStorage = priorStorage }
})

test('completed legacy attention opens from the header with an explicit upgrade control', async () => {
  let starts = 0
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(batch('completed', { workflow: 'legacy' })),
    startTiktokVariantBatch: async () => { starts++; return response(batch('completed')) }
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.equal(starts, 0)
    assert.ok(view.button('Tingkatkan dan Lanjutkan'))
  } finally { view.app.unmount() }
})

test('permanent attention retains explicit continuation and offers a distinct fresh full catalog action', async () => {
  const writes = [], completed = []
  const old = batch('completed', { can_resume_attention: true, items: [{ source_product_id: '10', name: 'Tujuan belum ada', status: 'missing_product' }] })
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(old),
    startTiktokVariantBatch: async payload => { writes.push(payload); return response(batch('completed', { run_id: 'fresh-catalog', items: [...old.items, { source_product_id: '30', name: 'Produk baru dalam katalog', status: 'success' }] })) }
  }), { onCompleted: run => completed.push(run.run_id) })
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    assert.deepEqual(writes, []); assert.ok(view.button('Periksa dan Lanjutkan'))
    assert.ok(view.button('Mulai Katalog Baru'))
    await view.button('Mulai Katalog Baru').props.onClick(); await tick()
    assert.equal(writes.length, 1); assert.equal(writes[0].workflow, 'agni_sku_repair_then_tiktok')
    assert.match(view.text(view.root), /Produk baru dalam katalog/); assert.deepEqual(completed, ['fresh-catalog'])
  } finally { view.app.unmount() }
})

test('fresh full catalog control is unavailable while a catalog is active or a create response is uncertain', async () => {
  for (const status of ['paused', 'running']) {
    const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', { activeTiktokVariantBatch: async () => response(batch(status)) }), {})
    try { await tick(); await view.app._instance.exposed.request(); await tick(); assert.equal(view.button('Mulai Katalog Baru'), undefined) }
    finally { view.app.unmount() }
  }
})

test('a fresh catalog claim race displays the competing run with explicit continuation and sends no step', async () => {
  const calls = []
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantBatch.vue', {
    activeTiktokVariantBatch: async () => response(batch('completed', { can_resume_attention: true })),
    startTiktokVariantBatch: async () => { calls.push('start'); return response(batch('running', { run_id: 'competing-catalog', matches_request_key: false })) },
    stepTiktokVariantBatch: async () => { calls.push('step'); return response(batch('completed')) }
  }), {})
  try {
    await tick(); await view.app._instance.exposed.request(); await tick()
    await view.button('Mulai Katalog Baru').props.onClick(); await tick()
    assert.deepEqual(calls, ['start']); assert.ok(view.button('Lanjutkan')); assert.equal(view.button('Mulai Katalog Baru'), undefined)
    assert.match(view.text(view.root), /lanjutkan secara eksplisit/)
  } finally { view.app.unmount() }
})

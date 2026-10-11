import test from 'node:test'
import assert from 'node:assert/strict'
import { loadComponent, mount, tick } from './vueRendererSupport.js'

const response = data => ({ data: { data } })
const run = (status, extra = {}) => ({ run_id: 'variant-ui', source_product_id: '10', remote_product_id: '900',
  status, can_continue: ['preparing', 'uploading', 'submitting', 'verifying'].includes(status), can_retry: status === 'blocked',
  context: {}, required_fields: [], progress: { scanned_products: 2, uploaded_images: 0, total_images: 2 }, message: 'Progres tersimpan', ...extra })

test('recovered package defaults are visible without asking for repeated measurements or sending a mutation', async () => {
  let writes = 0
  const api = {
    tiktokVariantAdditionSource: async () => response(run('blocked', { package_template_applied: true,
      package_template: { weight: { value: '0.2', unit: 'KILOGRAM' }, dimensions: { length: '25', width: '25', height: '10', unit: 'CENTIMETER' } } })),
    startTiktokVariantAddition: async () => { writes++ }
  }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  try {
    await view.app._instance.exposed.request({ item_id: '10', nama: 'Rok' }, true); await tick()
    assert.match(view.text(view.root), /25 × 25 × 10 cm/)
    assert.match(view.text(view.root), /0,2 kg/)
    assert.match(view.text(view.root), /dilengkapi otomatis/)
    assert.equal(view.all().filter(el => el.type === 'input').length, 0)
    assert.equal(writes, 0)
  } finally { view.app.unmount() }
})

test('dialog starts every missing variant from one product click and displays per-variant outcome', async () => {
  let finish; const calls = [], busy = []
  const api = {
    tiktokVariantAdditionSource: async () => response(null),
    startTiktokVariantAddition: async payload => { calls.push(payload); return new Promise(resolve => { finish = resolve }) }
  }
  const component = await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api)
  const view = mount(component, { onBusy: value => busy.push(value) })
  await view.app._instance.exposed.request({ item_id: '10', nama: 'Produk Agni' }, true)
  const request = view.app._instance.exposed.request({ item_id: '10', nama: 'Produk Agni' })
  await tick()
  assert.equal(calls.length, 1)
  assert.equal(calls[0].source_product_id, '10')
  assert.equal(busy.at(-1), true)
  assert.equal(view.button('Tutup').props.disabled, true)
  finish(response(run('partial', { result: { variants: [
    { source_variant_id: '102', seller_sku: 'INT-10-BLUE', name: 'Biru', status: 'updated' },
    { source_variant_id: '103', seller_sku: 'INT-10-GREEN', name: 'Hijau', status: 'failed', reason: 'Gambar varian gagal diunggah.' }
  ] } })))
  await request; await tick()
  assert.equal(view.button('Tutup').props.disabled, false)
  assert.match(view.text(view.root), /INT-10-BLUE/)
  assert.match(view.text(view.root), /Gambar varian gagal diunggah/)
  assert.match(view.text(view.root), /Sebagian varian/)
  view.app.unmount()
})

test('manual price form sends an explicit whole IDR price only after completion', async () => {
  const payloads = []
  const blocked = run('blocked', { required_fields: [{ key: 'manual_price', type: 'number', label: 'Harga varian baru (Rp)' }] })
  const api = {
    tiktokVariantAdditionSource: async () => response(blocked),
    startTiktokVariantAddition: async payload => { payloads.push(payload); return response(run('under_review')) }
  }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  await view.app._instance.exposed.request({ item_id: '10', nama: 'Produk Agni' }); await tick()
  assert.equal(payloads.length, 0)
  const input = view.all().find(el => el.type === 'input' && el.props.type === 'number')
  const form = view.all().find(el => el.type === 'form')
  assert.ok(input)
  input.props['onUpdate:modelValue']('0'); await tick()
  form.props.onSubmit({ preventDefault() {} }); await tick()
  assert.equal(payloads.length, 0)
  assert.ok(view.all().some(el => el.props.role === 'alert'))
  input.props['onUpdate:modelValue']('27500'); await tick()
  form.props.onSubmit({ preventDefault() {} }); await tick()
  assert.equal(payloads.length, 1)
  assert.deepEqual(payloads[0].context, { manual_price: 27500 })
  assert.ok(view.button('Periksa Status'))
  view.app.unmount()
})

test('Agni row launches variant batch, locks competing actions and refreshes cached coverage after completion', async () => {
  let finish; let reads = 0; const busy = []
  const item = { item_id: '10', nama: 'Produk Agni', is_live: true, models: [{ model_id: '101', model_sku: 'INT-10-RED', stock: 7, price: 25000 }], destination_presence: { 'tiktok-agnishopbjm': 'present', 'shopee-gitacollectionbjm': 'missing' },
    destination_variant_coverage: { 'tiktok-agnishopbjm': { state: 'missing_variants', missing_count: 2, target_product_id: '900', missing_model_ids: ['102', '103'] } } }
  const api = {
    shopeeItems: async sync => { assert.equal(Boolean(sync), false); reads++; return { data: { items: [reads === 1 ? item : { ...item, destination_variant_coverage: { 'tiktok-agnishopbjm': { state: 'complete', missing_count: 0 } } }] } } },
    tiktokProductCreationSource: async () => response(null), gitaProductCreationSource: async () => response(null),
    tiktokVariantAdditionSource: async () => response(null),
    startTiktokVariantAddition: async () => new Promise(resolve => { finish = resolve })
  }
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm', onBusy: value => busy.push(value) })
  await tick()
  const button = view.button('Tambah Semua Varian TikTok (2)')
  assert.ok(button)
  const execution = button.props.onClick(); await tick()
  assert.equal(busy.at(-1), true)
  assert.equal(view.button('Buat di Gitashop').props.disabled, true)
  assert.equal(view.all().find(el => el.type === 'section' && el.props.class === 'page-shell').props.inert, true)
  finish(response(run('success', { can_retry: true, result: { variants: [] } })))
  await execution; await tick(); await tick()
  assert.equal(reads, 2)
  assert.equal(busy.at(-1), false)
  assert.equal(view.button('Tambah Semua Varian TikTok (2)'), undefined)
  assert.ok(view.button('Status Varian TikTok'))
  assert.match(view.text(view.root), /Varian TikTok terverifikasi/)
  view.app.unmount()
})

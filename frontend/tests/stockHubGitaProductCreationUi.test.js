import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as Vue from 'vue'

// Vue's DOM v-model directive checks these types even with a custom renderer.
globalThis.Document = class Document {}
globalThis.ShadowRoot = class ShadowRoot {}

async function loadComponent(relativePath, services) {
  const path = new URL(relativePath, import.meta.url)
  const { descriptor } = parse(await readFile(path, 'utf8'), { filename: fileURLToPath(path) })
  let code = compileScript(descriptor, { id: 'creation-test', inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content
  const imports = {}, declarations = []
  const matches = [...code.matchAll(/import\s+([\s\S]*?)\s+from\s+['"]([^'"]+)['"]/g)]
  for (const [statement, binding, specifier] of matches) {
    let module
    if (specifier === 'vue') module = Vue
    else if (specifier === '@/services') module = { omnichannelService: services }
    else if (specifier.endsWith('OrphanVariantCleanup.vue')) module = { default: { render: () => null } }
    else {
      const target = specifier.startsWith('@/') ? new URL(`../src/${specifier.slice(2)}`, import.meta.url) : new URL(specifier, path)
      module = specifier.endsWith('.vue') ? { default: await loadComponent(target.href, services) } : await import(`${target.href}${target.href.endsWith('.js') ? '' : '.js'}`)
    }
    const name = `module${declarations.length}`; imports[name] = module
    if (binding.trim().startsWith('{')) declarations.push(`const ${binding.replace(/\bas\b/g, ':')} = imports.${name}`)
    else declarations.push(`const ${binding.trim()} = imports.${name}.default`)
    code = code.replace(statement, '')
  }
  return new Function('imports', `${declarations.join('\n')}\n${code.replace('export default', 'return')}`)(imports)
}

function mount(component, props) {
  const node = (type, text = '') => ({ type, tagName: type.toUpperCase(), text, props: {}, children: [], parent: null, value: '', focus() {}, addEventListener() {}, removeEventListener() {}, getRootNode() { return { activeElement: null } },
    get options() { return this.children.filter(child => child.type === 'option') },
    getAttribute(key) { return this.props[key] }, setAttribute(key, value) { this.props[key] = value }, removeAttribute(key) { delete this.props[key] } })
  const renderer = Vue.createRenderer({
    createElement: type => node(type), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText: (el, text) => { el.text = text }, setElementText: (el, text) => { el.text = text; el.children = [] },
    patchProp: (el, key, previous, value) => { el.props[key] = value; if (key === 'value') el.value = value }, parentNode: el => el.parent,
    nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] || null,
    insert(el, parent, anchor) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, el) },
    remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1) },
    querySelector: () => null, setScopeId() {}, insertStaticContent() { throw Error('Unexpected static host content') }
  })
  const root = node('root'), app = renderer.createApp(component, props); app.mount(root)
  const all = () => { const found = []; const visit = el => { found.push(el); el.children.forEach(visit) }; visit(root); return found }
  const text = el => el.text + el.children.map(text).join('')
  return { root, all, app, text, button: label => all().find(el => el.type === 'button' && text(el).trim() === label) }
}
const tick = async () => { await new Promise(resolve => setImmediate(resolve)); await Vue.nextTick() }
const source = '123', item = { item_id: source, nama: 'Tas Agni', is_live: true, models: [{ model_id: '1', stock: 2, price: 10000, model_sku: 'INT-123-A' }], destination_presence: { 'tiktok-agnishopbjm': 'missing', 'shopee-gitacollectionbjm': 'missing' } }
const snapshot = (status, extra = {}) => ({ run_id: 'run-gita', source_product_id: source, status, stage: status, can_continue: status === 'preparing', can_retry: status === 'blocked', required_fields: [], context: {}, progress: {}, message: 'Ready', result: null, ...extra })
const envelope = data => ({ data: { data } })

test('both creation actions coexist and Gita busy locks conflicting actions and parent propagation', async () => {
  let finish; const busy = []
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }), gitaProductCreationSource: async () => envelope(null),
    startGitaProductCreation: async () => new Promise(resolve => { finish = resolve }) }
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm', mirrorTargets: ['shopee-gitacollectionbjm'], onBusy: value => busy.push(value) })
  try {
    await tick(); assert.ok(view.button('Buat di TikTok')); assert.ok(view.button('Buat di Gitashop'))
    view.button('Buat di Gitashop').props.onClick(); await tick()
    assert.equal(busy.at(-1), true)
    assert.equal(view.button('Buat di TikTok').props.disabled, true)
    assert.equal(view.button('Refresh').props.disabled, true)
    assert.equal(view.button('Samakan Stok Produk').props.disabled, true)
    assert.equal(view.button('Tutup').props.disabled, true)
    const dialog = view.all().find(el => el.props.role === 'dialog')
    for (let p = dialog.parent; p; p = p.parent) assert.equal(Boolean(p.props.inert), false)
    finish(envelope(snapshot('partial_unverified', { remote_product_id: '200', result: { product_id: '200', skus: [{ id: '300', seller_sku: 'INT-123-A' }], published: false } }))); await tick()
    assert.equal(busy.at(-1), false); assert.ok(view.button('Periksa Status')); assert.ok(view.button('Buat di TikTok'))
    assert.ok(view.text(view.root).includes('ID model 300'))
    assert.ok(view.all().some(el => el.type === 'tr' && el.props.class?.includes('missing-destination-row')))
  } finally { view.app.unmount() }
})

test('Gita form corrects a rejected HTTPS chart and retains grouped shipping and category context', async () => {
  const saved = { category_id: '2', weight: 0.5, dimension: { package_length: 10, package_width: 20, package_height: 3 }, logistic_ids: ['1'], location_id: 'old', size_chart_image_url: 'https://localhost/chart.jpg' }
  const required_fields = [{ key: 'category_id', label: 'Kategori', type: 'category' }, { key: 'weight', label: 'Berat', type: 'number', unit: 'kg' },
    { key: 'dimension', label: 'Dimensi', type: 'number', unit: 'cm' }, { key: 'logistic_ids', label: 'Logistik', type: 'multiselect', options: [{ id: '1', name: 'Courier A' }, { id: '2', name: 'Courier B' }] },
    { key: 'location_id', label: 'Lokasi', type: 'select', options: [{ id: 'old', name: 'Old' }, { id: 'new', name: 'New' }] }, { key: 'size_chart_image_url', label: 'Chart', type: 'url' }]
  const calls = [], payloads = []
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }), gitaProductCreationSource: async () => { calls.push('GET'); return envelope(snapshot('blocked', { context: saved, required_fields })) },
    gitaProductCreationCategories: async () => envelope([{ id: '1', name: 'Fashion', is_leaf: false }, { id: '2', name: 'Tas', parent_id: '1', is_leaf: true }]),
    startGitaProductCreation: async payload => { calls.push('POST'); payloads.push(payload); return envelope(snapshot('success', { result: { product_id: '200', skus: [], published: true } })) } }
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
  try {
    await tick(); assert.ok(view.button('Buat di Gitashop')); view.button('Buat di Gitashop').props.onClick(); await tick()
    const dialog = view.all().find(el => el.props.role === 'dialog')
    const fields = view.all().filter(el => { for (let p = el.parent; p; p = p.parent) if (p === dialog) return true; return false })
    const numbers = fields.filter(el => el.type === 'input' && el.props.type === 'number')
    assert.equal(numbers.length, 4); assert.equal(numbers.filter(el => el.props.step === '1').length, 3)
    const chart = fields.find(el => el.type === 'input' && el.props.type === 'url')
    assert.ok(chart)
    assert.ok(view.text(view.root).includes('Fashion / Tas'))
    const selects = fields.filter(el => el.type === 'select')
    assert.equal(selects.length, 3); assert.equal(selects.filter(el => el.props.multiple).length, 1)
    numbers[1].props['onUpdate:modelValue']('12'); selects[1].props['onUpdate:modelValue'](['2']); selects[2].props['onUpdate:modelValue']('new'); chart.props['onUpdate:modelValue']('https://cdn.example/corrected-chart.jpg')
    await tick(); view.all().find(el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await tick()
    assert.deepEqual(calls.slice(-2), ['GET', 'POST'])
    assert.deepEqual(payloads[0].context, { ...saved, dimension: { package_length: 12, package_width: 20, package_height: 3 }, logistic_ids: ['2'], location_id: 'new', size_chart_image_url: 'https://cdn.example/corrected-chart.jpg' })
    assert.equal(view.all().some(el => el.type === 'form'), false)
    assert.ok(view.button('Buat di TikTok')); assert.equal(view.button('Buat di Gitashop'), undefined)
  } finally { view.app.unmount() }
})


for (const status of ['success', 'partial_unverified', 'under_review']) {
  test(`fresh Gita ${status} removes a stale correction form without a create`, async () => {
    let saved = snapshot('blocked', { context: { weight: 0.5 }, required_fields: [{ key: 'weight', type: 'number', label: 'Berat', unit: 'kg' }] }), posts = 0
    const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }), gitaProductCreationSource: async () => envelope(saved), startGitaProductCreation: async () => { posts++ } }
    const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
    try {
      await tick(); view.button('Buat di Gitashop').props.onClick(); await tick()
      const form = view.all().find(el => el.type === 'form'); assert.ok(form)
      saved = snapshot(status, { remote_product_id: '200' })
      form.props.onSubmit({ preventDefault() {} }); await tick()
      assert.equal(posts, 0); assert.equal(view.all().some(el => el.type === 'form'), false)
      assert.equal(view.button('Buat di Gitashop'), undefined)
    } finally { view.app.unmount() }
  })
}

test('remembered Gita source retains recovery action with missing catalog hint and failed GET', async () => {
  const previous = globalThis.localStorage; const calls = []
  globalThis.localStorage = { getItem: key => key === 'stock-hub-gita-creation:identifiers' ? JSON.stringify({ [source]: { run_id: 'saved' } }) : '{}', setItem() {} }
  let view
  try {
    const row = { ...structuredClone(item), destination_presence: { 'tiktok-agnishopbjm': 'missing' } }
    const api = { shopeeItems: async () => ({ data: { items: [row] } }), gitaProductCreationSource: async () => { calls.push('GET'); throw Error('offline') }, startGitaProductCreation: async () => calls.push('POST') }
    view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
    await tick(); assert.equal(view.button('Buat di Gitashop'), undefined); assert.ok(view.button('Muat Status Gitashop')); assert.ok(view.button('Buat di TikTok'))
    view.button('Muat Status Gitashop').props.onClick(); await tick()
    assert.ok(view.button('Muat Status Tersimpan')); assert.deepEqual(calls, ['GET', 'GET'])
  } finally { view?.app.unmount(); if (previous === undefined) delete globalThis.localStorage; else globalThis.localStorage = previous }
})

test('verified Gita existence alone updates Gita presence through refresh while TikTok remains missing', async () => {
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }), gitaProductCreationSource: async () => envelope(snapshot('exists', { result: { product_id: '200', skus: [], published: false } })) }
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
  try {
    await tick(); view.button('Buat di Gitashop').props.onClick(); await tick()
    view.button('Tutup').props.onClick(); view.button('Refresh').props.onClick(); await tick()
    const row = view.all().find(el => el.type === 'tr' && view.text(el).includes('Tas Agni'))
    assert.ok(view.text(row).includes('TikTok Agni'))
    assert.ok(view.text(row).includes('Belum ditemukan: TikTok Agni'))
    assert.equal(view.text(row).includes('Belum ditemukan: TikTok Agni, Gitashop'), false)
    assert.ok(view.button('Buat di TikTok')); assert.equal(view.button('Buat di Gitashop'), undefined)
  } finally { view.app.unmount() }
})

test('Gita request wrappers use exact paths and extended start/step/category timeout', async () => {
  const sourceText = await readFile(new URL('../src/services/index.js', import.meta.url), 'utf8')
  const calls = [], api = { get: (...args) => { calls.push(['GET', ...args]); return {} }, post: (...args) => { calls.push(['POST', ...args]); return {} } }
  const service = new Function('api', sourceText.replace(/^import api from .*$/m, '').replaceAll('export const ', 'const ') + '\nreturn omnichannelService')(api)
  assert.equal(typeof service.gitaProductCreationShippingChannels, 'function')
  service.startGitaProductCreation({ source_product_id: '123', request_key: 'uuid' }); service.gitaProductCreationSource('123'); service.gitaProductCreationRun('run'); service.stepGitaProductCreation('run'); service.gitaProductCreationCategories(); service.gitaProductCreationShippingChannels()
  assert.deepEqual(calls, [['POST', '/marketplace/gita-product-creation/runs', { source_product_id: '123', request_key: 'uuid' }, { timeout: 90000 }],
    ['GET', '/marketplace/gita-product-creation/source/123'], ['GET', '/marketplace/gita-product-creation/runs/run'],
    ['POST', '/marketplace/gita-product-creation/runs/run/step', undefined, { timeout: 90000 }], ['GET', '/marketplace/gita-product-creation/categories', { timeout: 90000 }], ['GET', '/marketplace/gita-product-creation/shipping-channels', { timeout: 90000 }]])
})

test('weight rejection loads current named shipping choices before allowing explicit corrections', async () => {
  const saved = { weight: 0.2, dimension: { package_length: 100, package_width: 100, package_height: 5 }, logistic_ids: ['8003', '8005'] }
  const required_fields = [{ key: 'weight', label: 'Berat paket', type: 'number', unit: 'kg' }, { key: 'dimension', label: 'Dimensi paket', type: 'number', unit: 'cm' }, { key: 'logistic_ids', label: 'Pengiriman Gita', type: 'multiselect', options: [] }]
  let resolveOptions; const payloads = []
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }),
    gitaProductCreationSource: async () => envelope(snapshot('rejected', { can_retry: true, context: saved, required_fields })),
    gitaProductCreationShippingChannels: async () => new Promise(resolve => { resolveOptions = resolve }),
    startGitaProductCreation: async payload => { payloads.push(payload); return envelope(snapshot('success', { result: { product_id: '200', published: true, skus: [] } })) } }
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
  try {
    await tick(); view.button('Buat di Gitashop').props.onClick(); await tick()
    assert.equal(typeof resolveOptions, 'function'); assert.equal(view.button('Lengkapi dan lanjutkan').props.disabled, true)
    view.all().find(el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await tick(); assert.equal(payloads.length, 0)
    resolveOptions(envelope([{ id: '8003', name: 'Reguler (Cashless)' }, { id: '8005', name: 'Hemat Kargo' }])); await tick()
    assert.ok(view.text(view.root).includes('Reguler (Cashless)')); assert.ok(view.text(view.root).includes('Hemat Kargo'))
    const dialog = view.all().find(el => el.props.role === 'dialog')
    const numbers = view.all().filter(el => {
      if (el.type !== 'input' || el.props.type !== 'number') return false
      for (let parent = el.parent; parent; parent = parent.parent) if (parent === dialog) return true
      return false
    })
    assert.equal(numbers.length, 4)
    numbers[1].props['onUpdate:modelValue']('10'); numbers[2].props['onUpdate:modelValue']('10')
    view.all().find(el => el.type === 'select' && el.props.multiple).props['onUpdate:modelValue'](['8003']); await tick()
    view.all().find(el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await tick()
    assert.equal(payloads.length, 1)
    assert.deepEqual(payloads[0].context, { ...saved, dimension: { package_length: 10, package_width: 10, package_height: 5 }, logistic_ids: ['8003'] })
  } finally { resolveOptions?.(envelope([])); view.app.unmount() }
})

test('unavailable shipping choices keep weight correction from posting a new product', async () => {
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }),
    gitaProductCreationSource: async () => envelope(snapshot('rejected', { can_retry: true, context: { logistic_ids: ['8003'] }, required_fields: [{ key: 'logistic_ids', type: 'multiselect', label: 'Pengiriman', options: [] }] })),
    gitaProductCreationShippingChannels: async () => { throw Error('offline') }, startGitaProductCreation: async () => assert.fail('Unsafe product creation') }
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
  try {
    await tick(); view.button('Buat di Gitashop').props.onClick(); await tick()
    assert.ok(view.button('Muat pengiriman')); assert.equal(view.button('Lengkapi dan lanjutkan').props.disabled, true)
    view.all().find(el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await tick()
  } finally { view.app.unmount() }
})


test('cross-target clicks in the same tick cannot start concurrent creation requests', async () => {
  let finish; const calls = []
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }),
    gitaProductCreationSource: async () => { calls.push('Gita GET'); return new Promise(resolve => { finish = resolve }) },
    tiktokProductCreationSource: async () => { calls.push('TikTok GET'); return envelope(null) }, startTiktokProductCreation: async () => { calls.push('TikTok POST'); return envelope(snapshot('under_review')) } }
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
  try {
    await tick(); const gita = view.button('Buat di Gitashop'), tiktok = view.button('Buat di TikTok')
    gita.props.onClick(); tiktok.props.onClick(); await tick()
    assert.deepEqual(calls, ['Gita GET'])
  } finally { finish?.(envelope(snapshot('blocked'))); await tick(); view.app.unmount() }
})


test('Gita grouped correction fields keep each dimension label independent and accessible', async () => {
  const fields = [{ key: 'dimension', type: 'number', label: 'Dimensi', unit: 'cm' }]
  const api = { gitaProductCreationSource: async () => envelope(snapshot('blocked', { required_fields: fields, context: { dimension: { package_length: 10, package_width: 20, package_height: 3 } } })) }
  const view = mount(await loadComponent('../src/components/StockHubGitaProductCreation.vue', api), {})
  try {
    await tick(); const component = view.app._instance.exposed; await component.request(item); await tick()
    const labels = view.all().filter(el => el.type === 'label')
    for (const label of labels) for (let parent = label.parent; parent; parent = parent.parent) assert.notEqual(parent.type, 'label')
    assert.equal(labels.length, 3)
  } finally { view.app.unmount() }
})

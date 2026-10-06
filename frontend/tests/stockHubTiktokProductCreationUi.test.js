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
const source = '123', item = { item_id: source, nama: 'Tas Agni', is_live: true, models: [{ model_id: '1', stock: 2, price: 10000, model_sku: 'INT-123-A' }], destination_presence: { 'tiktok-agnishopbjm': 'missing' } }
const snapshot = status => ({ run_id: 'run-ui', source_product_id: source, status, stage: status, can_continue: status === 'preparing', can_retry: status === 'blocked', required_fields: [], context: {}, progress: {}, message: 'Ready', result: null })

test('real stock screen blocks refresh, mirror and account propagation while modal remains interactive', async () => {
  let finish; const busy = []
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }), tiktokProductCreationSource: async () => ({ data: { data: null } }),
    startTiktokProductCreation: async () => new Promise(resolve => { finish = resolve }) }
  const component = await loadComponent('../src/pages/ShopeeStock.vue', api)
  const view = mount(component, { unified: true, accountKey: 'shopee-agnishopbjm', mirrorTargets: ['tiktok-agnishopbjm'], onBusy: value => busy.push(value) })
  await tick()
  const create = view.button('Buat di TikTok')
  assert.ok(create, 'eligible product has a creation action')
  create.props.onClick(); await tick()
  assert.equal(busy.at(-1), true)
  assert.equal(view.button('Refresh').props.disabled, true)
  assert.equal(view.button('Samakan Stok Produk').props.disabled, true)
  const dialog = view.all().find(el => el.props.role === 'dialog')
  assert.ok(dialog)
  for (let ancestor = dialog.parent; ancestor; ancestor = ancestor.parent) assert.equal(Boolean(ancestor.props.inert), false)
  assert.equal(view.button('Tutup').props.disabled, true)
  finish({ data: { data: snapshot('under_review') } }); await tick()
  assert.equal(busy.at(-1), false)
  assert.equal(view.button('Tutup').props.disabled, false)
  assert.equal(view.button('Buat di TikTok'), undefined)
  assert.ok(view.text(view.root).includes('Menunggu peninjauan TikTok'))
  view.app.unmount()
})

test('completion form renders only missing fields and names searchable leaf categories without default', async () => {
  const payloads = []
  const required_fields = [{ key: 'category_id', type: 'category', label: 'Kategori' }, { key: 'package_dimensions.length', type: 'number', label: 'Panjang', unit: 'cm' }]
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }), tiktokProductCreationSource: async () => ({ data: { data: { ...snapshot('blocked'), required_fields, context: { warehouse_id: 'known-wh', package_dimensions: { length: '0', width: '20', height: '3' } } } } }),
    tiktokProductCreationCategories: async () => ({ data: { data: [{ id: 'parent', name: 'Fashion', parent_id: '', is_leaf: false }, { id: 'leaf', name: 'Tas', parent_id: 'parent', is_leaf: true }] } }),
    startTiktokProductCreation: async payload => { payloads.push(payload); return { data: { data: snapshot('under_review') } } } }
  const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
  await tick(); view.button('Buat di TikTok').props.onClick(); await tick()
  const dialog = view.all().find(el => el.props.role === 'dialog')
  const descendants = view.all().filter(el => { for (let p = el.parent; p; p = p.parent) if (p === dialog) return true; return false })
  const select = descendants.find(el => el.type === 'select')
  assert.equal(select.props['onUpdate:modelValue'] instanceof Function, true)
  assert.equal(select.children.filter(el => el.type === 'option').length, 2)
  assert.ok(view.text(select).includes('Fashion / Tas'))
  const inputs = descendants.filter(el => el.type === 'input')
  assert.equal(inputs.length, 2)
  assert.equal(inputs.find(el => el.props.type === 'number').value, '')
  assert.equal(descendants.filter(el => el.type === 'label').length, 2)
  select.props['onUpdate:modelValue']('leaf')
  inputs.find(el => el.props.type === 'number').props['onUpdate:modelValue']('10')
  await tick()
  descendants.find(el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await tick()
  assert.equal(payloads.length, 1)
  assert.deepEqual(payloads[0].context, { category_id: 'leaf', warehouse_id: 'known-wh', package_dimensions: { length: '10', width: '20', height: '3', unit: 'CENTIMETER' } })
  view.app.unmount()
})

test('remembered source row keeps a read-only recovery action while mount GET fails with stale missing catalog', async () => {
  const previousStorage = globalThis.localStorage
  const stored = JSON.stringify({ [source]: { run_id: 'saved-accepted-run' } })
  globalThis.localStorage = { getItem: () => stored, setItem() {} }
  let view, rejectLookup, gets = 0, posts = 0
  try {
    const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }),
      tiktokProductCreationSource: async () => { gets++; if (gets === 1) return new Promise((resolve, reject) => { rejectLookup = reject }); throw Error('offline') },
      startTiktokProductCreation: async () => { posts++ } }
    view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
    await tick()
    assert.equal(Boolean(view.button('Buat di TikTok')), false, 'remembered identity excludes creation before recovery settles')
    assert.ok(view.button('Muat Status TikTok'))
    rejectLookup(Error('offline')); await tick()
    assert.equal(Boolean(view.button('Buat di TikTok')), false)
    assert.ok(view.button('Muat Status TikTok'))
    assert.equal(view.text(view.root).includes('Menunggu peninjauan TikTok'), false)
    assert.equal(view.text(view.root).includes('Terverifikasi di TikTok'), false)
    view.button('Muat Status TikTok').props.onClick(); await tick()
    assert.ok(view.all().find(el => el.props.role === 'dialog'))
    assert.ok(view.button('Muat Status Tersimpan'))
    assert.equal(gets, 2)
    assert.equal(posts, 0)
    assert.equal(Boolean(view.button('Buat di TikTok')), false)
  } finally { view?.app.unmount(); if (previousStorage === undefined) delete globalThis.localStorage; else globalThis.localStorage = previousStorage }
})

test('title-only blocked result keeps the actual stock row missing through catalog refresh and reload', async () => {
  const previousStorage = globalThis.localStorage
  let stored = '{}', posts = 0, view
  globalThis.localStorage = { getItem: () => stored, setItem: (key, value) => { stored = value } }
  const ambiguous = { ...snapshot('blocked'), message: 'Judul sama, hubungan SKU belum terbukti. Periksa Seller Center.' }
  const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }),
    tiktokProductCreationSource: async () => ({ data: { data: ambiguous } }),
    startTiktokProductCreation: async () => { posts++ } }
  const assertMissing = () => {
    assert.ok(view.all().some(el => el.type === 'tr' && el.props.class?.includes('missing-destination-row')))
    assert.equal(view.text(view.root).includes('Sudah ditemukan di TikTok'), false)
    assert.ok(view.button('Status Produk TikTok'))
  }
  try {
    const component = await loadComponent('../src/pages/ShopeeStock.vue', api)
    view = mount(component, { unified: true, accountKey: 'shopee-agnishopbjm' })
    await tick(); view.button('Buat di TikTok').props.onClick(); await tick()
    assertMissing()
    view.button('Tutup').props.onClick(); view.button('Refresh').props.onClick(); await tick()
    assertMissing()
    view.app.unmount()
    view = mount(component, { unified: true, accountKey: 'shopee-agnishopbjm' }); await tick()
    assertMissing()
    assert.equal(posts, 0)
  } finally { view?.app.unmount(); if (previousStorage === undefined) delete globalThis.localStorage; else globalThis.localStorage = previousStorage }
})

const correctionFields = [
  { key: 'category_id', type: 'category', label: 'Kategori TikTok' },
  { key: 'warehouse_id', type: 'text', label: 'Gudang TikTok' },
  { key: 'package_weight.value', type: 'number', label: 'Berat paket', unit: 'kg' },
  { key: 'package_dimensions.length', type: 'number', label: 'Panjang paket', unit: 'cm' },
  { key: 'package_dimensions.width', type: 'number', label: 'Lebar paket', unit: 'cm' },
  { key: 'package_dimensions.height', type: 'number', label: 'Tinggi paket', unit: 'cm' }
]
const knownContext = { category_id: '600', warehouse_id: 'wh', package_weight: { value: '0.5', unit: 'KILOGRAM' },
  package_dimensions: { length: '10', width: '20', height: '3', unit: 'CENTIMETER' } }

for (const scenario of [
  { name: 'filled invalid warehouse', context: { ...knownContext, warehouse_id: 'invalid-wh' }, fields: [correctionFields[1]], edits: { warehouse_id: 'wh' } },
  { name: 'filled invalid category', context: { ...knownContext, category_id: '999' }, fields: [correctionFields[0]], edits: { category_id: '600' } },
  { name: 'unsupported category attributes', context: knownContext, fields: [correctionFields[0]], edits: { category_id: '601' } },
  { name: 'explicit rejection', status: 'rejected', context: knownContext, fields: correctionFields, edits: { category_id: '601', warehouse_id: 'corrected-wh', 'package_weight.value': '0.75', 'package_dimensions.length': '12' } }
]) {
  test(`real form corrects ${scenario.name} after reload and sends a new UUID only after fresh GET`, async () => {
    const previousStorage = globalThis.localStorage
    let stored = '{}', saved = null, view
    globalThis.localStorage = { getItem: () => stored, setItem: (key, value) => { stored = value } }
    const calls = [], payloads = []
    const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }),
      tiktokProductCreationSource: async () => { calls.push('GET'); return { data: { data: structuredClone(saved) } } },
      tiktokProductCreationCategories: async () => ({ data: { data: [
        { id: '600', name: 'Clothing', is_leaf: true }, { id: '601', name: 'Bags', is_leaf: true }
      ] } }),
      startTiktokProductCreation: async payload => {
        calls.push('POST'); payloads.push(structuredClone(payload))
        saved = payloads.length === 1
          ? { ...snapshot(scenario.status || 'blocked'), can_retry: true, context: scenario.context, required_fields: scenario.fields }
          : { ...snapshot('success'), run_id: 'corrected-run', context: payload.context }
        return { data: { data: saved } }
      } }
    try {
      const component = await loadComponent('../src/pages/ShopeeStock.vue', api)
      view = mount(component, { unified: true, accountKey: 'shopee-agnishopbjm' })
      await tick(); view.button('Buat di TikTok').props.onClick(); await tick()
      assert.deepEqual(calls, ['GET', 'POST'])
      view.app.unmount()
      view = mount(component, { unified: true, accountKey: 'shopee-agnishopbjm' }); await tick()
      view.button('Status Produk TikTok').props.onClick(); await tick()
      const labels = view.all().filter(el => el.type === 'label' && scenario.fields.some(field => view.text(el).startsWith(field.label)))
      assert.equal(labels.length, scenario.fields.length, 'only corrective context fields appear')
      for (const [key, value] of Object.entries(scenario.edits)) {
        const field = scenario.fields.find(field => field.key === key)
        const label = labels.find(el => view.text(el).startsWith(field.label))
        const input = view.all().find(el => el.parent === label && (el.type === 'select' || (el.type === 'input' && el.props.type !== 'search')))
        input.props['onUpdate:modelValue'](value)
      }
      await tick()
      view.all().find(el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await tick()
      assert.deepEqual(calls.slice(-2), ['GET', 'POST'])
      assert.equal(payloads.length, 2)
      assert.notEqual(payloads[1].request_key, payloads[0].request_key)
      assert.match(payloads[1].request_key, /^[0-9a-f-]{36}$/)
      assert.deepEqual(payloads[1].context, {
        category_id: scenario.edits.category_id || '600', warehouse_id: scenario.edits.warehouse_id || 'wh',
        package_weight: { value: scenario.edits['package_weight.value'] || '0.5', unit: 'KILOGRAM' },
        package_dimensions: { length: scenario.edits['package_dimensions.length'] || '10', width: '20', height: '3', unit: 'CENTIMETER' }
      })
      assert.equal(view.all().some(el => el.type === 'form'), false, 'accepted snapshot has no correction form')
    } finally { view?.app.unmount(); if (previousStorage === undefined) delete globalThis.localStorage; else globalThis.localStorage = previousStorage }
  })
}

for (const status of ['success', 'under_review', 'submitted_unverified']) {
  test(`fresh ${status} permission removes corrections and prevents a stale form retry`, async () => {
    let saved = { ...snapshot('blocked'), context: { ...knownContext, warehouse_id: 'invalid-wh' }, required_fields: [correctionFields[1]] }, posts = 0
    const api = { shopeeItems: async () => ({ data: { items: [structuredClone(item)] } }),
      tiktokProductCreationSource: async () => ({ data: { data: saved } }), startTiktokProductCreation: async () => { posts++ } }
    const view = mount(await loadComponent('../src/pages/ShopeeStock.vue', api), { unified: true, accountKey: 'shopee-agnishopbjm' })
    try {
      await tick(); view.button('Buat di TikTok').props.onClick(); await tick()
      const form = view.all().find(el => el.type === 'form')
      assert.ok(form)
      saved = { ...snapshot(status), context: knownContext }
      form.props.onSubmit({ preventDefault() {} }); await tick()
      assert.equal(posts, 0)
      assert.equal(view.all().some(el => el.type === 'form'), false)
      assert.equal(view.button('Coba Lagi'), undefined)
      assert.equal(view.button('Buat di TikTok'), undefined)
    } finally { view.app.unmount() }
  })
}

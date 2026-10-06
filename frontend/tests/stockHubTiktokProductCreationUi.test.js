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

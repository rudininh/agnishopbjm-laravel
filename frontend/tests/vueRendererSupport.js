

import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as Vue from 'vue'

// Vue's DOM v-model directive checks these types even with a custom renderer.
globalThis.Document = class Document {}
globalThis.ShadowRoot = class ShadowRoot {}

export async function loadComponent(relativePath, services) {
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

export function mount(component, props) {
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
export const tick = async () => { await new Promise(resolve => setImmediate(resolve)); await Vue.nextTick() }

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { loadComponent, mount, tick } from './vueRendererSupport.js'

const response = data => ({ data: { data } })
const run = (status, extra = {}) => ({ run_id: 'variant-ui', source_product_id: '10', remote_product_id: '900',
  status, can_continue: ['preparing', 'uploading', 'submitting', 'verifying'].includes(status), can_retry: status === 'blocked',
  context: {}, required_fields: [], progress: { scanned_products: 2, uploaded_images: 0, total_images: 2 }, message: 'Progres tersimpan', ...extra })

test('pending normalization can recover changed names and continue in the same modal without TikTok writes', async () => {
  let normalized=false, recoveries=0, sourceWrites=0, tiktokWrites=0
  let sku=run('submitted_unverified',{run_id:'sku-old',can_retry:false,result:{changed:[{name:'Latte',seller_sku:'INT-10-LATTE',status:'submitted_unverified'}]}})
  const api={tiktokVariantRepairSource:async()=>normalized?response(null):({data:{data:null,prerequisite:{code:'agni_sku_pending'}}}),
    tiktokVariantAdditionSource:async()=>response(run('blocked',{repair_available:true})),
    agniSkuSource:async()=>response(sku),
    recoverAgniSku:async id=>{assert.equal(id,'sku-old');recoveries++;sku=run('reconciled',{run_id:id,can_retry:true});return response(sku)},
    startAgniSku:async payload=>{sourceWrites++;assert.equal(payload.source_product_id,'10');assert.match(payload.request_key,/^[0-9a-f-]{36}$/i);sku=run('preparing',{run_id:'sku-new'});return response(sku)},
    stepAgniSku:async id=>{assert.equal(id,'sku-new');normalized=true;sku=run('success',{run_id:id,can_retry:true,result:{changed:[{name:'Soft pink',seller_sku:'INT-10-SOFT-PINK',status:'verified'}]}});return response(sku)},
    startTiktokVariantRepair:async()=>{tiktokWrites++},startTiktokVariantAddition:async()=>{tiktokWrites++}}
  const view=mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue',api),{})
  try{
    await view.app._instance.exposed.request({item_id:'10'});await tick()
    assert.match(view.text(view.root),/0\/1 SKU/)
    assert.equal(recoveries,0);assert.equal(sourceWrites,0)
    const button=view.button('Periksa dan Lanjutkan SKU Agni');assert.ok(button)
    await button.props.onClick();await tick()
    assert.equal(recoveries,1);assert.equal(sourceWrites,1);assert.equal(tiktokWrites,0)
    assert.match(view.text(view.root),/SKU Agni terverifikasi/)
    assert.ok(view.button('Perbaiki Mengikuti Agni'))
  }finally{view.app.unmount()}
})

test('failed normalization recovery reloads saved status without automatically replaying recovery or TikTok requests', async () => {
  let recoveries=0,gets=0,writes=0
  const sku=run('submitted_unverified',{run_id:'sku-old',can_retry:false,result:{changed:[]}})
  const api={tiktokVariantRepairSource:async()=>({data:{data:null,prerequisite:{code:'agni_sku_pending'}}}),
    agniSkuSource:async()=>{gets++;return response(sku)},recoverAgniSku:async()=>{recoveries++;throw Error('timeout')},
    startAgniSku:async()=>{writes++},stepAgniSku:async()=>{writes++}}
  const view=mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue',api),{})
  try{
    await view.app._instance.exposed.request({item_id:'10'});await tick()
    const button=view.button('Periksa dan Lanjutkan SKU Agni');assert.ok(button)
    await button.props.onClick();await tick()
    assert.equal(recoveries,1);assert.equal(writes,0)
    assert.match(view.text(view.root),/Respons belum dapat dipastikan/)
    const previous=gets;await view.button('Muat Status Tersimpan').props.onClick();await tick()
    assert.ok(gets>previous);assert.equal(recoveries,1);assert.equal(writes,0)
  }finally{view.app.unmount()}
})

test('reloaded lost normalization start exposes only an explicit same-key retry even after the old prerequisite cleared', async () => {
  const previousStorage=globalThis.localStorage,key='a86e7ed1-6112-4f64-a75b-7568dbe96fbe',starts=[]
  const stored=new Map([['stock-hub-agni-skus:identifiers',JSON.stringify({10:{run_id:'sku-old',request_key:key}})]])
  globalThis.localStorage={getItem:k=>stored.get(k),setItem:(k,v)=>stored.set(k,v)}
  let sku=run('reconciled',{run_id:'sku-old',can_retry:true})
  const api={tiktokVariantRepairSource:async()=>response(null),agniSkuSource:async()=>response(sku),
    startAgniSku:async payload=>{starts.push(payload);sku=run('success',{run_id:'sku-new'});return response(sku)},
    tiktokVariantAdditionSource:async()=>response(run('blocked',{repair_available:true}))}
  const view=mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue',api),{})
  try{
    await view.app._instance.exposed.request({item_id:'10'});await tick()
    assert.equal(starts.length,0);assert.equal(view.button('Perbaiki Mengikuti Agni'),undefined)
    assert.equal(view.button('Periksa dan Lanjutkan SKU Agni'),undefined)
    assert.ok(view.button('Ulangi Permintaan SKU yang Sama'))
    await view.button('Ulangi Permintaan SKU yang Sama').props.onClick();await tick()
    assert.deepEqual(starts,[{source_product_id:'10',request_key:key}])
    assert.match(view.text(view.root),/SKU Agni terverifikasi/)
    assert.ok(view.button('Perbaiki Mengikuti Agni'))
  }finally{view.app.unmount();globalThis.localStorage=previousStorage}
})

test('one normalization continuation reads back each ambiguous accepted write and stops when a write remains unobserved', async () => {
  for(const observed of [true,false]){
    let checks=0,steps=0
    let sku=run('submitted_unverified',{run_id:'sku-old',can_retry:false,result:{changed:[]}})
    const api={tiktokVariantRepairSource:async()=>sku.status==='success'?response(null):({data:{data:null,prerequisite:{code:'agni_sku_pending'}}}),
      tiktokVariantAdditionSource:async()=>response(null),agniSkuSource:async()=>response(sku),
      recoverAgniSku:async()=>{checks++;if(observed||checks===1)sku=run('submitting',{run_id:'sku-old',can_retry:false});return response(sku)},
      stepAgniSku:async()=>{steps++;sku=run(steps===2?'success':'submitted_unverified',{run_id:'sku-old',can_retry:false});return response(sku)}}
    const view=mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue',api),{})
    try{
      await view.app._instance.exposed.request({item_id:'10'});await tick()
      await view.button('Periksa dan Lanjutkan SKU Agni').props.onClick();await tick()
      assert.equal(checks,2);assert.equal(steps,observed?2:1)
      if(observed)assert.match(view.text(view.root),/SKU Agni terverifikasi/)
      else assert.ok(view.button('Periksa dan Lanjutkan SKU Agni'))
    }finally{view.app.unmount()}
  }
})

test('explicit missing-only addition uses a new idempotent recovery request and retains status checking', async () => {
  const previousStorage=globalThis.localStorage,stored=new Map()
  globalThis.localStorage={getItem:key=>stored.get(key),setItem:(key,value)=>stored.set(key,value)}
  let saved=run('submitted_unverified',{can_add_missing:true});let finish;const requests=[],starts=[],completed=[]
  const api={tiktokVariantAdditionSource:async()=>response(saved),
    startTiktokVariantAddition:async payload=>{starts.push(payload)},
    addMissingTiktokVariants:async(id,payload)=>{requests.push({id,payload});return new Promise(resolve=>{finish=resolve})},
    stepTiktokVariantAddition:async()=>response(run('success',{run_id:'new-addition'}))}
  const view=mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue',api),{onCompleted:value=>completed.push(value)})
  try{
    await view.app._instance.exposed.request({item_id:'10'},true);await tick()
    assert.ok(view.button('Periksa Status'));const button=view.button('Tambahkan Varian yang Belum Ada');assert.ok(button)
    const action=button.props.onClick();await tick()
    assert.equal(view.button('Tutup').props.disabled,true);assert.equal(requests.length,1)
    assert.equal(requests[0].id,'variant-ui');assert.match(requests[0].payload.request_key,/^[0-9a-f-]{36}$/i)
    assert.deepEqual(JSON.parse(stored.get('stock-hub-tiktok-variants:identifiers'))['10'],{
      request_key:requests[0].payload.request_key,run_id:'variant-ui',operation:'missing_recovery'
    })
    saved=run('preparing',{run_id:'new-addition'});finish(response(saved));await action;await tick()
    assert.equal(completed.at(-1).run_id,'new-addition');assert.equal(starts.length,0)
    assert.equal(view.button('Tambahkan Varian yang Belum Ada'),undefined)
  }finally{view.app.unmount();globalThis.localStorage=previousStorage}
})

test('reloaded missing recovery retries the same operation and key when the old run is still current', async () => {
  const previousStorage=globalThis.localStorage,key='af3be53e-9a49-4acd-8383-7811a1c45e8e',requests=[]
  const stored=new Map([['stock-hub-tiktok-variants:identifiers',JSON.stringify({10:{run_id:'variant-ui',request_key:key,operation:'missing_recovery'}})]])
  globalThis.localStorage={getItem:k=>stored.get(k),setItem:(k,v)=>stored.set(k,v)}
  const api={tiktokVariantAdditionSource:async()=>response(run('submitted_unverified',{can_add_missing:true})),
    addMissingTiktokVariants:async(id,payload)=>{requests.push({id,payload});return response(run('exists',{run_id:'recovered-new'}))}}
  const view=mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue',api),{})
  try{
    await view.app._instance.exposed.request({item_id:'10'},true);await tick()
    assert.equal(view.button('Tambahkan Varian yang Belum Ada'),undefined)
    await view.button('Ulangi Permintaan yang Sama').props.onClick();await tick()
    assert.deepEqual(requests,[{id:'variant-ui',payload:{request_key:key}}])
    assert.equal(view.button('Ulangi Permintaan yang Sama'),undefined)
  }finally{view.app.unmount();globalThis.localStorage=previousStorage}
})

test('status check shows fresh progress and missing variants instead of stale review labels', async () => {
  let finish, starts=0, checks=0
  let saved=run('submitted_unverified',{result:{variants:[{seller_sku:'INT-10-BLUE',name:'Biru',status:'under_review'}]}})
  const api={tiktokVariantAdditionSource:async()=>response(saved),
    startTiktokVariantAddition:async()=>{starts++},
    stepTiktokVariantAddition:async()=>{checks++;return new Promise(resolve=>{finish=resolve})}}
  const view=mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue',api),{})
  try {
    await view.app._instance.exposed.request({item_id:'10'},true);await tick()
    const checking=view.button('Periksa Status').props.onClick();await tick()
    assert.match(view.text(view.root),/Mengambil data TikTok terbaru/)
    saved={...saved,verification:{checked_at:'2026-10-17T07:04:05Z',outcome:'mismatch',code:'coverage_mismatch',actual_count:70,expected_count:85,missing_count:15,audit_status:'APPROVED',missing_variants:[{name:'Biru',seller_sku:'INT-10-BLUE'}]}}
    finish(response(saved));await checking;await tick()
    assert.match(view.text(view.root),/70.*85/)
    assert.match(view.text(view.root),/Belum ditemukan di TikTok/)
    assert.doesNotMatch(view.text(view.root),/Menunggu peninjauan/)
    assert.match(view.text(view.root),/Belum selesai/)
    assert.equal(view.all().find(el=>el.type==='time').props.datetime,'2026-10-17T07:04:05Z')
    await view.button('Muat Status Tersimpan').props.onClick();await tick()
    assert.equal(checks,1);assert.equal(starts,0)
  } finally {view.app.unmount()}
})

test('pending source normalization explains recovery without offering a duplicate repair request', async () => {
  const previousStorage = globalThis.localStorage
  const values = new Map([['stock-hub-tiktok-variant-repairs:identifiers', JSON.stringify({ 10: { request_key: 'saved-key' } })]])
  globalThis.localStorage = { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) }
  let writes = 0
  const api = { tiktokVariantRepairSource: async () => ({ data: { data: null, prerequisite: { code: 'agni_sku_pending' } } }),
    startTiktokVariantRepair: async () => { writes++ }, startTiktokVariantAddition: async () => { writes++ } }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  try {
    await view.app._instance.exposed.request({ item_id: '10' }); await tick()
    assert.match(view.text(view.root), /Normalisasi SKU Agni.*belum terverifikasi/)
    assert.match(view.text(view.root), /Periksa dan Lanjutkan/)
    assert.doesNotMatch(view.text(view.root), /Respons perbaikan belum diterima/)
    assert.equal(view.button('Ulangi Permintaan yang Sama'), undefined)
    await view.button('Muat Status Tersimpan').props.onClick(); await tick()
    assert.equal(writes, 0)
    assert.equal(JSON.parse(values.get('stock-hub-tiktok-variant-repairs:identifiers'))['10'].request_key, 'saved-key')
  } finally { view.app.unmount(); globalThis.localStorage = previousStorage }
})

test('typed identity repair offers Agni repair while legacy text alone does not', async () => {
  for (const extra of [{ repair_available: true }, { repair_check_available: true }, {}]) {
    const api = { tiktokVariantAdditionSource: async () => response(run('blocked', { message: 'SKU konflik', ...extra })),
      tiktokVariantRepairSource: async () => response(null) }
    const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
    try {
      await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
      assert.equal(Boolean(view.button('Perbaiki Mengikuti Agni')), Boolean(extra.repair_available || extra.repair_check_available))
      if (extra.repair_check_available) assert.match(view.text(view.root), /data terbaru/i)
    } finally { view.app.unmount() }
  }
})

test('pending repair overrides stale errors and waits for verified explicit addition continuation', async () => {
  let repair = run('under_review', { run_id: 'repair-ui', message: 'Perbaikan menunggu TikTok', result: {
    changed: [{ name: 'Merah', old_name: 'Red', seller_sku: 'AGNI-RED' }], deleted: [{ name: 'Lama', seller_sku: 'OLD' }], missing_count: 2 } })
  const calls = [], additionRuns = [], completed = []
  const api = {
    tiktokVariantRepairSource: async () => { calls.push('repair GET'); return response(repair) },
    tiktokVariantAdditionSource: async () => { calls.push('addition GET'); return response(run('blocked', { repair_available: true, message: 'Error lama' })) },
    stepTiktokVariantRepair: async () => { calls.push('repair check'); repair = { ...repair, status: 'success', can_retry: false }; return response(repair) },
    startTiktokVariantRepair: async () => { calls.push('repair start') },
    startTiktokVariantAddition: async () => { calls.push('addition start'); return response(run('under_review')) }
  }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {
    onRun: value => additionRuns.push(value), onCompleted: value => completed.push(value)
  })
  try {
    await view.app._instance.exposed.request({ item_id: '10' }); await tick()
    assert.equal(calls[0], 'repair GET')
    assert.equal(calls.includes('addition start'), false)
    assert.doesNotMatch(view.text(view.root), /Error lama/)
    assert.match(view.text(view.root), /Merah/)
    assert.match(view.text(view.root), /Lama/)
    assert.match(view.text(view.root), /1 varian akan diperbaiki/)
    assert.match(view.text(view.root), /1 varian akan dihapus/)
    assert.match(view.text(view.root), /2 varian masih kurang/)
    assert.equal(view.button('Perbaiki Mengikuti Agni'), undefined)
    assert.equal(view.button('Lanjutkan Tambah Varian'), undefined)
    await view.button('Periksa Status').props.onClick(); await tick()
    assert.deepEqual(completed.map(value => value.run_id), ['repair-ui'])
    assert.ok(view.button('Lanjutkan Tambah Varian'))
    assert.match(view.text(view.root), /1 varian diperbaiki/)
    assert.match(view.text(view.root), /1 varian dihapus/)
    assert.equal(calls.includes('addition start'), false)
    assert.equal(additionRuns.some(value => value.run_id === 'repair-ui'), false)
    await view.button('Lanjutkan Tambah Varian').props.onClick(); await tick()
    assert.equal(calls.filter(call => call === 'addition start').length, 1)
    assert.ok(additionRuns.every(value => value.run_id !== 'repair-ui'))
  } finally { view.app.unmount() }
})

test('unverified plans never claim repaired or deleted variants for pre-send, rejected or pending outcomes', async () => {
  for (const status of ['preparing', 'scanning', 'blocked', 'rejected', 'under_review', 'submitted_unverified']) {
    const api = { tiktokVariantRepairSource: async () => response(run(status, { result: {
      changed: [{ name: 'Merah', seller_sku: 'AGNI-RED' }], deleted: [{ name: 'Lama', seller_sku: 'OLD' }], missing_count: 2 } })),
      tiktokVariantAdditionSource: async () => response(null) }
    const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
    try {
      await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
      assert.match(view.text(view.root), /Rencana: 1 varian akan diperbaiki/)
      assert.match(view.text(view.root), /1 varian akan dihapus/)
      assert.match(view.text(view.root), /Merah/)
      assert.match(view.text(view.root), /Lama/)
      assert.doesNotMatch(view.text(view.root), /1 varian diperbaiki|1 varian dihapus|Diperbaiki mengikuti|Dihapus karena/)
      assert.equal(view.button('Lanjutkan Tambah Varian'), undefined)
    } finally { view.app.unmount() }
  }
})

test('configured repair GET failure prevents normal addition writes', async () => {
  let writes = 0
  const api = { tiktokVariantRepairSource: async () => { throw Error('offline') },
    tiktokVariantAdditionSource: async () => response(null), startTiktokVariantAddition: async () => { writes++ } }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  try {
    await view.app._instance.exposed.request({ item_id: '10' }); await tick()
    assert.equal(writes, 0)
    assert.match(view.text(view.root), /Status.*(dimuat|diperiksa)/)
  } finally { view.app.unmount() }
})

for (const action of ['Lanjutkan Tambah Varian', 'Muat Status Tersimpan']) test(`verified repair shows a failed addition lookup from ${action} and permits only explicit recovery`, async () => {
  let failLookup = false, writes = 0
  const api = { tiktokVariantRepairSource: async () => response(run('success', { run_id: 'repair-verified', can_retry: true })),
    tiktokVariantAdditionSource: async () => { if (failLookup) throw Error('network unavailable'); return response(null) },
    startTiktokVariantAddition: async () => { writes++; return response(run('under_review')) } }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  try {
    await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
    failLookup = true
    await view.button(action).props.onClick(); await tick()
    const alerts = view.all().filter(el => el.props.role === 'alert')
    assert.equal(alerts.length, 1)
    assert.match(view.text(alerts[0]), /Status belum dapat dimuat.*koneksi.*status kembali/)
    assert.equal(writes, 0)
    assert.ok(view.button('Lanjutkan Tambah Varian'))
    failLookup = false
    await view.button('Muat Status Tersimpan').props.onClick(); await tick()
    assert.equal(view.all().some(el => el.props.role === 'alert'), false)
    assert.equal(writes, 0)
    await view.button('Lanjutkan Tambah Varian').props.onClick(); await tick()
    assert.equal(writes, 1)
  } finally { view.app.unmount() }
})

test('a pending repair still suppresses a failed stale addition lookup', async () => {
  const api = { tiktokVariantRepairSource: async () => response(run('under_review', { run_id: 'repair-pending' })),
    tiktokVariantAdditionSource: async () => { throw Error('network unavailable') } }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  try {
    await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
    assert.equal(view.all().some(el => el.props.role === 'alert'), false)
    assert.match(view.text(view.root), /Perbaikan menunggu peninjauan TikTok/)
    assert.ok(view.button('Periksa Status'))
    assert.equal(view.button('Lanjutkan Tambah Varian'), undefined)
  } finally { view.app.unmount() }
})

test('a lost new repair request suppresses stale addition errors even when its old repair was verified', async () => {
  let failAddition = false, repairs = 0
  const blocked = run('blocked', { repair_available: true })
  const api = { tiktokVariantRepairSource: async () => response(run('success', { run_id: 'old-verified', can_retry: true })),
    tiktokVariantAdditionSource: async () => { if (failAddition) throw Error('offline'); return response(blocked) },
    startTiktokVariantAddition: async () => response(run('blocked', { run_id: 'new-addition', repair_available: true })),
    startTiktokVariantRepair: async () => { repairs++; throw Error('lost repair start') } }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  try {
    await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
    await view.button('Lanjutkan Tambah Varian').props.onClick(); await tick()
    failAddition = true
    await view.button('Muat Status Tersimpan').props.onClick(); await tick()
    assert.equal(view.all().filter(el => el.props.role === 'alert').length, 1)
    await view.button('Perbaiki Mengikuti Agni').props.onClick(); await tick()
    assert.equal(repairs, 1)
    await view.button('Muat Status Tersimpan').props.onClick(); await tick()
    assert.equal(view.all().filter(el => el.props.role === 'alert').length, 0)
    assert.match(view.text(view.root), /Respons perbaikan belum diterima/)
    assert.equal(view.button('Lanjutkan Tambah Varian'), undefined)
    assert.ok(view.button('Ulangi Permintaan yang Sama'))
  } finally { view.app.unmount() }
})

test('repair holds aggregate busy until unmount and disables actions during its request', async () => {
  let finish; const busy = []
  const api = { tiktokVariantRepairSource: async () => response(null),
    tiktokVariantAdditionSource: async () => response(run('blocked', { repair_available: true })),
    startTiktokVariantRepair: async () => new Promise(resolve => { finish = resolve }) }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), { onBusy: value => busy.push(value) })
  await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
  view.app._instance.props.disabled = true; await tick()
  assert.equal(view.button('Perbaiki Mengikuti Agni').props.disabled, true)
  await view.button('Perbaiki Mengikuti Agni').props.onClick(); await tick()
  assert.equal(finish, undefined)
  view.app._instance.props.disabled = false; await tick()
  const execution = view.button('Perbaiki Mengikuti Agni').props.onClick(); await tick()
  assert.ok(!view.button('Perbaiki Mengikuti Agni') || view.button('Perbaiki Mengikuti Agni').props.disabled)
  assert.equal(view.button('Tutup').props.disabled, true)
  assert.equal(busy.at(-1), true)
  view.app.unmount()
  assert.equal(busy.at(-1), false)
  finish(response(run('under_review', { run_id: 'repair-ui' }))); await execution
})

test('switching products hides the previous repair during fresh GET recovery', async () => {
  let finish
  const api = { tiktokVariantRepairSource: async id => id === '10' ? response(run('under_review', { message: 'Old repair' })) : new Promise(resolve => { finish = resolve }),
    tiktokVariantAdditionSource: async id => response(run('blocked', { source_product_id: id, message: 'New addition' })) }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  try {
    await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
    const opening = view.app._instance.exposed.request({ item_id: '11' }, true); await tick()
    assert.doesNotMatch(view.text(view.root), /Old repair/)
    finish(response(null)); await opening; await tick()
    assert.match(view.text(view.root), /New addition/)
    assert.equal(view.button('Periksa Status'), undefined)
  } finally { view.app.unmount() }
})

test('a newly blocked addition after verified repair offers an explicit fresh repair', async () => {
  let repair = run('success', { run_id: 'repair-old', can_retry: true }), addition = run('blocked', { repair_available: true })
  let starts = 0, repairs = 0
  const api = { tiktokVariantRepairSource: async () => response(repair), tiktokVariantAdditionSource: async () => response(addition),
    startTiktokVariantAddition: async () => { starts++; addition = run('blocked', { run_id: 'addition-new', repair_available: true }); return response(addition) },
    startTiktokVariantRepair: async () => { repairs++; repair = run('under_review', { run_id: 'repair-new' }); return response(repair) } }
  const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
  try {
    await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
    assert.equal(starts, 0); assert.equal(repairs, 0)
    await view.button('Lanjutkan Tambah Varian').props.onClick(); await tick()
    assert.equal(starts, 1); assert.equal(repairs, 0)
    assert.ok(view.button('Perbaiki Mengikuti Agni'))
    await view.button('Perbaiki Mengikuti Agni').props.onClick(); await tick()
    assert.equal(repairs, 1)
    assert.equal(view.button('Perbaiki Mengikuti Agni'), undefined)
    assert.equal(view.button('Lanjutkan Tambah Varian'), undefined)
  } finally { view.app.unmount() }
})

test('reopening a pending addition prioritizes its status over a previously verified repair', async () => {
  for (const status of ['under_review', 'submitted_unverified']) {
    let steps = 0, starts = 0
    const api = { tiktokVariantRepairSource: async () => response(run('success', { run_id: 'repair-old', can_retry: true })),
      tiktokVariantAdditionSource: async () => response(run(status, { message: 'Penambahan terbaru menunggu hasil' })),
      startTiktokVariantAddition: async () => { starts++ },
      stepTiktokVariantAddition: async () => { steps++; return response(run('success')) } }
    const view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
    try {
      await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
      assert.match(view.text(view.root), /Penambahan terbaru menunggu hasil/)
      assert.equal(view.button('Lanjutkan Tambah Varian'), undefined)
      assert.equal(starts, 0); assert.equal(steps, 0)
      await view.button('Periksa Status').props.onClick(); await tick()
      assert.equal(steps, 1); assert.equal(starts, 0)
    } finally { view.app.unmount() }
  }
})

test('mount restoration is GET-only and keeps busy across repair and addition recovery', async () => {
  const previousStorage = globalThis.localStorage, calls = [], busy = []
  let finishRepair, finishAddition
  const stored = new Map([
    ['stock-hub-tiktok-variant-repairs:identifiers', JSON.stringify({ 10: { run_id: 'repair-old' } })],
    ['stock-hub-tiktok-variants:identifiers', JSON.stringify({ 10: { run_id: 'addition-old' } })]
  ])
  globalThis.localStorage = { getItem: key => stored.get(key), setItem: (key, value) => stored.set(key, value) }
  const api = { tiktokVariantRepairSource: async () => { calls.push('repair GET'); return new Promise(resolve => { finishRepair = resolve }) },
    tiktokVariantAdditionSource: async () => { calls.push('addition GET'); return new Promise(resolve => { finishAddition = resolve }) },
    stepTiktokVariantRepair: async () => calls.push('repair step'), startTiktokVariantRepair: async () => calls.push('repair start'),
    stepTiktokVariantAddition: async () => calls.push('addition step'), startTiktokVariantAddition: async () => calls.push('addition start') }
  let view
  try {
    view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), { onBusy: value => busy.push(value) })
    await tick()
    assert.equal(busy.at(-1), true)
    finishRepair(response(run('under_review', { run_id: 'repair-old' }))); await tick()
    assert.equal(busy.every(Boolean), true)
    finishAddition(response(run('blocked', { run_id: 'addition-old' }))); await tick()
    assert.deepEqual(calls, ['repair GET', 'addition GET'])
    assert.equal(busy.at(-1), false)
    assert.equal(busy.slice(0, -1).every(Boolean), true)
  } finally { view?.app.unmount(); globalThis.localStorage = previousStorage }
})

test('repair dialog traps Tab and Shift-Tab and returns focus to its launcher', async () => {
  const previousDocument = globalThis.document
  let launcherFocus = 0, firstFocus = 0, lastFocus = 0, prevented = 0
  const launcher = { focus() { launcherFocus++ } }, first = { focus() { firstFocus++ } }, last = { focus() { lastFocus++ } }
  globalThis.document = { activeElement: launcher }
  const api = { tiktokVariantRepairSource: async () => response(run('under_review')),
    tiktokVariantAdditionSource: async () => response(null) }
  let view
  try {
    view = mount(await loadComponent('../src/components/StockHubTiktokVariantAddition.vue', api), {})
    await view.app._instance.exposed.request({ item_id: '10' }, true); await tick()
    const dialog = view.all().find(el => el.props.role === 'dialog')
    dialog.querySelectorAll = () => [first, last]
    globalThis.document.activeElement = first
    dialog.props.onKeydown({ key: 'Tab', shiftKey: true, preventDefault() { prevented++ } })
    globalThis.document.activeElement = last
    dialog.props.onKeydown({ key: 'Tab', shiftKey: false, preventDefault() { prevented++ } })
    assert.equal(firstFocus, 1); assert.equal(lastFocus, 1); assert.equal(prevented, 2)
    view.button('Tutup').props.onClick(); await tick()
    assert.equal(launcherFocus, 1)
    assert.equal(view.all().some(el => el.props.role === 'dialog'), false)
  } finally { view?.app.unmount(); globalThis.document = previousDocument }
})

test('repair service sends exact dedicated endpoints with bounded mutation timeouts', async () => {
  const source = await readFile(new URL('../src/services/index.js', import.meta.url), 'utf8')
  const calls = [], api = { get: (...args) => calls.push(['GET', ...args]), post: (...args) => calls.push(['POST', ...args]) }
  const service = new Function('api', source.replace(/^import api from .*$/m, '').replaceAll('export const ', 'const ') + '\nreturn omnichannelService')(api)
  service.startTiktokVariantRepair({ source_product_id: '10', request_key: 'identity' })
  service.tiktokVariantRepairSource('10'); service.tiktokVariantRepairRun('repair-1'); service.stepTiktokVariantRepair('repair-1')
  assert.deepEqual(calls, [
    ['POST', '/stock-hub/tiktok-variant-repairs/runs', { source_product_id: '10', request_key: 'identity' }, { timeout: 90000 }],
    ['GET', '/stock-hub/tiktok-variant-repairs/source/10'], ['GET', '/stock-hub/tiktok-variant-repairs/runs/repair-1'],
    ['POST', '/stock-hub/tiktok-variant-repairs/runs/repair-1/step', undefined, { timeout: 90000 }]
  ])
})

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

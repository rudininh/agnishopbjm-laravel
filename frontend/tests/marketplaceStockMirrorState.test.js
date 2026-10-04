import test from 'node:test'
import assert from 'node:assert/strict'
import { stockMirrorScope, stockMirrorTargets, stockMirrorSummary, stockMirrorCanContinue, stockMirrorQuantity, continueStockMirror } from '../src/pages/marketplaceStockMirrorState.js'
const agni='shopee-agnishopbjm',gita='shopee-gitacollectionbjm',tik='tiktok-agnishopbjm'
test('account-bound scopes and invalid identity',()=>{
 assert.deepEqual(stockMirrorScope('all',agni),{type:'all',view_account_key:agni})
 assert.deepEqual(stockMirrorScope('variant',gita,'12','0'),{type:'variant',view_account_key:gita,product_id:'12',variant_id:'0'})
 assert.notDeepEqual(stockMirrorScope('product',agni,'12'),stockMirrorScope('product',gita,'12'))
 for(const args of [['bad',agni],['product',agni,''],['variant',agni,'12'],['all','foreign'],['product',agni,'1e3']]) assert.throws(()=>stockMirrorScope(...args))
})
test('restricted selected destinations',()=>{assert.deepEqual(stockMirrorTargets([tik,gita,tik]),[tik,gita]);assert.throws(()=>stockMirrorTargets([]));assert.throws(()=>stockMirrorTargets([agni]))})
test('summary, statuses and unavailable quantities',()=>{
 assert.equal(stockMirrorQuantity(null),'Tidak tersedia');assert.equal(stockMirrorQuantity(undefined),'Tidak tersedia');assert.equal(stockMirrorQuantity(0),0)
 assert.equal(stockMirrorSummary({summary:{success:2}}).success,2);assert.equal(stockMirrorSummary(null).pending,0)
 for(const status of ['completed','cancelled','scan_failed'])assert.equal(stockMirrorCanContinue({status,can_continue:true}),false)
 assert.equal(stockMirrorCanContinue({status:'running',can_continue:true}),true)
})
test('resume uses existing run and stops on terminal result',async()=>{
 let calls=0;const seen=[]
 await continueStockMirror({stepStockMirror:async id=>{assert.equal(id,'existing');calls++;return {data:{run_id:id,status:'completed',can_continue:false}}}},{run_id:'existing',status:'running',can_continue:true},r=>seen.push(r),()=>true)
 assert.equal(calls,1);assert.equal(seen[0].status,'completed')
})
test('stop waits for current result without another step or racing cancel',async()=>{
 let resolve,alive=true,calls=0;const seen=[]
 const p=continueStockMirror({stepStockMirror:async()=>{calls++;return new Promise(r=>resolve=r)}},{run_id:'x',status:'running',can_continue:true},r=>seen.push(r),()=>alive)
 alive=false;resolve({data:{run_id:'x',status:'running',can_continue:true}});await p
 assert.equal(calls,1);assert.equal(seen.length,1)
})
test('failed request pauses without replay',async()=>{let calls=0;await assert.rejects(continueStockMirror({stepStockMirror:async()=>{calls++;throw Error('423')}},{run_id:'x',status:'running',can_continue:true},()=>{},()=>true));assert.equal(calls,1)})
test('recover reads existing run without starting or stepping',async()=>{
 const { recoverStockMirror }=await import('../src/pages/marketplaceStockMirrorState.js')
 const calls=[]
 const run=await recoverStockMirror({stockMirrorRun:async id=>{calls.push(id);return {data:{run_id:id,status:'running',can_continue:true}}}},'stored')
 assert.deepEqual(calls,['stored']);assert.equal(run.run_id,'stored')
})
test('stopped or unmounted continuation makes no request',async()=>{
 let calls=0
 await continueStockMirror({stepStockMirror:async()=>{calls++}},{run_id:'x',status:'running',can_continue:true},()=>{},()=>false)
 assert.equal(calls,0)
})
test('cancel is serialized after an in-flight step settles',async()=>{
 let finish,stop=false,writing=false;const events=[]
 const api={stepStockMirror:async()=>{writing=true;events.push('step');await new Promise(r=>finish=r);writing=false;events.push('settled');return {data:{run_id:'x',status:'running',can_continue:true}}},cancelStockMirror:async()=>{assert.equal(writing,false);events.push('cancel');return {data:{status:'cancelled',can_continue:false}}}}
 const processing=continueStockMirror(api,{run_id:'x',status:'running',can_continue:true},()=>{},()=>!stop)
 stop=true;assert.deepEqual(events,['step']);finish();await processing;await api.cancelStockMirror('x')
 assert.deepEqual(events,['step','settled','cancel'])
})
test('HTTP-compatible UUID uses getRandomValues without randomUUID',async()=>{
 const { stockMirrorRequestKey }=await import('../src/pages/marketplaceStockMirrorState.js')
 const provider={getRandomValues(bytes){bytes.fill(255);return bytes}}
 assert.equal(stockMirrorRequestKey(provider),'ffffffff-ffff-4fff-bfff-ffffffffffff')
 assert.throws(()=>stockMirrorRequestKey({}),/Kunci permintaan/)
 assert.throws(()=>stockMirrorRequestKey({getRandomValues(){throw Error('Unavailable')}}),/Kunci permintaan/)
})
test('lost create response retains payload and explicitly retries same UUID',async()=>{
 const { createStockMirrorStarter }=await import('../src/pages/marketplaceStockMirrorState.js')
 const payloads=[];let calls=0
 const api={startStockMirror:async payload=>{payloads.push(structuredClone(payload));if(++calls===1)throw Error('lost response');return {data:{run_id:'created',status:'scanning',can_continue:true}}}}
 const starter=createStockMirrorStarter(api,{getRandomValues(bytes){bytes.fill(1);return bytes}})
 starter.prepare({type:'product',view_account_key:agni,product_id:'12'},[tik,gita])
 await assert.rejects(starter.submit(),/lost response/)
 assert.ok(starter.pendingPayload);assert.throws(()=>starter.prepare({type:'all',view_account_key:agni},[tik]))
 const result=await starter.submit();assert.equal(result.data.run_id,'created');assert.equal(result.allow_follow,true)
 assert.deepEqual(payloads[0],payloads[1]);assert.equal(starter.pendingPayload,null)
})
test('reload without stored ID recovers active run by GET without any mutation',async()=>{
 const { recoverStockMirror }=await import('../src/pages/marketplaceStockMirrorState.js')
 const original={run_id:'lost',status:'running',can_continue:true,scope:{type:'variant',view_account_key:gita,product_id:'5',variant_id:'8'},target_accounts:[tik]}
 const calls=[];const run=await recoverStockMirror({stockMirrorActiveRun:async()=>{calls.push('GET active');return {data:{run:original}}}})
 assert.deepEqual(run,original);assert.deepEqual(calls,['GET active'])
})
test('409 reads existing active run and never continues it automatically',async()=>{
 const { createStockMirrorStarter }=await import('../src/pages/marketplaceStockMirrorState.js')
 const calls=[];const original={run_id:'other',status:'scanning',can_continue:true,scope:{type:'all',view_account_key:gita}}
 const starter=createStockMirrorStarter({startStockMirror:async()=>{calls.push('POST start');throw {response:{status:409,data:{message:'Aktif'}}}},stockMirrorActiveRun:async()=>{calls.push('GET active');return {data:{run:original}}}},{getRandomValues(bytes){bytes.fill(1);return bytes}})
 starter.prepare({type:'all',view_account_key:agni},[tik]);const result=await starter.submit()
 assert.deepEqual(result.data,original);assert.equal(result.allow_follow,false);assert.equal(starter.pendingPayload,null);assert.deepEqual(calls,['POST start','GET active'])
})
test('stale stored terminal ID still discovers a newer lost create',async()=>{
 const { recoverStockMirror }=await import('../src/pages/marketplaceStockMirrorState.js')
 const calls=[];const recovered=await recoverStockMirror({stockMirrorRun:async()=>{calls.push('GET known');return {data:{run_id:'old',status:'completed',can_continue:false}}},stockMirrorActiveRun:async()=>{calls.push('GET active');return {data:{run:{run_id:'new',status:'scanning',can_continue:true}}}}},'old')
 assert.equal(recovered.run_id,'new');assert.deepEqual(calls,['GET known','GET active'])
})
test('missing stored run falls back to active lookup, empty lookup allows new action',async()=>{
 const { recoverStockMirror }=await import('../src/pages/marketplaceStockMirrorState.js')
 const calls=[]
 const result=await recoverStockMirror({stockMirrorRun:async()=>{calls.push('GET invalid');throw {response:{status:404}}},stockMirrorActiveRun:async()=>{calls.push('GET active');return {data:{run:null}}}},'invalid')
 assert.equal(result,null);assert.deepEqual(calls,['GET invalid','GET active'])
})
test('UUID generation failure does not retain pending payload or send a request',async()=>{
 const { createStockMirrorStarter }=await import('../src/pages/marketplaceStockMirrorState.js')
 let calls=0;const starter=createStockMirrorStarter({startStockMirror:async()=>{calls++}}, {})
 assert.throws(()=>starter.prepare({type:'all',view_account_key:agni},[tik]),/Kunci permintaan/)
 assert.equal(starter.pendingPayload,null);assert.equal(calls,0)
})

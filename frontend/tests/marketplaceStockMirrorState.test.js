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

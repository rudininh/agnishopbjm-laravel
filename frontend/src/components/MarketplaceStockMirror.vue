<template>
  <section class="mirror-panel" aria-labelledby="mirror-title">
    <h2 id="mirror-title">Samakan Stok dari Shopee Agni</h2>
    <p>Sumber tetap Shopee Agni. Stok terbaru dikirim ke tujuan terpilih dan diperiksa kembali. Stock Master tidak berubah.</p>
    <fieldset :disabled="locked || disabled"><legend>Tujuan</legend>
      <label v-for="target in destinations" :key="target.key"><input v-model="targets" type="checkbox" :value="target.key"> {{ target.name }}</label>
    </fieldset>
    <div class="mirror-actions">
      <button :disabled="locked || disabled || !targets.length" @click="request({type:'all', view_account_key:accountKey})">Samakan Semua Stok</button>
      <button v-if="active" :disabled="inFlight || disabled" @click="resume">Lanjutkan Proses</button>
      <button v-if="active" :disabled="cancelRequested || disabled" @click="cancel">{{ cancelRequested ? 'Menunggu langkah selesai...' : 'Batalkan Proses' }}</button>
      <button v-if="starter.pendingPayload" :disabled="inFlight || disabled" @click="retryStart">Ulangi Permintaan yang Sama</button>
      <button v-if="needsRecovery || starter.pendingPayload" :disabled="inFlight" @click="recover">Muat Status Tersimpan</button>
    </div>
    <p>Semua stok mencakup seluruh halaman katalog Agni, tanpa mengikuti filter tabel. Tombol Refresh hanya mengambil ulang data.</p>
    <p v-if="starter.pendingPayload">Respons permintaan belum diterima. Ulangi permintaan yang sama atau muat status untuk menemukan proses tersimpan.</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <div v-if="run">
      <p role="status">{{ labels[run.status] || run.status }}{{ inFlight ? ' · Memproses...' : active ? ' · Dijeda, lanjutkan atau batalkan' : '' }} — {{ run.message }}</p>
      <p v-if="run.scope">Lingkup: {{ run.scope.type }}<template v-if="run.scope.product_id"> / Produk {{ run.scope.product_id }}</template><template v-if="run.scope.variant_id != null"> / Varian {{ run.scope.variant_id }}</template> · Toko tampilan: {{ run.scope.view_account_key }} · Tujuan: {{ run.target_accounts.map(targetName).join(', ') }}</p>
      <div class="mirror-summary"><span v-for="(value, key) in summary" :key="key">{{ labels[key] }}: <strong>{{ value }}</strong></span></div>
      <p v-if="run.status === 'scanning'">Katalog masih diperiksa; jumlah menunggu belum merupakan total katalog.</p>
      <div class="mirror-table"><table><thead><tr><th>Produk / varian Agni</th><th>SKU</th><th>Tujuan</th><th>Stok Agni</th><th>Sebelum</th><th>Sesudah</th><th>Hasil</th></tr></thead>
        <tbody><tr v-for="item in run.items" :key="item.item_id"><td>{{ item.source_product_name || item.source_product_id }}<br>{{ item.source_variant_name || item.source_variant_id || '-' }}</td><td>{{ item.seller_sku || '-' }}</td><td>{{ targetName(item.target_account_key) }}</td><td>{{ quantity(item.source_stock) }}</td><td>{{ quantity(item.before_stock) }}</td><td>{{ quantity(item.after_stock) }}</td><td><strong>{{ labels[item.status] || item.status }}</strong><br>{{ item.message }}</td></tr></tbody>
      </table></div>
    </div>
  </section>
</template>
<script setup>
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { omnichannelService as api } from '@/services'
import { stockMirrorSummary, stockMirrorCanContinue, stockMirrorQuantity as quantity, continueStockMirror, recoverStockMirror, createStockMirrorStarter } from '@/pages/marketplaceStockMirrorState'
const props = defineProps({ accountKey: String, disabled: Boolean })
const emit = defineEmits(['busy', 'targets', 'completed'])
const destinations = [{key:'tiktok-agnishopbjm',name:'TikTok Agni'}, {key:'shopee-gitacollectionbjm',name:'Gitashop'}]
const targets = ref(destinations.map(t => t.key)), run = ref(null), inFlight = ref(false), error = ref(''), recoveryId = ref(''), cancelRequested = ref(false), needsRecovery = ref(true)
const starter = reactive(createStockMirrorStarter(api))
let mounted = true, stop = false
const storageKey = 'marketplace-stock-mirror:run'
const active = computed(() => stockMirrorCanContinue(run.value))
const locked = computed(() => inFlight.value || active.value || needsRecovery.value || Boolean(starter.pendingPayload))
const summary = computed(() => stockMirrorSummary(run.value))
const targetName = key => destinations.find(t => t.key === key)?.name || key
const labels = { scanning:'Memeriksa katalog',running:'Berjalan',completed:'Selesai',scan_failed:'Pemeriksaan gagal',cancelled:'Dibatalkan',checked:'Diperiksa',success:'Berhasil',unchanged:'Sudah sama',skipped:'Dilewati',failed:'Gagal',unverified:'Belum terverifikasi',pending:'Menunggu',attempted:'Menunggu verifikasi' }
watch(locked, value => emit('busy', value), {immediate:true,flush:'sync'})
watch(targets, value => emit('targets', [...value]), {immediate:true,deep:true})
function update(value) {
  if (!mounted) return
  run.value = value
  recoveryId.value = value?.run_id || ''
  if (value) starter.pendingPayload = null
  try {
    if (value) localStorage.setItem(storageKey, value.run_id)
    else localStorage.removeItem(storageKey)
  } catch {}
}
function request(scope) {
  if (locked.value || props.disabled) return
  try {
    starter.prepare(scope, targets.value)
    return retryStart()
  } catch (e) {
    error.value = e.message
  }
}
defineExpose({ request })
async function execute(action, follow = false) {
  if (inFlight.value || !mounted) return
  inFlight.value = true; error.value = ''; stop = false
  try {
    const response = await action()
    update(response.data)
    error.value = response.notice || ''
    if (follow && response.allow_follow !== false && mounted) await continueStockMirror(api, run.value, update, () => mounted && !stop)
    if (mounted && run.value && !stockMirrorCanContinue(run.value)) emit('completed')
  } catch(e) {
    if (mounted) error.value = e.response?.data?.message || 'Koneksi terputus. Muat atau lanjutkan proses tersimpan; hasil yang belum terverifikasi tidak diulang.'
  } finally {
    if (mounted) { inFlight.value = false; if (cancelRequested.value) {cancelRequested.value=false; await cancel()} }
  }
}
function retryStart() {
  if (inFlight.value || props.disabled || !starter.pendingPayload) return
  return execute(() => starter.submit(), true)
}
function resume() {
  if (!props.disabled && active.value) return execute(() => api.stockMirrorRun(run.value.run_id), true)
}
function recover() {
  return execute(async () => {
    const value = await recoverStockMirror(api, recoveryId.value)
    if (mounted) needsRecovery.value = false
    return {data:value}
  })
}
async function cancel() {
  if (!active.value || props.disabled) return
  stop = true
  if (inFlight.value) {cancelRequested.value=true; return}
  return execute(() => api.cancelStockMirror(run.value.run_id))
}
onMounted(() => {try {recoveryId.value=localStorage.getItem(storageKey)||''} catch {} recover()})
onBeforeUnmount(() => {mounted=false;stop=true;emit('busy',false)})
</script>
<style scoped>
.mirror-panel { margin:18px 28px 0 268px; padding:18px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#334155; }
h2 {font-size:18px;margin:0 0 8px} p {font-size:13px;line-height:1.6} fieldset {border:1px solid #e2e8f0;border-radius:6px;display:flex;gap:18px} label {display:flex;align-items:center;gap:6px} button {padding:8px 12px;border:1px solid #94a3b8;border-radius:6px;background:#f8fafc;color:#0f172a;cursor:pointer} button:disabled {opacity:.5;cursor:not-allowed}.mirror-actions,.mirror-summary {display:flex;flex-wrap:wrap;gap:10px;margin:12px 0}.mirror-table {overflow-x:auto} table {border-collapse:collapse;width:100%;font-size:12px} th,td {padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;min-width:90px} th {background:#f1f5f9} @media(max-width:820px){.mirror-panel{margin:18px}fieldset{flex-wrap:wrap}}
</style>

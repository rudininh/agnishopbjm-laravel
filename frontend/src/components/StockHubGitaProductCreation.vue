<template>
  <div v-if="open" class="creation-backdrop">
    <section class="creation-dialog" role="dialog" aria-modal="true" aria-labelledby="gita-creation-title" tabindex="-1" ref="dialog">
      <h2 id="gita-creation-title">{{ state.run ? 'Produk Shopee Gitashop' : 'Buat di Gitashop' }}</h2>
      <p>{{ productName || `Produk Shopee ${state.sourceId}` }}</p>
      <p v-if="state.busy" role="status">Memproses, tunggu langkah selesai...</p>
      <p v-if="state.error || formError" role="alert">{{ formError || state.error }}</p>
      <template v-if="state.run">
        <p role="status"><strong>{{ rowResult.label }}</strong><br>{{ state.run.message }}</p>
        <p v-if="state.paused && state.run.can_continue">Proses dijeda. Lanjutkan ketika siap.</p>
        <p v-if="state.run.can_continue">Katalog diperiksa: {{ state.run.progress?.scanned_products || 0 }} · Gambar: {{ state.run.progress?.uploaded_images || 0 }} / {{ state.run.progress?.total_images || 0 }} · Varian: {{ state.run.variant_count }}</p>
        <template v-if="state.run.result">
          <p>ID produk Gitashop: {{ state.run.result.product_id }}<br>{{ state.run.status === 'under_review' ? 'Produk masih menunggu peninjauan Gitashop.' : state.run.result.published ? 'Publikasi telah terverifikasi.' : 'Publikasi belum dikonfirmasi.' }}</p>
          <ul v-if="state.run.result.skus?.length"><li v-for="sku in state.run.result.skus" :key="sku.id">{{ sku.seller_sku }} · ID model {{ sku.id }}</li></ul>
        </template>
        <form v-if="state.run.can_retry && !state.pendingKey" @submit.prevent="retry">
          <div class="creation-fields" v-if="correctionFields.length">
            <fieldset v-for="field in correctionFields" :key="field.key">
              <legend>{{ field.label }}{{ field.unit ? ` (${field.unit})` : '' }}</legend>
              <template v-if="field.type === 'category'">
                <input v-model="categorySearch" type="search" placeholder="Cari nama kategori" :disabled="state.busy" aria-label="Cari kategori Gitashop">
                <select v-model="values[field.key]" :aria-label="field.label" required :disabled="state.busy || categoriesLoading">
                  <option value="">{{ categoriesLoading ? 'Memuat kategori...' : 'Pilih kategori yang sesuai' }}</option>
                  <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
                <small v-if="categoryError" role="alert">{{ categoryError }} <button type="button" :disabled="categoriesLoading" @click="loadCategories">Muat kategori</button></small>
              </template>
              <div v-else-if="field.key === 'dimension'" class="dimension-fields">
                <label v-for="dimension in dimensions" :key="dimension.key"><span>{{ dimension.label }} (cm)</span>
                  <input v-model="values[`dimension.${dimension.key}`]" type="number" min="1" step="1" :disabled="state.busy" required>
                </label>
              </div>
              <select v-else-if="field.type === 'multiselect' || field.type === 'select'" v-model="values[field.key]" :aria-label="field.label" :multiple="field.type === 'multiselect'" :disabled="state.busy || (field.key === 'logistic_ids' && shippingBlocked)" required>
                <option v-if="field.type === 'select'" value="">Pilih lokasi</option>
                <option v-for="option in field.options || []" :key="option.id" :value="String(option.id)">{{ option.name }}</option>
              </select>
              <input v-else v-model="values[field.key]" :aria-label="field.label" :type="field.type === 'number' ? 'number' : field.type === 'url' ? 'url' : 'text'" :min="field.type === 'number' ? '0.000001' : undefined" :step="field.type === 'number' ? 'any' : undefined" :disabled="state.busy" required>
              <small v-if="field.key === 'weight'">Isi berat paket beserta kemasan dalam kg. 200 gram = 0,2 kg.</small>
              <small v-if="field.key === 'logistic_ids' && shippingLoading">Memuat saluran pengiriman...</small>
              <small v-if="field.key === 'logistic_ids' && shippingError" role="alert">{{ shippingError }} <button type="button" :disabled="shippingLoading || state.busy" @click="loadShippingChannels">Muat pengiriman</button></small>
            </fieldset>
          </div>
          <button type="submit" :disabled="state.busy || disabled || categoriesLoading || shippingBlocked">{{ state.run.required_fields?.length ? 'Lengkapi dan lanjutkan' : 'Coba Lagi' }}</button>
        </form>
      </template>
      <p v-else-if="state.pendingKey">Respons permintaan belum diterima. Periksa status atau ulangi permintaan yang sama.</p>
      <div class="creation-actions">
        <button v-if="state.run?.can_continue" :disabled="state.busy || disabled" @click="controller.resume()">Lanjutkan Proses</button>
        <button v-if="['submitted_unverified', 'partial_unverified', 'under_review'].includes(state.run?.status) && state.run.remote_product_id" :disabled="state.busy || disabled" @click="controller.checkStatus()">Periksa Status</button>
        <button v-if="state.pendingKey" :disabled="state.busy || disabled" @click="controller.retryPending()">Ulangi Permintaan yang Sama</button>
        <button :disabled="state.busy" @click="controller.recover()">Muat Status Tersimpan</button>
        <button :disabled="state.busy" @click="close">Tutup</button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { omnichannelService } from '@/services'
import { createGitaProductController, creationContext, creationFieldValues, creationLeafCategories, creationRowResult } from '@/pages/stockHubGitaProductCreationState'

const dimensions = [{ key: 'package_length', label: 'Panjang' }, { key: 'package_width', label: 'Lebar' }, { key: 'package_height', label: 'Tinggi' }]
const props = defineProps({ disabled: Boolean })
const emit = defineEmits(['busy', 'run', 'remembered'])
const state = ref({ busy: false, run: null, pendingKey: '', error: '', sourceId: '', paused: false })
const open = ref(false), dialog = ref(null), productName = ref(''), formError = ref(''), values = ref({})
const categoryNodes = ref([]), categorySearch = ref(''), categoriesLoading = ref(false), categoryError = ref('')
const shippingOptions = ref([]), shippingLoading = ref(false), shippingError = ref('')
const shippingNeeded = computed(() => state.value.run?.required_fields?.some(field => field.key === 'logistic_ids' && !field.options?.length))
const shippingBlocked = computed(() => Boolean(shippingNeeded.value && (shippingLoading.value || !shippingOptions.value.length)))
const correctionFields = computed(() => (state.value.run?.required_fields || []).map(field => field.key === 'logistic_ids' && !field.options?.length ? { ...field, options: shippingOptions.value } : field))
let alive = true, previousFocus = null
let storage
try { storage = globalThis.localStorage } catch {}
const controller = createGitaProductController(omnichannelService, {
  storage, onChange: value => { state.value = value }, onBusy: value => emit('busy', value), onRun: run => emit('run', run), onRemember: sourceId => emit('remembered', sourceId)
})
const rowResult = computed(() => creationRowResult(state.value.run))
const categories = computed(() => {
  const found = creationLeafCategories(categoryNodes.value, categorySearch.value)
  const selected = creationLeafCategories(categoryNodes.value).find(category => category.id === values.value.category_id)
  return selected && !found.some(category => category.id === selected.id) ? [selected, ...found] : found
})
watch(() => state.value.run, run => {
  values.value = creationFieldValues(run?.context)
  formError.value = ''
  if (open.value && run?.required_fields?.some(field => field.type === 'category') && !categoryNodes.value.length) loadCategories()
  if (open.value && shippingNeeded.value && !shippingOptions.value.length) loadShippingChannels()
})
async function loadShippingChannels() {
  if (shippingLoading.value) return
  shippingLoading.value = true; shippingError.value = ''; shippingOptions.value = []
  try {
    const response = await omnichannelService.gitaProductCreationShippingChannels()
    const options = response?.data?.data
    if (!Array.isArray(options) || !options.length || options.some(option => !/^\d+$/.test(option?.id) || typeof option.name !== 'string' || !option.name.trim()) || new Set(options.map(option => option.id)).size !== options.length) throw Error('Invalid shipping choices')
    if (alive) shippingOptions.value = options
  } catch { if (alive) shippingError.value = 'Saluran pengiriman belum tersedia. Muat kembali sebelum melanjutkan.' }
  finally { if (alive) shippingLoading.value = false }
}
async function loadCategories() {
  if (categoriesLoading.value) return
  categoriesLoading.value = true; categoryError.value = ''
  try {
    const response = await omnichannelService.gitaProductCreationCategories()
    if (alive) categoryNodes.value = response.data.data || []
  } catch { if (alive) categoryError.value = 'Kategori belum dapat dimuat. Coba kembali.' }
  finally { if (alive) categoriesLoading.value = false }
}
async function request(item, inspect = false) {
  if (props.disabled || state.value.busy) return
  previousFocus = globalThis.document?.activeElement
  productName.value = item.nama || ''; categorySearch.value = ''; formError.value = ''; shippingOptions.value = []; shippingError.value = ''; open.value = true
  nextTick(() => dialog.value?.focus())
  return inspect ? controller.recover(String(item.item_id)) : controller.start(String(item.item_id))
}
function retry() {
  if (props.disabled || state.value.busy || shippingBlocked.value) return
  try {
    const run = state.value.run
    const context = creationContext(run.context, correctionFields.value, values.value)
    if (context.category_id && run.required_fields?.some(field => field.key === 'category_id') && !creationLeafCategories(categoryNodes.value).some(category => category.id === context.category_id)) {
      throw Error('Pilih kategori akhir yang sesuai dengan produk.')
    }
    formError.value = ''; return controller.retry(context)
  } catch (error) { formError.value = error.message }
}
function close() { if (!state.value.busy) { open.value = false; previousFocus?.focus?.() } }
defineExpose({ request })
onMounted(() => controller.restore())
onBeforeUnmount(() => { alive = false; controller.stop() })
</script>

<style scoped>
.creation-backdrop { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 18px; background: #0f172a80; }
.creation-dialog { width: min(560px, 100%); max-height: 90vh; overflow: auto; padding: 22px; border-radius: 10px; background: white; color: #334155; box-shadow: 0 12px 40px #0f172a40; }
h2 { margin: 0 0 12px; font-size: 20px; } p, li { font-size: 13px; line-height: 1.6; } [role=alert] { color: #b91c1c; }
.creation-fields { display: grid; gap: 14px; margin: 16px 0; } label { display: grid; gap: 6px; font-size: 13px; }
fieldset { margin: 0; padding: 0; border: 0; min-inline-size: 0; display: grid; gap: 6px; font-size: 13px; } legend { padding: 0; margin-bottom: 6px; }
input, select { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid #cbd5e1; border-radius: 5px; font: inherit; }
button { padding: 9px 12px; border: 1px solid #94a3b8; border-radius: 6px; background: #f8fafc; color: #0f172a; cursor: pointer; } button:disabled { opacity: .5; cursor: not-allowed; }
.dimension-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.creation-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; } ul { padding-left: 18px; }
</style>

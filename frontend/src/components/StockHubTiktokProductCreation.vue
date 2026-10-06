<template>
  <div v-if="open" class="creation-backdrop">
    <section class="creation-dialog" role="dialog" aria-modal="true" aria-labelledby="creation-title" tabindex="-1" ref="dialog">
      <h2 id="creation-title">{{ state.run ? 'Produk TikTok Agni' : 'Buat di TikTok' }}</h2>
      <p>{{ productName || `Produk Shopee ${state.sourceId}` }}</p>
      <p v-if="state.busy" role="status">Memproses, tunggu langkah selesai...</p>
      <p v-if="state.error || formError" role="alert">{{ formError || state.error }}</p>
      <template v-if="state.run">
        <p role="status"><strong>{{ rowResult.label }}</strong><br>{{ state.run.message }}</p>
        <p v-if="state.paused && state.run.can_continue">Proses dijeda. Lanjutkan ketika siap.</p>
        <p v-if="state.run.can_continue">Katalog diperiksa: {{ state.run.progress?.scanned_products || 0 }} · Gambar: {{ state.run.progress?.uploaded_images || 0 }} / {{ state.run.progress?.total_images || 0 }} · Varian: {{ state.run.variant_count }}</p>
        <template v-if="state.run.result">
          <p>ID produk TikTok: {{ state.run.result.product_id }}<br>{{ state.run.status === 'under_review' ? 'Produk masih menunggu peninjauan TikTok.' : state.run.result.published ? 'Publikasi telah terverifikasi.' : 'Publikasi belum dikonfirmasi.' }}</p>
          <ul v-if="state.run.result.skus?.length"><li v-for="sku in state.run.result.skus" :key="sku.id">{{ sku.seller_sku }} · ID SKU {{ sku.id }}</li></ul>
        </template>
        <form v-if="state.run.can_retry && !state.pendingKey" @submit.prevent="retry">
          <div class="creation-fields" v-if="state.run.required_fields?.length">
            <label v-for="field in state.run.required_fields" :key="field.key">
              <span>{{ field.label }}{{ field.unit ? ` (${field.unit})` : '' }}</span>
              <template v-if="field.type === 'category'">
                <input v-model="categorySearch" type="search" placeholder="Cari nama kategori" :disabled="state.busy" aria-label="Cari kategori TikTok">
                <select v-model="values[field.key]" required :disabled="state.busy || categoriesLoading">
                  <option value="">{{ categoriesLoading ? 'Memuat kategori...' : 'Pilih kategori yang sesuai' }}</option>
                  <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
                <small v-if="categoryError" role="alert">{{ categoryError }} <button type="button" :disabled="categoriesLoading" @click="loadCategories">Muat kategori</button></small>
              </template>
              <input v-else v-model="values[field.key]" :type="field.type === 'number' ? 'number' : 'text'" :min="field.type === 'number' ? '0.000001' : undefined" :step="field.type === 'number' ? 'any' : undefined" :disabled="state.busy" required>
            </label>
          </div>
          <button type="submit" :disabled="state.busy || disabled || categoriesLoading">{{ state.run.required_fields?.length ? 'Lengkapi dan lanjutkan' : 'Coba Lagi' }}</button>
        </form>
      </template>
      <p v-else-if="state.pendingKey">Respons permintaan belum diterima. Periksa status atau ulangi permintaan yang sama.</p>
      <div class="creation-actions">
        <button v-if="state.run?.can_continue" :disabled="state.busy || disabled" @click="controller.resume()">Lanjutkan Proses</button>
        <button v-if="state.run?.status === 'submitted_unverified' && state.run.remote_product_id" :disabled="state.busy || disabled" @click="controller.checkStatus()">Periksa Status</button>
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
import { createTiktokProductController, creationContext, creationFieldValues, creationLeafCategories, creationRowResult } from '@/pages/stockHubTiktokProductCreationState'

const props = defineProps({ disabled: Boolean })
const emit = defineEmits(['busy', 'run'])
const state = ref({ busy: false, run: null, pendingKey: '', error: '', sourceId: '', paused: false })
const open = ref(false), dialog = ref(null), productName = ref(''), formError = ref(''), values = ref({})
const categoryNodes = ref([]), categorySearch = ref(''), categoriesLoading = ref(false), categoryError = ref('')
let alive = true, previousFocus = null
let storage
try { storage = globalThis.localStorage } catch {}
const controller = createTiktokProductController(omnichannelService, {
  storage, onChange: value => { state.value = value }, onBusy: value => emit('busy', value), onRun: run => emit('run', run)
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
})
async function loadCategories() {
  if (categoriesLoading.value) return
  categoriesLoading.value = true; categoryError.value = ''
  try {
    const response = await omnichannelService.tiktokProductCreationCategories()
    if (alive) categoryNodes.value = response.data.data || []
  } catch { if (alive) categoryError.value = 'Kategori belum dapat dimuat. Coba kembali.' }
  finally { if (alive) categoriesLoading.value = false }
}
async function request(item, inspect = false) {
  if (props.disabled || state.value.busy) return
  previousFocus = globalThis.document?.activeElement
  productName.value = item.nama || ''; categorySearch.value = ''; formError.value = ''; open.value = true
  nextTick(() => dialog.value?.focus())
  return inspect ? controller.recover(String(item.item_id)) : controller.start(String(item.item_id))
}
function retry() {
  if (props.disabled || state.value.busy) return
  try {
    const run = state.value.run
    const context = creationContext(run.context, run.required_fields || [], values.value)
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
input, select { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid #cbd5e1; border-radius: 5px; font: inherit; }
button { padding: 9px 12px; border: 1px solid #94a3b8; border-radius: 6px; background: #f8fafc; color: #0f172a; cursor: pointer; } button:disabled { opacity: .5; cursor: not-allowed; }
.creation-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; } ul { padding-left: 18px; }
</style>

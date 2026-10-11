<template>
  <div v-if="open" class="variant-backdrop" @click.self="close" @keydown.esc="close">
    <section ref="dialog" class="variant-dialog" role="dialog" aria-modal="true" aria-labelledby="tiktok-variant-title" tabindex="-1" @keydown.tab="trapFocus">
      <h2 id="tiktok-variant-title">Varian TikTok Agni</h2>
      <p>{{ productName }}</p>
      <p class="source-note">SKU, gambar, nama varian, dan stok varian baru mengikuti Agni. Harga memakai harga mayoritas produk TikTok.</p>
      <p v-if="state.error || formError" role="alert">{{ formError || state.error }}</p>
      <template v-if="state.run">
        <p v-if="packageTemplate" class="source-note">Template paket: {{ packageTemplate }}. Dipakai otomatis untuk data yang kosong atau nol setelah memeriksa data Agni. Data yang sudah valid tetap dipakai.</p>
        <p v-if="state.run.package_template_applied" class="source-note">Data paket kosong dilengkapi otomatis; tidak perlu diisi ulang.</p>
        <p><strong>{{ result?.label }}</strong><br />{{ state.run.message }}</p>
        <p v-if="state.run.can_continue" aria-live="polite">
          {{ state.run.progress?.scanned_products || 0 }} produk diperiksa ·
          {{ state.run.progress?.uploaded_images || 0 }}/{{ state.run.progress?.total_images || 0 }} gambar varian diproses
        </p>
        <p v-if="state.run.price || state.run.result?.price">Harga varian baru: {{ rupiah(state.run.price || state.run.result.price) }}</p>
        <ul v-if="state.run.result?.variants?.length" class="variant-results">
          <li v-for="variant in state.run.result.variants" :key="variant.source_variant_id || variant.seller_sku">
            <strong>{{ variant.name || variant.variant_name || variant.seller_sku }}</strong>
            <small>{{ variant.seller_sku }}</small>
            <span>{{ statusLabel(variant.status) }}</span>
            <small v-if="variant.reason">{{ variant.reason }}</small>
          </li>
        </ul>
        <form v-if="state.run.can_retry && ['blocked', 'rejected'].includes(state.run.status)" @submit.prevent="retry">
          <label v-if="needsPrice">Harga varian baru (Rp)
            <input v-model="manualPrice" type="number" min="1" step="1" inputmode="numeric" :disabled="state.busy || disabled" />
          </label>
          <button type="submit" :disabled="state.busy || disabled">{{ needsPrice ? 'Lengkapi dan lanjutkan' : 'Coba Lagi' }}</button>
        </form>
      </template>
      <p v-else-if="state.pendingKey">Respons permintaan belum diterima. Muat status tersimpan sebelum melanjutkan.</p>
      <div class="variant-actions">
        <button v-if="state.run?.can_continue" :disabled="state.busy || disabled" @click="execute(() => controller.resume())">Lanjutkan Proses</button>
        <button v-if="['submitted_unverified', 'under_review'].includes(state.run?.status) && state.run.remote_product_id" :disabled="state.busy || disabled" @click="execute(() => controller.checkStatus())">Periksa Status</button>
        <button v-if="state.pendingKey" :disabled="state.busy || disabled" @click="execute(() => controller.retryPending())">Ulangi Permintaan yang Sama</button>
        <button :disabled="state.busy" @click="controller.recover()">Muat Status Tersimpan</button>
        <button :disabled="state.busy" @click="close">Tutup</button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { omnichannelService } from '@/services'
import { createTiktokVariantController, variantPriceContext, variantRowResult } from '@/pages/stockHubTiktokVariantState'
import { packageTemplateText } from '@/pages/stockHubPackageTemplate'

const props = defineProps({ disabled: Boolean })
const emit = defineEmits(['busy', 'run', 'remembered', 'completed'])
const state = ref({ busy: false, run: null, pendingKey: '', error: '', sourceId: '', paused: false })
const open = ref(false), dialog = ref(null), productName = ref(''), manualPrice = ref(''), formError = ref('')
let previousFocus = null, activeExecution = false
let storage
try { storage = globalThis.localStorage } catch {}
const controller = createTiktokVariantController(omnichannelService, {
  storage, onChange: value => { state.value = value }, onBusy: value => emit('busy', value),
  onRun: run => { emit('run', run); if (activeExecution && ['success', 'partial', 'exists'].includes(run.status)) emit('completed', run) },
  onRemember: id => emit('remembered', id)
})
const result = computed(() => variantRowResult(state.value.run))
const packageTemplate = computed(() => packageTemplateText(state.value.run?.package_template))
const needsPrice = computed(() => state.value.run?.required_fields?.some(field => field.key === 'manual_price'))
const rupiah = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value)
const statusLabel = status => ({ updated: 'Terverifikasi', under_review: 'Menunggu peninjauan', submitted_unverified: 'Belum terverifikasi', failed: 'Gagal', skipped: 'Dilewati' })[status] || status
watch(() => state.value.run, run => { manualPrice.value = String(run?.context?.manual_price || ''); formError.value = '' })
async function execute(action) {
  if (state.value.busy || props.disabled) return
  activeExecution = true
  try { return await action() } finally { activeExecution = false }
}
async function request(item, inspect = false) {
  if (props.disabled || state.value.busy) return
  previousFocus = globalThis.document?.activeElement
  productName.value = item.nama || ''; formError.value = ''; open.value = true
  nextTick(() => dialog.value?.focus())
  return inspect ? controller.recover(String(item.item_id)) : execute(() => controller.start(String(item.item_id)))
}
function retry() {
  if (state.value.busy || props.disabled) return
  try {
    const context = needsPrice.value ? variantPriceContext(manualPrice.value) : state.value.run?.context || {}
    formError.value = ''; return execute(() => controller.retry(context))
  } catch (error) { formError.value = error.message }
}
function close() { if (!state.value.busy) { open.value = false; previousFocus?.focus?.() } }
function trapFocus(event) {
  const controls = Array.from(dialog.value?.querySelectorAll?.('button:not(:disabled), input:not(:disabled)') || [])
  if (!controls.length) { event.preventDefault(); return }
  const first = controls[0], last = controls.at(-1), current = globalThis.document?.activeElement
  if (event.shiftKey && (current === first || current === dialog.value)) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && (current === last || current === dialog.value)) { event.preventDefault(); first.focus() }
}
defineExpose({ request })
onMounted(() => controller.restore())
onBeforeUnmount(() => controller.stop())
</script>

<style scoped>
.variant-backdrop { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 18px; background: #0f172a80; }
.variant-dialog { width: min(620px, 100%); max-height: 90vh; overflow: auto; padding: 22px; border-radius: 10px; background: #fff; color: #334155; box-shadow: 0 12px 40px #0f172a40; }
h2 { margin: 0 0 12px; font-size: 20px; } p, li { font-size: 13px; line-height: 1.6; } .source-note, small { color: #64748b; } [role=alert] { color: #b91c1c; }
.variant-results { display: grid; gap: 10px; list-style: none; padding: 0; } .variant-results li { display: grid; gap: 3px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; overflow-wrap: anywhere; }
form, label { display: grid; gap: 8px; margin: 12px 0; font-size: 13px; } input { padding: 9px; border: 1px solid #cbd5e1; border-radius: 5px; font: inherit; }
.variant-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
button { padding: 9px 12px; border: 1px solid #94a3b8; border-radius: 6px; background: #f8fafc; color: #0f172a; cursor: pointer; } button:disabled { opacity: .5; cursor: not-allowed; }
</style>

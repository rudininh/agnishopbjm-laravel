<template>
  <div v-if="open" class="variant-backdrop" @click.self="close" @keydown.esc="close">
    <section ref="dialog" class="variant-dialog" role="dialog" aria-modal="true" aria-labelledby="tiktok-variant-title" tabindex="-1" @keydown.tab="trapFocus">
      <h2 id="tiktok-variant-title">Varian TikTok Agni</h2>
      <p>{{ productName }}</p>
      <p class="source-note">SKU, gambar, nama varian, dan stok varian baru mengikuti Agni. Harga memakai harga mayoritas produk TikTok.</p>
      <p v-if="checkingMarketplace" role="status" aria-live="polite">Mengambil data TikTok terbaru dan memeriksa hasil tersimpan…</p>
      <div v-if="latestVerification" aria-live="polite">
        <p>Pemeriksaan terakhir: <time :datetime="latestVerification.checked_at">{{ checkedTime(latestVerification.checked_at) }}</time></p>
        <p v-if="Number.isInteger(latestVerification.actual_count)">TikTok: {{ latestVerification.actual_count }} varian · Kiriman tersimpan: {{ latestVerification.expected_count }} varian.</p>
        <p v-if="latestVerification.missing_count">Belum ditemukan: {{ latestVerification.missing_count }} varian.</p>
        <p><strong>{{ verificationConclusion }}</strong></p>
      </div>
      <p v-if="displayError || formError" role="alert">{{ formError || displayError }}</p>
      <div v-if="normalizationVisible" aria-live="polite">
        <p><strong>{{ agniSkuVerified(normalization.run) ? 'SKU Agni terverifikasi' : 'Normalisasi SKU Agni' }}</strong><br />{{ normalization.run?.message }}</p>
        <p v-if="normalization.run">{{ normalizationVerifiedCount }}/{{ normalization.run.result?.changed?.length || 0 }} SKU terverifikasi · {{ normalizationQueuedCount }} menunggu giliran</p>
        <p v-if="normalizationBusy" role="status">Memeriksa hasil SKU tersimpan dan memproses normalisasi Agni. Progres diperbarui setiap langkah…</p>
        <p v-if="normalization.error" role="alert">{{ normalization.error }}</p>
        <ul v-if="normalization.run?.result?.changed?.length" class="variant-results">
          <li v-for="variant in normalization.run.result.changed" :key="variant.seller_sku">
            <strong>{{ variant.name }}</strong><small>{{ variant.seller_sku }}</small><span>{{ ({ verified: 'Terverifikasi', queued: 'Menunggu giliran', submitted_unverified: 'Kiriman lama perlu diperiksa', rejected: 'Ditolak Shopee' })[variant.status] || variant.status }}</span>
          </li>
        </ul>
        <button v-if="normalization.run && !agniSkuVerified(normalization.run) && !normalization.pendingKey" :disabled="busy || disabled" @click="continueNormalization">Periksa dan Lanjutkan SKU Agni</button>
        <button v-if="normalization.pendingKey" :disabled="busy || disabled" @click="retryNormalizationPending">Ulangi Permintaan SKU yang Sama</button>
      </div>
      <template v-if="showRepair">
        <p v-if="repair.run"><strong>{{ tiktokVariantRepairLabel(repair.run) }}</strong><br />{{ repair.run.message }}</p>
        <p v-if="repair.run?.can_continue" aria-live="polite">
          {{ repair.run.progress?.scanned_products || 0 }} produk diperiksa ·
          {{ repair.run.progress?.uploaded_images || 0 }}/{{ repair.run.progress?.total_images || 0 }} gambar perbaikan diproses
        </p>
        <template v-if="repair.run?.result">
          <p v-if="repairVerified">{{ repair.run.result.changed?.length || 0 }} varian diperbaiki · {{ repair.run.result.deleted?.length || 0 }} varian dihapus · {{ repair.run.result.missing_count || 0 }} varian masih kurang</p>
          <p v-else>Rencana: {{ repair.run.result.changed?.length || 0 }} varian akan diperbaiki · {{ repair.run.result.deleted?.length || 0 }} varian akan dihapus · {{ repair.run.result.missing_count || 0 }} varian masih kurang</p>
          <ul class="variant-results">
            <li v-for="variant in repair.run.result.changed" :key="`changed-${variant.seller_sku}`">
              <strong>{{ variant.name }}</strong><small v-if="variant.old_name">Sebelumnya: {{ variant.old_name }}</small><small>{{ variant.seller_sku }}</small><span>{{ repairVerified ? 'Diperbaiki mengikuti Agni' : 'Rencana perbaikan mengikuti Agni' }}</span>
            </li>
            <li v-for="variant in repair.run.result.deleted" :key="`deleted-${variant.seller_sku}`">
              <strong>{{ variant.name }}</strong><small>{{ variant.seller_sku }}</small><span>{{ repairVerified ? 'Dihapus karena sudah tidak ada di Agni' : 'Rencana penghapusan: sudah tidak ada di Agni' }}</span>
            </li>
          </ul>
        </template>
        <p v-if="repair.pendingKey && !repair.prerequisite">Respons perbaikan belum diterima. Muat status tersimpan sebelum melanjutkan.</p>
      </template>
      <template v-else-if="additionVisible && state.run">
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
            <span>{{ variantStatusLabel(variant) }}</span>
            <small v-if="variant.reason">{{ variant.reason }}</small>
          </li>
        </ul>
        <form v-if="state.run.can_retry && ['blocked', 'rejected'].includes(state.run.status)" @submit.prevent="retry">
          <label v-if="needsPrice">Harga varian baru (Rp)
            <input v-model="manualPrice" type="number" min="1" step="1" inputmode="numeric" :disabled="busy || disabled" />
          </label>
          <button type="submit" :disabled="busy || disabled">{{ needsPrice ? 'Lengkapi dan lanjutkan' : 'Coba Lagi' }}</button>
        </form>
      </template>
      <p v-else-if="additionVisible && state.pendingKey">Respons permintaan belum diterima. Muat status tersimpan sebelum melanjutkan.</p>
      <div v-if="repairAvailable">
        <p class="source-note">SKU dan nama mengikuti Agni. Varian TikTok yang terbukti sudah tidak ada di Agni dapat dihapus. Harga dan stok varian yang dipertahankan tetap memakai data TikTok.</p>
        <p v-if="state.run?.repair_check_available" class="source-note">Data terbaru akan diperiksa sebelum perbaikan; percobaan lama belum memastikan konflik identitas.</p>
        <button :disabled="busy || disabled" @click="startRepair">Perbaiki Mengikuti Agni</button>
      </div>
      <div class="variant-actions">
        <template v-if="showRepair">
          <button v-if="repair.run?.can_continue" :disabled="busy || disabled" @click="execute(() => repairController.resume())">Lanjutkan Proses</button>
          <button v-if="['submitted_unverified', 'under_review'].includes(repair.run?.status) && repair.run.remote_product_id" :disabled="busy || disabled" @click="checkMarketplaceStatus(() => execute(() => repairController.checkStatus()))">Periksa Status</button>
          <button v-if="repair.run?.can_retry && ['blocked', 'rejected'].includes(repair.run.status) && !repair.pendingKey" :disabled="busy || disabled" @click="execute(() => repairController.retry())">Coba Lagi Perbaikan</button>
          <button v-if="repair.pendingKey && !repair.prerequisite" :disabled="busy || disabled" @click="execute(() => repairController.retryPending())">Ulangi Permintaan yang Sama</button>
          <button v-if="repairVerified && !repair.error && !repair.pendingKey" :disabled="busy || disabled" @click="continueAddition">Lanjutkan Tambah Varian</button>
        </template>
        <template v-else-if="additionVisible">
          <button v-if="state.run?.can_add_missing && !state.pendingKey" :disabled="busy || disabled" @click="additionAction(() => controller.retryMissing())">Tambahkan Varian yang Belum Ada</button>
          <button v-if="state.run?.can_continue" :disabled="busy || disabled" @click="additionAction(() => controller.resume())">Lanjutkan Proses</button>
          <button v-if="['submitted_unverified', 'under_review'].includes(state.run?.status) && state.run.remote_product_id" :disabled="busy || disabled" @click="checkMarketplaceStatus(() => additionAction(() => controller.checkStatus()))">Periksa Status</button>
          <button v-if="state.pendingKey" :disabled="busy || disabled" @click="additionAction(() => controller.retryPending())">Ulangi Permintaan yang Sama</button>
        </template>
        <button :disabled="busy || disabled" @click="execute(recoverBoth)">Muat Status Tersimpan</button>
        <button :disabled="busy" @click="close">Tutup</button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { omnichannelService } from '@/services'
import { createTiktokVariantController, variantPriceContext, variantRowResult } from '@/pages/stockHubTiktokVariantState'
import { createTiktokVariantRepairController, tiktokVariantRepairLabel, tiktokVariantRepairVerified } from '@/pages/stockHubTiktokVariantRepairState'
import { packageTemplateText } from '@/pages/stockHubPackageTemplate'
import { createAgniSkuController, agniSkuVerified } from '@/pages/stockHubAgniSkuState'

const props = defineProps({ disabled: Boolean })
const emit = defineEmits(['busy', 'run', 'remembered', 'completed'])
const emptyState = () => ({ busy: false, run: null, pendingKey: '', error: '', sourceId: '', paused: false })
const state = ref(emptyState()), repairState = ref(emptyState())
const normalizationState = ref(emptyState()), normalizationBusy = ref(false)
const open = ref(false), dialog = ref(null), productName = ref(''), manualPrice = ref(''), formError = ref('')
const sourceId = ref(''), continuedRepairId = ref(''), flowBusy = ref(false), additionBusy = ref(false), repairBusy = ref(false)
const checkingMarketplace = ref(false)
const busy = computed(() => flowBusy.value || additionBusy.value || repairBusy.value || normalizationBusy.value)
const repairEnabled = typeof omnichannelService.tiktokVariantRepairSource === 'function'
const normalizationEnabled = typeof omnichannelService.agniSkuSource === 'function'
let previousFocus = null, activeExecution = false, alive = true, restoration = Promise.resolve()
let storage
try { storage = globalThis.localStorage } catch {}
function publishBusy() { if (alive) emit('busy', busy.value) }
function setFlowBusy(value) { flowBusy.value = value; publishBusy() }
const controller = createTiktokVariantController(omnichannelService, {
  storage, onChange: value => { state.value = value }, onBusy: value => { additionBusy.value = value; publishBusy() },
  onRun: run => { emit('run', run); if (activeExecution && ['success', 'partial', 'exists'].includes(run.status)) emit('completed', run) },
  onRemember: id => emit('remembered', id)
})
const repairController = createTiktokVariantRepairController(omnichannelService, {
  storage, onChange: value => { repairState.value = value }, onBusy: value => { repairBusy.value = value; publishBusy() },
  onRun: run => { emit('remembered', String(run.source_product_id)); if (activeExecution && tiktokVariantRepairVerified(run)) emit('completed', run) },
  onRemember: id => emit('remembered', id)
})
const normalizationController = createAgniSkuController(omnichannelService, {
  storage, onChange: value => { normalizationState.value = value },
  onBusy: value => { normalizationBusy.value = value; publishBusy() }
})
const normalization = computed(() => normalizationState.value.sourceId === sourceId.value ? normalizationState.value : emptyState())
const normalizationVisible = computed(() => normalizationEnabled && Boolean(normalization.value.run || normalization.value.error || normalization.value.pendingKey))
const normalizationBlocked = computed(() => normalizationEnabled && Boolean(normalization.value.error || normalization.value.pendingKey || (normalization.value.run && !agniSkuVerified(normalization.value.run))))
const normalizationVerifiedCount = computed(() => normalization.value.run?.result?.changed?.filter(row => row.status === 'verified').length || 0)
const normalizationQueuedCount = computed(() => normalization.value.run?.result?.changed?.filter(row => row.status === 'queued').length || 0)
const repair = computed(() => repairState.value.sourceId === sourceId.value ? repairState.value : emptyState())
const additionVisible = computed(() => state.value.sourceId === sourceId.value)
const repairVerified = computed(() => tiktokVariantRepairVerified(repair.value.run))
const showRepair = computed(() => Boolean(repair.value.error || repair.value.pendingKey || (repair.value.run && (continuedRepairId.value !== repair.value.run.run_id || !repairVerified.value))))
const displayError = computed(() => repair.value.error || (additionVisible.value && (!showRepair.value || (repairVerified.value && !repair.value.pendingKey)) ? state.value.error : ''))
const repairAvailable = computed(() => repairEnabled && !normalizationBlocked.value && additionVisible.value && !showRepair.value && (!repair.value.run || repairVerified.value) && !state.value.pendingKey
  && state.value.run?.status === 'blocked' && state.value.run?.can_retry
  && (state.value.run.repair_available === true || state.value.run.repair_check_available === true))
const result = computed(() => variantRowResult(state.value.run))
const latestVerification = computed(() => showRepair.value ? repair.value.run?.verification : (additionVisible.value ? state.value.run?.verification : null))
const verificationConclusion = computed(() => ({ verified: 'Pemeriksaan kiriman selesai dan terverifikasi di TikTok.',
  partial: 'Sebagian varian terverifikasi; periksa varian yang gagal.',
  under_review: 'Belum selesai; menunggu peninjauan TikTok.', mismatch: 'Belum selesai; data TikTok terbaru belum sesuai kiriman.',
  unavailable: 'Belum selesai; data terbaru belum dapat diperiksa.' })[latestVerification.value?.outcome] || 'Hasil pemeriksaan belum dapat dipastikan.')
function checkedTime(value) { const date = new Date(value); return Number.isNaN(date.getTime()) ? 'Waktu belum tersedia' : new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'medium' }).format(date) }
function variantStatusLabel(variant) {
  const verification = latestVerification.value
  if (verification?.missing_variants?.some(row => row.seller_sku === variant.seller_sku)) return 'Belum ditemukan di TikTok'
  if (['mismatch', 'unavailable'].includes(verification?.outcome) && variant.status === 'under_review') return 'Status terbaru belum terverifikasi'
  return statusLabel(variant.status)
}
async function checkMarketplaceStatus(action) {
  if (busy.value || props.disabled) return
  checkingMarketplace.value = true
  try { return await action() } finally { checkingMarketplace.value = false }
}
const packageTemplate = computed(() => packageTemplateText(state.value.run?.package_template))
const needsPrice = computed(() => state.value.run?.required_fields?.some(field => field.key === 'manual_price'))
const rupiah = value => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value)
const statusLabel = status => ({ updated: 'Terverifikasi', under_review: 'Menunggu peninjauan', submitted_unverified: 'Belum terverifikasi', failed: 'Gagal', skipped: 'Dilewati' })[status] || status
watch(() => state.value.run, run => { manualPrice.value = String(run?.context?.manual_price || ''); formError.value = '' })
async function execute(action) {
  if (!alive || busy.value || props.disabled) return
  activeExecution = true; setFlowBusy(true)
  try { return await action() } finally { activeExecution = false; setFlowBusy(false) }
}
async function recoverRepair() {
  if (repairEnabled) await repairController.recover(sourceId.value)
  if (normalizationEnabled) await normalizationController.recover(sourceId.value)
  return alive && !repair.value.error && !repair.value.pendingKey && !normalizationBlocked.value
}
function retryNormalizationPending() {
  return execute(async () => {
    await normalizationController.retryPending()
    if (alive && agniSkuVerified(normalization.value.run) && !normalization.value.error && !normalization.value.pendingKey) await recoverBoth()
  })
}
async function continueNormalization() {
  return execute(async () => {
    await normalizationController.recover(sourceId.value)
    if (!alive || normalization.value.error || normalization.value.pendingKey) return
    for (let step = 0; alive && step < 1000; step++) {
      const run = normalization.value.run
      if (!run || agniSkuVerified(run)) break
      if (run.status === 'submitted_unverified' || (run.result?.changed?.some(row => ['verified', 'submitted_unverified'].includes(row.status)) && !run.can_retry)) {
        await normalizationController.checkStatus()
        if (!alive || normalization.value.error || !['preparing', 'submitting', 'verifying', 'reconciled'].includes(normalization.value.run?.status)) break
      }
      const current = normalization.value.run
      if (current?.status === 'reconciled') await normalizationController.start(sourceId.value)
      else if (current?.can_retry && ['blocked', 'rejected'].includes(current.status)) await normalizationController.retry()
      else if (current?.can_continue) await normalizationController.resume()
      if (!alive || normalization.value.error || normalization.value.pendingKey || normalization.value.run?.status !== 'submitted_unverified') break
    }
    if (alive && agniSkuVerified(normalization.value.run) && !normalization.value.error) await recoverBoth()
  })
}
async function recoverBoth() {
  if (!await recoverRepair()) return
  await controller.recover(sourceId.value)
  // An active/accepted addition can only follow a resolved repair; its status remains independently recoverable.
  if (repairVerified.value && !state.value.error && (state.value.run?.can_continue
    || ['under_review', 'submitted_unverified', 'success', 'partial', 'exists'].includes(state.value.run?.status))) {
    continuedRepairId.value = repair.value.run.run_id
  }
}
async function request(item, inspect = false) {
  await restoration
  if (!alive || props.disabled || busy.value) return
  previousFocus = globalThis.document?.activeElement
  sourceId.value = String(item.item_id); continuedRepairId.value = ''
  productName.value = item.nama || ''; formError.value = ''; open.value = true
  nextTick(() => dialog.value?.focus())
  return execute(async () => {
    await recoverBoth()
    if (!alive || normalizationBlocked.value || repair.value.error || repair.value.pendingKey || state.value.error) return
    if (!inspect && !showRepair.value) await controller.start(sourceId.value)
  })
}
function additionAction(action) {
  return execute(async () => { if (await recoverRepair() && !showRepair.value) return action() })
}
function startRepair() {
  if (!repairAvailable.value) return
  return execute(() => { continuedRepairId.value = ''; return repairController.start(sourceId.value) })
}
function continueAddition() {
  if (!repairVerified.value) return
  return execute(async () => {
    if (!await recoverRepair() || !repairVerified.value) return
    await controller.recover(sourceId.value)
    if (!alive || state.value.error) return
    continuedRepairId.value = repair.value.run.run_id
    if (['blocked', 'rejected'].includes(state.value.run?.status) && state.value.run.can_retry) {
      await controller.retry(state.value.run.context || {})
    } else await controller.start(sourceId.value)
  })
}
function retry() {
  if (busy.value || props.disabled || showRepair.value) return
  try {
    const context = needsPrice.value ? variantPriceContext(manualPrice.value) : state.value.run?.context || {}
    formError.value = ''; return additionAction(() => controller.retry(context))
  } catch (error) { formError.value = error.message }
}
function close() { if (!busy.value) { open.value = false; previousFocus?.focus?.() } }
function trapFocus(event) {
  const controls = Array.from(dialog.value?.querySelectorAll?.('button:not(:disabled), input:not(:disabled)') || [])
  if (!controls.length) { event.preventDefault(); return }
  const first = controls[0], last = controls.at(-1), current = globalThis.document?.activeElement
  if (event.shiftKey && (current === first || current === dialog.value)) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && (current === last || current === dialog.value)) { event.preventDefault(); first.focus() }
}
defineExpose({ request })
onMounted(() => {
  setFlowBusy(true)
  restoration = (async () => {
    try { if (repairEnabled) await repairController.restore(); if (alive) await controller.restore() }
    finally { setFlowBusy(false) }
  })()
})
onBeforeUnmount(() => { alive = false; controller.stop(); repairController.stop(); normalizationController.stop(); emit('busy', false) })
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

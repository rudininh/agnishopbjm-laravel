<template>
  <div v-if="open" class="batch-backdrop" @click.self="close" @keydown.esc="close">
    <section ref="dialog" class="batch-dialog" role="dialog" aria-modal="true" aria-labelledby="tiktok-batch-title" tabindex="-1" @keydown.tab="trapFocus">
      <h2 id="tiktok-batch-title">Tambah Semua Varian TikTok</h2>
      <p>Seluruh katalog Agni, termasuk stok kosong dan produk tidak live, diproses tanpa mengikuti filter atau halaman. Tujuan: produk TikTok Agni yang sudah ada.</p>
      <p v-if="state.error" role="alert">{{ state.error }}</p>
      <template v-if="state.run">
        <p v-if="packageTemplate">Template paket bersama: {{ packageTemplate }}. Data kosong atau nol dilengkapi otomatis dari Agni, lalu template. Data yang sudah valid tetap dipakai.</p>
        <p aria-live="polite"><strong>{{ runLabel }}</strong><br />{{ state.run.message }}</p>
        <p>{{ summary.products_processed }}/{{ summary.products_total }} produk diproses<span v-if="currentProduct"> · Saat ini: {{ currentProduct.name }}</span></p>
        <dl class="batch-summary">
          <div><dt>Varian terverifikasi</dt><dd>{{ summary.variants_verified }}</dd></div>
          <div><dt>Varian menunggu hasil</dt><dd>{{ summary.variants_pending }}</dd></div>
          <div><dt>Varian gagal</dt><dd>{{ summary.variants_failed }}</dd></div>
          <div><dt>Varian dilewati</dt><dd>{{ summary.variants_skipped }}</dd></div>
          <div><dt>Produk perlu perhatian</dt><dd>{{ summary.products_need_attention }}</dd></div>
        </dl>
        <ul class="batch-results">
          <li v-for="item in visibleItems" :key="item.source_product_id">
            <strong>{{ item.name }}</strong> <small>Agni #{{ item.source_product_id }}</small>
            <span :class="{ attention: needsAttention(item) }">{{ statusLabel(item.status) }}</span>
            <p>{{ item.message }}</p>
            <details v-if="item.result?.variants?.length">
              <summary>Hasil varian ({{ item.result.variants.length }})</summary>
              <ul><li v-for="(variant, index) in item.result.variants" :key="variant.source_variant_id || variant.seller_sku || index">
                {{ variant.name || variant.variant_name || variant.seller_sku }} · {{ variant.seller_sku }} · {{ statusLabel(variant.status) }}
                <small v-if="variant.reason">{{ variant.reason }}</small>
              </li></ul>
            </details>
            <button v-if="needsAttention(item)" :disabled="state.busy || disabled" @click="inspect(item)">Periksa Produk</button>
          </li>
        </ul>
        <nav v-if="pageCount > 1" aria-label="Halaman hasil katalog">
          <button :disabled="page === 1" @click="page--">Sebelumnya</button>
          <span>{{ page }}/{{ pageCount }}</span>
          <button :disabled="page === pageCount" @click="page++">Berikutnya</button>
        </nav>
      </template>
      <p v-else-if="state.pendingKey">Respons permintaan belum diterima. Muat status atau ulangi permintaan yang sama secara eksplisit.</p>
      <div class="batch-actions">
        <button v-if="state.busy && !state.paused && state.run?.status === 'running'" :disabled="disabled" @click="controller.pause()">Jeda</button>
        <button v-if="!state.busy && state.run && state.run.status !== 'completed'" :disabled="disabled" @click="execute(() => controller.resume())">Lanjutkan</button>
        <button v-if="state.pendingKey" :disabled="state.busy || disabled" @click="execute(() => controller.start())">Ulangi Permintaan yang Sama</button>
        <button :disabled="state.busy" @click="controller.recover()">Muat Status Tersimpan</button>
        <button :disabled="state.busy" @click="close">Tutup</button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { omnichannelService } from '@/services'
import { createTiktokVariantBatchController } from '@/pages/stockHubTiktokVariantBatchState'
import { packageTemplateText } from '@/pages/stockHubPackageTemplate'

const props = defineProps({ disabled: Boolean })
const emit = defineEmits(['busy', 'run', 'completed', 'inspect-product'])
const state = ref({ busy: false, run: null, pendingKey: '', error: '', paused: true })
const open = ref(false), dialog = ref(null), page = ref(1)
let previousFocus = null, activeExecution = false
const completedNotified = new Set()
let storage
try { storage = globalThis.localStorage } catch {}
const controller = createTiktokVariantBatchController(omnichannelService, {
  storage, onChange: value => { state.value = value }, onBusy: value => emit('busy', value),
  onRun: run => {
    emit('run', run)
    if (activeExecution && run.status === 'completed' && !completedNotified.has(run.run_id)) { completedNotified.add(run.run_id); emit('completed', run) }
  }
})
const summary = computed(() => state.value.run?.summary || {})
const packageTemplate = computed(() => packageTemplateText(state.value.run?.package_template))
const currentProduct = computed(() => state.value.run?.items.find(item => item.source_product_id === state.value.run.current_source_product_id))
const pageCount = computed(() => Math.max(1, Math.ceil((state.value.run?.items.length || 0) / 20)))
const visibleItems = computed(() => state.value.run?.items.slice((page.value - 1) * 20, page.value * 20) || [])
const runLabel = computed(() => state.value.run?.status === 'completed'
  ? (summary.value.products_need_attention || summary.value.variants_pending || summary.value.variants_failed ? 'Selesai · masih ada hasil perlu perhatian' : 'Selesai')
  : ({ running: state.value.paused ? 'Progres tersimpan · lanjutkan secara eksplisit' : 'Memproses katalog', paused: 'Dijeda', pausing: 'Menunggu proses berjalan selesai sebelum jeda', paused_error: 'Dijeda · perlu pemeriksaan' })[state.value.run?.status])
const statusLabel = status => ({ queued: 'Menunggu giliran', preparing: 'Menyiapkan', scanning: 'Memeriksa SKU', uploading: 'Mengunggah gambar', submitting: 'Mengirim varian', verifying: 'Memverifikasi', blocked: 'Perlu dilengkapi', rejected: 'Ditolak TikTok', success: 'Terverifikasi', partial: 'Sebagian terverifikasi', exists: 'Varian sudah ada', submitted_unverified: 'Terkirim, belum terverifikasi', under_review: 'Menunggu peninjauan', skipped_complete: 'Lengkap · dilewati', missing_product: 'Perlu membuat produk TikTok', unknown: 'Hubungan produk perlu diperiksa', updated: 'Terverifikasi', failed: 'Gagal', skipped: 'Dilewati' })[status] || status
const needsAttention = item => ['blocked', 'rejected', 'partial', 'submitted_unverified', 'under_review', 'missing_product', 'unknown'].includes(item.status)
async function execute(action) {
  if (state.value.busy || props.disabled) return
  activeExecution = true
  try { return await action() } finally { activeExecution = false }
}
async function request() {
  if (state.value.busy || props.disabled) return
  previousFocus = globalThis.document?.activeElement; open.value = true; page.value = 1
  nextTick(() => dialog.value?.focus())
  return execute(() => controller.start())
}
async function inspect(item) {
  if (state.value.busy || props.disabled) return
  await controller.pause()
  if (state.value.error) return
  open.value = false
  await nextTick()
  emit('inspect-product', { item_id: item.source_product_id, nama: item.name })
}
function close() { if (!state.value.busy) { open.value = false; previousFocus?.focus?.() } }
function trapFocus(event) {
  const controls = Array.from(dialog.value?.querySelectorAll?.('button:not(:disabled), summary, [tabindex="0"]') || [])
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
.batch-backdrop { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 18px; background: #0f172a80; }
.batch-dialog { width: min(760px, 100%); max-height: 90vh; overflow: auto; padding: 22px; border-radius: 10px; background: #fff; color: #334155; box-shadow: 0 12px 40px #0f172a40; }
h2 { margin: 0 0 12px; font-size: 20px; } p, li, dl, nav { font-size: 13px; line-height: 1.6; } small { color: #64748b; } [role=alert], .attention { color: #b91c1c; }
.batch-summary { display: flex; flex-wrap: wrap; gap: 16px; } dd { margin: 0; font-size: 20px; font-weight: 700; }
.batch-results { display: grid; gap: 12px; list-style: none; padding: 0; } .batch-results > li { display: grid; gap: 4px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; overflow-wrap: anywhere; } .batch-results p { margin: 0; } summary { cursor: pointer; }
.batch-actions, nav { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
button { padding: 9px 12px; border: 1px solid #94a3b8; border-radius: 6px; background: #f8fafc; color: #0f172a; cursor: pointer; } button:disabled { opacity: .5; cursor: not-allowed; }
</style>

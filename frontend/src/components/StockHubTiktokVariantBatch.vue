<template>
  <div v-if="open" class="batch-backdrop" @click.self="close" @keydown.esc="close">
    <section ref="dialog" class="batch-dialog" role="dialog" aria-modal="true" aria-labelledby="tiktok-batch-title" tabindex="-1" @keydown.tab="trapFocus">
      <h2 id="tiktok-batch-title">Tambah Semua Varian TikTok</h2>
      <p>Seluruh katalog Agni, termasuk stok kosong dan produk tidak live, diproses tanpa mengikuti filter atau halaman. Tujuan: produk TikTok Agni yang sudah ada.</p>
      <p>SKU Agni disesuaikan dengan nama varian saat ini, lalu varian TikTok diperbaiki dan varian usang yang memenuhi syarat dihapus. Varian yang belum ada ditambahkan setelah perbaikan terverifikasi.</p>
      <p v-if="state.error" role="alert">{{ state.error }}</p>
      <section v-if="state.run || state.busy" class="batch-live" aria-label="Informasi proses">
          <strong>{{ requestLabel }}</strong>
          <p v-if="state.requestStartedAt !== null && state.requestStartedAt !== undefined">Durasi permintaan: {{ elapsedText(state.requestStartedAt, clockNow) }}.</p>
          <p v-if="slowRequest">Respons lebih lama dari biasanya. Hasil belum dapat dipastikan; tunggu respons sebelum melakukan tindakan lain.</p>
          <p v-if="state.lastResponseAt">Respons terakhir: {{ timeText(state.lastResponseAt) }} ({{ elapsedText(state.lastResponseAt, clockNow) }} lalu).</p>
          <p v-if="currentProduct">{{ stageLabel(currentProduct.workflow_stage) }}: {{ currentProduct.message }}<br v-if="progressText(currentProduct)" />{{ progressText(currentProduct) }}</p>
          <p v-if="currentProduct?.status === 'scanning'">Katalog TikTok diperiksa untuk memastikan SKU tidak dipakai produk lain. Jumlah produk Agni selesai bertambah setelah pemeriksaan produk ini selesai.</p>
          <p v-if="currentProduct?.updated_at">Progres server tersimpan: {{ timeText(currentProduct.updated_at) }}.</p>
          <p v-if="state.run?.status === 'running' && !state.paused">Proses dijalankan halaman ini. Biarkan halaman tetap terbuka. Informasi diperbarui setiap respons; durasi diperbarui setiap detik.</p>
          <p v-else-if="state.run?.status === 'running' && !state.requestKind">Halaman ini belum menjalankan proses. Status server tersimpan; gunakan Lanjutkan untuk memeriksa dan melanjutkan secara eksplisit.</p>
        </section>
      <template v-if="state.run">
        <p v-if="legacy">Alur lama: hasil penambahan tetap tersimpan. Tingkatkan dan Lanjutkan untuk memeriksa proses tersimpan, menyesuaikan SKU Agni, memperbaiki TikTok, lalu menambah varian.</p>
        <p v-if="packageTemplate">Template paket bersama: {{ packageTemplate }}. Data kosong atau nol dilengkapi otomatis dari Agni, lalu template. Data yang sudah valid tetap dipakai.</p>
        <p aria-live="polite"><strong>{{ runLabel }}</strong><br />{{ state.run.message }}</p>
        <p>{{ summary.products_processed }}/{{ summary.products_total }} produk diproses<span v-if="currentProduct"> · Saat ini: {{ currentProduct.name }}</span></p>

        <details v-if="state.activities?.length" class="batch-activity" open>
          <summary>Riwayat aktivitas ({{ state.activities.length }} terakhir selama halaman terbuka)</summary>
          <ol><li v-for="(activity, index) in state.activities" :key="index"><time>{{ timeText(activity.at) }}</time> · {{ activity.message }}</li></ol>
        </details>
        <dl class="batch-summary">
          <template v-if="!legacy">
            <div><dt>SKU Agni terverifikasi</dt><dd>{{ summary.agni_skus_verified ?? 0 }}</dd></div>
            <div><dt>Perbaikan TikTok terverifikasi</dt><dd>{{ summary.tiktok_variants_repaired ?? 0 }}</dd></div>
            <div><dt>Penghapusan TikTok terverifikasi</dt><dd>{{ summary.tiktok_variants_removed ?? 0 }}</dd></div>
            <div><dt>Produk menunggu peninjauan</dt><dd>{{ summary.products_waiting_review ?? 0 }}</dd></div>
          </template>
          <div><dt>Varian terverifikasi</dt><dd>{{ summary.variants_verified }}</dd></div>
          <div><dt>Varian menunggu hasil</dt><dd>{{ summary.variants_pending }}</dd></div>
          <div><dt>Varian gagal</dt><dd>{{ summary.variants_failed }}</dd></div>
          <div><dt>Varian dilewati</dt><dd>{{ summary.variants_skipped }}</dd></div>
          <div><dt>Produk perlu perhatian</dt><dd>{{ summary.products_need_attention }}</dd></div>
        </dl>
        <ul class="batch-results">
          <li v-for="item in visibleItems" :key="item.source_product_id">
            <strong>{{ item.name }}</strong> <small>Agni #{{ item.source_product_id }}</small>
            <span :class="{ attention: needsAttention(item) }">{{ itemStatus(item) }}</span>
            <p v-if="!legacy">Tahap: {{ stageLabel(item.workflow_stage) }}</p>
            <p>{{ item.message }}</p>
            <p v-if="progressText(item)">{{ progressText(item) }}</p>
            <ol v-if="!legacy" class="batch-stages">
              <li>
                <strong>Normalisasi SKU Agni</strong> · {{ stageStatus(item.source_sku_status, 'Shopee') }}
                <ul v-if="item.source_sku_result?.changed?.length"><li v-for="(change, index) in item.source_sku_result.changed" :key="index">
                  {{ change.name }} · {{ change.old_sku || '(kosong)' }} → {{ change.seller_sku }} · {{ sourceChangeLabel(change.status) }}
                </li></ul>
              </li>
              <li>
                <strong>Perbaikan TikTok</strong> · {{ stageStatus(item.repair_status) }}
                <template v-if="item.repair_result">
                  <p v-if="item.repair_result.changed?.length">{{ verifiedStage(item.repair_status) ? 'Perubahan terverifikasi' : 'Perubahan terencana · menunggu verifikasi' }}</p>
                  <ul v-if="item.repair_result.changed?.length"><li v-for="(change, index) in item.repair_result.changed" :key="index">
                    {{ change.old_name || change.name }} → {{ change.name }} · {{ change.seller_sku }}
                  </li></ul>
                  <p v-if="item.repair_result.deleted?.length">{{ verifiedStage(item.repair_status) ? 'Penghapusan terverifikasi' : 'Penghapusan terencana · menunggu verifikasi' }}</p>
                  <ul v-if="item.repair_result.deleted?.length"><li v-for="(deleted, index) in item.repair_result.deleted" :key="index">{{ deleted.name }} · {{ deleted.seller_sku }}</li></ul>
                </template>
              </li>
              <li><strong>Penambahan varian TikTok</strong> · {{ additionStatus(item) }}</li>
            </ol>
            <details v-if="item.result?.variants?.length">
              <summary>Hasil penambahan varian ({{ item.result.variants.length }})</summary>
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
        <button v-if="!state.busy && state.run && (legacy || state.run.can_resume_attention || state.run.status !== 'completed')" :disabled="disabled" @click="execute(() => controller.resume())">{{ legacy ? 'Tingkatkan dan Lanjutkan' : state.run.can_resume_attention ? 'Periksa dan Lanjutkan' : 'Lanjutkan' }}</button>
        <button v-if="state.pendingKey" :disabled="state.busy || disabled" @click="execute(() => controller.start({ retryPending: true }))">Ulangi Permintaan yang Sama</button>
        <button v-if="state.run?.status === 'completed' && !state.pendingKey" :disabled="state.busy || disabled" @click="execute(() => controller.startFresh())">Mulai Katalog Baru</button>
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
import { batchProgressText, elapsedText } from '@/pages/stockHubTiktokVariantBatchProgress'

const props = defineProps({ disabled: Boolean })
const emit = defineEmits(['busy', 'run', 'completed', 'inspect-product'])
const state = ref({ busy: false, run: null, pendingKey: '', error: '', paused: true })
const open = ref(false), dialog = ref(null), page = ref(1)
const clockNow = ref(Date.now())
let clockTimer
const progressText = batchProgressText
const timeText = value => {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Belum diketahui' : date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}
const requestLabel = computed(() => ({ step: 'Menunggu respons proses', start: 'Menyiapkan katalog', resume: 'Memeriksa dan melanjutkan batch', pause: 'Menyimpan permintaan jeda', status: 'Memuat status tersimpan' })[state.value.requestKind]
  || (state.value.run?.status === 'completed' ? 'Pemeriksaan katalog selesai' : state.value.busy && !state.value.paused ? 'Menunggu giliran pemeriksaan berikutnya' : 'Progres tersimpan'))
const slowRequest = computed(() => state.value.requestStartedAt != null && clockNow.value - state.value.requestStartedAt >= 30000)
let previousFocus = null, activeExecution = false
let storage
try { storage = globalThis.localStorage } catch {}
const controller = createTiktokVariantBatchController(omnichannelService, {
  storage, onChange: value => { state.value = value }, onBusy: value => emit('busy', value),
  onRun: (run, { mutated }) => {
    emit('run', run)
    if (activeExecution && mutated && run.status === 'completed') emit('completed', run)
  }
})
const legacy = computed(() => state.value.run?.workflow === 'legacy')
const summary = computed(() => state.value.run?.summary || {})
const packageTemplate = computed(() => packageTemplateText(state.value.run?.package_template))
const currentProduct = computed(() => state.value.run?.items.find(item => item.source_product_id === state.value.run.current_source_product_id))
const pageCount = computed(() => Math.max(1, Math.ceil((state.value.run?.items.length || 0) / 20)))
const visibleItems = computed(() => state.value.run?.items.slice((page.value - 1) * 20, page.value * 20) || [])
const runLabel = computed(() => state.value.run?.status === 'completed'
  ? (summary.value.products_need_attention || summary.value.variants_pending || summary.value.variants_failed ? 'Selesai · masih ada hasil perlu perhatian' : 'Selesai')
  : ({ running: state.value.paused ? 'Progres tersimpan · lanjutkan secara eksplisit' : 'Memproses katalog', paused: 'Dijeda', pausing: 'Menunggu proses berjalan selesai sebelum jeda', paused_error: 'Dijeda · perlu pemeriksaan' })[state.value.run?.status])
const statusLabel = status => ({ queued: 'Menunggu giliran', preparing: 'Menyiapkan', scanning: 'Memeriksa SKU', uploading: 'Mengunggah gambar', submitting: 'Mengirim varian', verifying: 'Memverifikasi', blocked: 'Perlu dilengkapi', rejected: 'Ditolak TikTok', success: 'Terverifikasi', partial: 'Sebagian terverifikasi', exists: 'Varian sudah ada', submitted_unverified: 'Terkirim, belum terverifikasi', under_review: 'Menunggu peninjauan', skipped_complete: 'Lengkap · dilewati', missing_product: 'Perlu membuat produk TikTok', unknown: 'Hubungan produk perlu diperiksa', updated: 'Terverifikasi', failed: 'Gagal', skipped: 'Dilewati' })[status] || status
const verifiedStage = status => ['success', 'exists'].includes(status)
const stageStatus = (status, channel = 'TikTok') => verifiedStage(status) ? 'Terverifikasi' : status === 'rejected' ? `Ditolak ${channel}` : status ? statusLabel(status) : 'Belum diproses'
const sourceChangeLabel = status => ({ verified: 'Terverifikasi', queued: 'Terencana · menunggu verifikasi', submitted_unverified: 'Terkirim, belum terverifikasi', rejected: 'Ditolak Shopee' })[status] || 'Menunggu verifikasi'
const stageLabel = stage => ({ recover: 'Pemulihan proses tersimpan', recover_addition: 'Pemulihan penambahan TikTok', recover_repair: 'Pemulihan perbaikan TikTok', source_sku: 'Normalisasi SKU Agni', repair: 'Perbaikan TikTok', addition: 'Penambahan varian TikTok', completed: 'Tahapan selesai' })[stage] || 'Menunggu giliran'
const itemStatus = item => item.workflow_stage === 'source_sku' ? stageStatus(item.status, 'Shopee') : statusLabel(item.status)
const additionStatus = item => ['addition', 'recover_addition', 'completed'].includes(item.workflow_stage) && item.child_run_id ? statusLabel(item.status) : 'Belum diproses'
const needsAttention = item => ['blocked', 'rejected', 'partial', 'submitted_unverified', 'under_review', 'missing_product', 'unknown', 'failed'].includes(item.status)
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
onMounted(() => { clockTimer = setInterval(() => { clockNow.value = Date.now() }, 1000); controller.restore() })
onBeforeUnmount(() => { clearInterval(clockTimer); controller.stop() })
</script>

<style scoped>
.batch-backdrop { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 18px; background: #0f172a80; }
.batch-dialog { width: min(760px, 100%); max-height: 90vh; overflow: auto; padding: 22px; border-radius: 10px; background: #fff; color: #334155; box-shadow: 0 12px 40px #0f172a40; }
h2 { margin: 0 0 12px; font-size: 20px; } p, li, dl, nav { font-size: 13px; line-height: 1.6; } small { color: #64748b; } [role=alert], .attention { color: #b91c1c; }
.batch-summary { display: flex; flex-wrap: wrap; gap: 16px; } dd { margin: 0; font-size: 20px; font-weight: 700; }
.batch-results { display: grid; gap: 12px; list-style: none; padding: 0; } .batch-results > li { display: grid; gap: 4px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; overflow-wrap: anywhere; } .batch-results p { margin: 0; } summary { cursor: pointer; }
.batch-stages { padding-left: 20px; } .batch-stages > li { margin: 8px 0; }
.batch-live { border: 1px solid #bfdbfe; border-radius: 6px; background: #eff6ff; padding: 12px; margin: 14px 0; }
.batch-live p { margin: 6px 0; } .batch-activity ol { max-height: 180px; overflow: auto; padding-left: 24px; } .batch-activity time { font-variant-numeric: tabular-nums; color: #64748b; }
.batch-actions, nav { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
button { padding: 9px 12px; border: 1px solid #94a3b8; border-radius: 6px; background: #f8fafc; color: #0f172a; cursor: pointer; } button:disabled { opacity: .5; cursor: not-allowed; }
</style>

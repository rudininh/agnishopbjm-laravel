<template>
  <button v-if="supportsCleanup(accountKey)" class="cleanup-trigger" :disabled="disabled || busy" @click="openDialog">
    {{ busy ? 'Memeriksa varian...' : 'Hapus Varian Tidak Ada di Agni' }}
  </button>
  <Teleport to="body">
    <div v-if="open" class="cleanup-overlay" @click.self="closeDialog">
      <section ref="dialog" class="cleanup-dialog" role="dialog" aria-modal="true" aria-labelledby="orphan-cleanup-title" tabindex="-1" @keydown="keyboard">
        <header><div><h2 id="orphan-cleanup-title">Hapus Varian Tidak Ada di Agni</h2><p>Tujuan: {{ accountName }}. Acuan: Shopee AgniShopBJM.</p></div>
          <button :disabled="busy" @click="closeDialog" aria-label="Tutup dialog">Tutup</button></header>
        <p class="cleanup-note">Pemeriksaan mencakup seluruh katalog akun, bukan hanya filter/halaman tabel. SKU salah, identitas ambigu, dan varian terakhir tidak dihapus otomatis.</p>
        <p v-if="busy" role="status">{{ run?.status === 'running' ? 'Memproses dan memverifikasi penghapusan...' : 'Memeriksa katalog terbaru...' }} Produk diperiksa: {{ run?.products_scanned || 0 }}.</p>
        <p v-if="error || run?.message" class="cleanup-error" role="alert">{{ error || run.message }}</p>
        <div v-if="run" class="cleanup-summary">
          <span>Kandidat: {{ run.summary?.eligible || 0 }}</span><span>Dipertahankan: {{ run.summary?.retained || 0 }}</span>
          <span>Diblokir: {{ run.summary?.blocked || 0 }}</span><span>Terhapus: {{ run.summary?.deleted || 0 }}</span>
          <span>Belum terverifikasi: {{ run.summary?.unverified || 0 }}</span><span>Data berubah: {{ run.summary?.stale || 0 }}</span>
        </div>
        <label v-if="run?.items?.length" class="cleanup-filter"><input v-model="showRetained" type="checkbox"> Tampilkan juga varian yang masih ada di Agni</label>
        <div class="cleanup-results">
          <article v-for="group in groups" :key="group.id" class="cleanup-product">
            <h3>{{ group.name || 'Produk' }} <small>ID {{ group.id }}</small></h3>
            <p>Agni: {{ group.sourceName || group.sourceId || 'Belum teridentifikasi' }} <small v-if="group.sourceId">({{ group.sourceId }})</small></p>
            <div v-for="item in group.items" :key="item.item_id" class="cleanup-row">
              <div><strong>{{ item.name || 'Detail produk belum tersedia' }}</strong><code>{{ item.seller_sku || '-' }}</code><small>ID varian: {{ item.variant_id || '-' }}</small></div>
              <div><span class="cleanup-status" :data-status="item.status">{{ labels[item.status] || item.status }}</span><p>{{ item.reason }}</p></div>
              <button class="cleanup-danger" :disabled="busy || !canSubmit(run, [item.item_id], false)" @click="prepare(item.item_id)">Hapus</button>
            </div>
          </article>
          <p v-if="run && !groups.length && !busy">Tidak ada kandidat yang ditampilkan. Varian yang masih ada di Agni tetap dipertahankan.</p>
        </div>
        <div v-if="confirmation.length" class="cleanup-confirm" role="alert">
          <strong>Hapus permanen {{ confirmation.length }} varian dari {{ accountName }}?</strong>
          <p>Hanya varian terpilih yang dihapus di marketplace. Produk sumber Agni dan kuantitas Stock Master tidak diubah. Tindakan ini tidak dapat dibatalkan.</p>
          <button :disabled="busy" @click="confirmation = []">Batal</button>
          <button class="cleanup-danger" :disabled="!canSubmit(run, confirmation, busy)" @click="submit">Ya, Hapus {{ confirmation.length }} Varian</button>
        </div>
        <footer>
          <button :disabled="busy" @click="newPreview">Periksa Ulang Katalog</button>
          <button v-if="run && ['scanning', 'running'].includes(run.status)" :disabled="busy" @click="resume">Muat Status / Lanjutkan</button>
          <button class="cleanup-danger" :disabled="!canSubmit(run, selectionFor(run), busy)" @click="prepare()">Hapus Semua Kandidat Aman ({{ selectionFor(run).length }})</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { omnichannelService as api } from '@/services'
import { supportsCleanup, canSubmit, selectionFor, runCleanupSteps } from '@/pages/orphanVariantCleanupState'

const props = defineProps({ accountKey: String, accountName: String, disabled: Boolean })
const emit = defineEmits(['busy', 'completed'])
const open = ref(false), busy = ref(false), run = ref(null), error = ref(''), confirmation = ref([]), showRetained = ref(false), dialog = ref(null)
let generation = 0, opener = null
const labels = { eligible: 'Kandidat aman', retained: 'Dipertahankan', blocked: 'Diblokir', deleted: 'Terhapus', unverified: 'Belum terverifikasi', stale: 'Preview berubah' }
const storageKey = () => `orphan-cleanup:${props.accountKey}`
function stored(id) { try { if (id) localStorage.setItem(storageKey(), id); else return localStorage.getItem(storageKey()) } catch { return null } }
function update(value) { run.value = value; stored(value.run_id) }
const groups = computed(() => {
  const result = new Map()
  for (const item of run.value?.items || []) {
    if (!showRetained.value && item.status === 'retained') continue
    if (!result.has(item.product_id)) result.set(item.product_id, { id: item.product_id, name: item.product_name, sourceId: item.source_product_id, sourceName: item.source_name, items: [] })
    result.get(item.product_id).items.push(item)
  }
  return [...result.values()]
})
watch(busy, value => emit('busy', value), { flush: 'sync' })
watch(() => props.accountKey, () => { generation++; open.value = false; busy.value = false; run.value = null; confirmation.value = [] })
onBeforeUnmount(() => { generation++; emit('busy', false) })

async function execute(action) {
  if (busy.value) return
  const current = ++generation
  busy.value = true; error.value = ''
  try {
    const response = await action()
    if (current !== generation) return
    update(response.data)
    await runCleanupSteps(api, run.value, update, () => current === generation)
    if (current === generation && run.value.status === 'completed') emit('completed')
  } catch (e) {
    if (current === generation) error.value = e.response?.data?.message || 'Koneksi terputus. Muat status untuk melihat hasil; jangan mengulangi konfirmasi hapus.'
  } finally { if (current === generation) busy.value = false }
}
async function openDialog() {
  opener = document.activeElement; open.value = true; confirmation.value = []
  await nextTick(); dialog.value?.focus()
  const id = stored()
  if (id) await execute(() => api.orphanVariantRun(id, props.accountKey))
  else await newPreview()
}
function closeDialog() { if (busy.value) return; open.value = false; opener?.focus() }
function newPreview() { confirmation.value = []; return execute(() => api.orphanVariantPreview(props.accountKey)) }
function resume() { return execute(() => api.orphanVariantRun(run.value.run_id, props.accountKey)) }
function prepare(id = null) { confirmation.value = selectionFor(run.value, id) }
function submit() {
  if (!canSubmit(run.value, confirmation.value, busy.value)) return
  const ids = [...confirmation.value]; confirmation.value = []
  return execute(() => api.orphanVariantSubmit(run.value.run_id, props.accountKey, run.value.revision, ids))
}
function keyboard(event) {
  if (event.key === 'Escape') closeDialog()
  if (event.key !== 'Tab') return
  const nodes = [...dialog.value.querySelectorAll('button:not(:disabled), input:not(:disabled), [tabindex="0"]')]
  if (!nodes.length) { event.preventDefault(); dialog.value.focus(); return }
  const first = nodes[0], last = nodes[nodes.length - 1]
  if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
}
</script>

<style scoped>
.cleanup-trigger,.cleanup-dialog button { border:1px solid #cbd5e1; border-radius:7px; background:#fff; padding:9px 12px; cursor:pointer; font:inherit; font-size:13px; }
.cleanup-trigger,.cleanup-dialog .cleanup-danger { color:#b91c1c; border-color:#fecaca; background:#fff7f7; }
button:disabled { opacity:.5; cursor:not-allowed; }
.cleanup-overlay { position:fixed; inset:0; background:#0f172a99; z-index:1200; display:flex; align-items:center; justify-content:center; padding:20px; }
.cleanup-dialog { background:#fff; color:#1e293b; width:min(1100px,100%); max-height:94vh; overflow:auto; border-radius:12px; padding:22px; box-shadow:0 24px 70px #0004; }
header,footer { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
h2 { font-size:20px; margin:0; } h3 { font-size:15px; margin:0; } p { font-size:13px; line-height:1.5; margin:6px 0; } small { color:#64748b; font-weight:400; }
.cleanup-note,.cleanup-confirm { background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:12px; margin:16px 0; }
.cleanup-error { color:#b91c1c; background:#fef2f2; padding:12px; }
.cleanup-summary { display:flex; gap:8px 20px; flex-wrap:wrap; font-size:13px; margin:14px 0; }
.cleanup-filter { font-size:13px; display:flex; gap:8px; }
.cleanup-results { margin:15px 0; } .cleanup-product { border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin:10px 0; }
.cleanup-row { display:grid; grid-template-columns:1fr 1fr auto; align-items:center; gap:14px; border-top:1px solid #e2e8f0; padding:12px 0; }
.cleanup-row code,.cleanup-row small { display:block; overflow-wrap:anywhere; margin-top:4px; } .cleanup-status { font-size:12px; font-weight:700; color:#64748b; }
.cleanup-status[data-status="deleted"] { color:#15803d; } .cleanup-status[data-status="unverified"] { color:#b45309; }
footer { position:sticky; bottom:-22px; background:#fff; padding:14px 0; border-top:1px solid #e2e8f0; }
@media(max-width:650px) { .cleanup-overlay { padding:8px; } .cleanup-dialog { padding:14px; } .cleanup-row { grid-template-columns:1fr; gap:7px; } footer button { width:100%; } }
</style>

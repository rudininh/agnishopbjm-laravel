<template>
  <section class="page-shell">
    <header class="page-header">
      <div><p>Marketplace</p><h1>Sinkronisasi Varian Marketplace</h1><small>Shopee menjadi sumber SKU, nama, gambar, dan stok varian.</small></div>
      <button class="ghost" type="button" :disabled="loading || !selectedKey" @click="loadPreview">{{ loading ? 'Memuat...' : 'Analisis Ulang' }}</button>
    </header>
    <p v-if="notice" :class="['notice', noticeTone]">{{ notice }}</p>
    <section class="panel controls">
      <label>Produk terhubung
        <select v-model="selectedKey" :disabled="loading" @change="resetPreview">
          <option value="">Pilih produk</option>
          <option v-for="item in products" :key="keyOf(item)" :value="keyOf(item)">{{ reconciliationProductOptionLabel(item) }}</option>
        </select>
      </label>
      <div class="run"><strong>{{ preview?.summary?.total || 0 }} varian dianalisis</strong><button class="primary" type="button" disabled title="Aktif setelah backend sinkronisasi dan verifikasi selesai">Sinkronkan Semua Anomali</button></div>
    </section>
    <section v-if="preview" class="panel table-wrap">
      <div class="summary"><span>Perlu review {{ preview.summary.manual_review || 0 }}</span><span>Update TikTok {{ preview.summary.tiktok_variant_outdated || 0 }}</span><span class="danger">Orphan TikTok {{ preview.summary.tiktok_orphan || 0 }}</span></div>
      <table><thead><tr><th>Varian Shopee</th><th>SKU Saat Ini</th><th>Target</th><th>Stok</th><th>Status</th></tr></thead><tbody>
        <tr v-for="row in preview.rows" :key="`${row.shopee_model_id || 'orphan'}-${row.tiktok_sku_id || ''}`" :class="row.classification">
          <td><strong>{{ row.current?.shopee?.name || row.current?.tiktok?.sku_name || '-' }}</strong><small>{{ row.message }}</small></td>
          <td>{{ row.current?.shopee?.model_sku || row.current?.tiktok?.seller_sku || '-' }}</td>
          <td>{{ row.target?.seller_sku || '-' }}</td>
          <td>{{ row.target?.stock_qty ?? row.current?.tiktok?.stock_qty ?? '-' }}</td>
          <td><span class="badge">{{ label(row.classification) }}</span></td>
        </tr>
      </tbody></table>
    </section>
  </section>
</template>
<script setup>
import { computed, onMounted, ref } from 'vue'
import { omnichannelService } from '@/services'
import {
  firstReconciliationAnomalyKey,
  reconciliationProductKey,
  reconciliationProductOptionLabel,
  sortReconciliationProducts
} from './reconciliationProductState'
const products = ref([]); const selectedKey = ref(''); const preview = ref(null); const loading = ref(false); const notice = ref(''); const noticeTone = ref('warning')
const selected = computed(() => products.value.find(item => keyOf(item) === selectedKey.value) || null)
const keyOf = reconciliationProductKey
const label = value => ({ tiktok_variant_outdated: 'Perlu update TikTok', shopee_sku_outdated: 'Perlu update Shopee', tiktok_orphan: 'Akan dihapus', manual_review: 'Review manual', no_change: 'Sesuai' }[value] || value || '-')
const resetPreview = () => { preview.value = null; notice.value = '' }
const loadProducts = async () => {
  loading.value = true
  try {
    products.value = sortReconciliationProducts((await omnichannelService.tiktokVariantReconciliationProducts()).data.items || [])
    selectedKey.value = firstReconciliationAnomalyKey(products.value)
    if (selectedKey.value) await loadPreview()
  } catch {
    notice.value = 'Daftar produk sinkronisasi belum dapat dimuat.'
    noticeTone.value = 'error'
  } finally {
    loading.value = false
  }
}
const loadPreview = async () => { if (!selected.value) return; loading.value = true; notice.value = ''; try { preview.value = (await omnichannelService.tiktokVariantReconciliationPreview({ shopee_item_id: selected.value.shopee_item_id, tiktok_product_id: selected.value.tiktok_product_id })).data } catch (error) { notice.value = error.response?.data?.message || 'Analisis varian gagal dimuat.'; noticeTone.value = 'error' } finally { loading.value = false } }
onMounted(loadProducts)
</script>
<style scoped>
.page-shell{margin-left:240px;padding:24px;color:#1e293b}.page-header{display:flex;justify-content:space-between;gap:16px;margin-bottom:16px}.page-header p{margin:0;color:#64748b;font-size:13px}h1{margin:4px 0;font-size:26px}small{display:block;color:#64748b;font-size:12px}.panel{border:1px solid #d9e2ec;background:#fff;border-radius:6px;padding:14px}.controls{display:grid;grid-template-columns:minmax(320px,1fr) 260px;gap:14px;margin-bottom:16px}label{display:grid;gap:6px;font-size:13px;font-weight:700}select{min-height:38px;border:1px solid #cbd5e1;border-radius:4px;padding:0 9px}.run{display:grid;gap:8px;align-content:end}.primary,.ghost{border:1px solid transparent;border-radius:6px;padding:9px 12px;font-weight:700;cursor:pointer}.primary{background:#0f5fc7;color:#fff}.ghost{background:#fff;border-color:#cbd5e1;color:#334155}button:disabled{opacity:.56;cursor:not-allowed}.notice{border-radius:6px;padding:10px 12px;margin-bottom:14px}.notice.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.table-wrap{overflow:auto}.summary{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;font-size:13px;font-weight:700}.danger{color:#b91c1c}table{width:100%;min-width:800px;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e5eaf0;text-align:left;vertical-align:top;font-size:13px}th{background:#f8fafc;color:#475569;font-size:11px;text-transform:uppercase}.badge{display:inline-flex;padding:3px 6px;border-radius:4px;background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:800}tr.tiktok_orphan td{background:#fff1f2}@media(max-width:980px){.page-shell{margin-left:0;padding:16px}.page-header{flex-direction:column}.controls{grid-template-columns:1fr}}
</style>

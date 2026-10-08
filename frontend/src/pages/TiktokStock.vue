<template>
  <section class="page-shell" :inert="cleanupBusy || mirrorBusy">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>{{ unified ? `Stok TikTok · ${accountName}` : 'Stok TikTok' }}</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="resetFilters">Reset Filter</button>
        <button class="bulk-sku-action" @click="openSkuRepair(null, null, true)" :disabled="mirrorBusy || (loading || repairBusy || Boolean(updatingSkuKey))">Isi SKU Kosong</button>
        <button class="bulk-sku-action" @click="openSkuRepair()" :disabled="mirrorBusy || (loading || repairBusy || Boolean(updatingSkuKey))">{{ repairBusy ? 'Memproses SKU...' : 'Perbaiki SKU Semua Produk & Varian' }}</button>
        <OrphanVariantCleanup :account-key="accountKey || 'tiktok-agnishopbjm'" :account-name="accountName"
          :disabled="mirrorBusy || (loading || repairBusy || Boolean(updatingSkuKey) || Boolean(syncingProductId) || Boolean(deletingVariantKey))"
          @busy="cleanupBusy = $event" @completed="loadData(false)" />
        <button class="primary tiktok" @click="syncAndLoad" :disabled="mirrorBusy || (loading || repairBusy)">
          {{ loading ? 'Memuat...' : 'Ambil Produk' }}
        </button>
      </div>
    </header>

    <div class="summary-grid">
      <article class="metric">
        <span>Produk Live</span>
        <strong>{{ liveCount }}</strong>
      </article>
      <article class="metric">
        <span>Total Varian</span>
        <strong>{{ variantCount }}</strong>
      </article>
      <article class="metric">
        <span>Total Stok</span>
        <strong>{{ grandStock }}</strong>
      </article>
      <article class="metric">
        <span>Nilai Stok</span>
        <strong>{{ formatCurrency(grandValue) }}</strong>
      </article>
    </div>

    <div class="filter-panel">
      <div class="filter-row">
        <label>
          <span>Stores</span>
          <select v-model="filters.store">
            <option value="all">All</option>
            <option v-for="store in storeOptions" :key="store" :value="store">{{ store }}</option>
          </select>
        </label>
        <label>
          <span>Status</span>
          <select v-model="filters.status">
            <option value="all">Semua Status</option>
            <option value="live">Live</option>
            <option value="soldout">Sold Out</option>
            <option value="inactive">Tidak Live</option>
          </select>
        </label>
        <label>
          <span>Stok Minimum</span>
          <input v-model.number="filters.minimumStock" type="number" min="0" placeholder="0" />
        </label>
        <label>
          <span>Harga</span>
          <select v-model="filters.price">
            <option value="all">Semua Harga</option>
            <option value="promo">Ada Promo</option>
            <option value="high">Di atas Rp50.000</option>
          </select>
        </label>
      </div>

      <div class="filter-row">
        <label class="search-field">
          <span>Search</span>
          <div class="search-box">
            <select v-model="filters.searchBy">
              <option value="all">Semua</option>
              <option value="name">Product Name</option>
              <option value="sku">SKU</option>
              <option value="product_id">Product ID</option>
            </select>
            <input v-model.trim="filters.search" type="search" placeholder="Cari produk / SKU TikTok" />
          </div>
        </label>
        <label>
          <span>Sort By</span>
          <select v-model="filters.sort">
            <option value="updated_desc">Update Time</option>
            <option value="created_desc">Create Time</option>
            <option value="stock_desc">Stock</option>
            <option value="sales_desc">Sales</option>
            <option value="name_asc">Product Name</option>
          </select>
        </label>
      </div>
    </div>

    <div class="toolbar">
      <nav class="tabs">
        <button :class="{ active: activeTab === 'live' }" @click="setActiveTab('live')">Live ({{ liveCount }})</button>
        <button :class="{ active: activeTab === 'soldout' }" @click="setActiveTab('soldout')">Sold Out ({{ soldOutCount }})</button>
        <button :class="{ active: activeTab === 'inactive' }" @click="setActiveTab('inactive')">Tidak Live ({{ inactiveCount }})</button>
        <button :class="{ active: activeTab === 'all' }" @click="setActiveTab('all')">Semua ({{ items.length }})</button>
      </nav>
      <span class="result-count">{{ filteredItems.length }} produk tampil</span>
    </div>

    <p v-if="syncMessage" :class="['sync-message', syncTone]">{{ syncMessage }}</p>

    <div class="panel">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="check-col"><input type="checkbox" /></th>
              <th>Item & Store</th>
              <th>Parent SKU</th>
              <th>SKU</th>
              <th>Price</th>
              <th>Stock</th>
              <th>Quality</th>
              <th>Time</th>
              <th>Operation</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="item in pagedItems" :key="item.product_id">
              <tr :class="['product-row', { 'missing-sku-row': itemHasMissingSku(item) }]">
                <td class="check-col"><input type="checkbox" /></td>
                <td>
                  <div class="product-cell">
                    <img v-if="item.image_url" :src="item.image_url" :alt="item.product_name" class="thumb-image" />
                    <div v-else class="thumb-fallback">{{ initials(item.product_name) }}</div>
                    <div>
                      <strong>{{ item.product_name }}</strong>
                      <small>Item ID: {{ item.product_id }}</small>
                      <small>Sales: 0 | Likes: 0</small>
                      <span class="store-pill">{{ item.shop_name || 'TikTok Shop AgniShopBJM' }}</span>
                    </div>
                  </div>
                </td>
                <td>--</td>
                <td>
                  <strong>{{ firstRealSku(item.skus, 'seller_sku') || 'Tidak ada SKU' }}</strong>
                  <small>{{ skuSummary(item) }}</small>
                </td>
                <td>
                  <div class="price-cell">
                    <strong>Harga: {{ priceRange(item.skus) }}</strong>
                  </div>
                </td>
                <td>
                  <strong>{{ totalStock(item.skus) }}</strong>
                  <small>{{ formatCurrency(totalValue(item.skus)) }}</small>
                </td>
                <td>
                  <span :class="['status-badge', rowStatus(item).tone]">{{ rowStatus(item).label }}</span>
                  <small>{{ qualityNote(item) }}</small>
                </td>
                <td>
                  <small>Create Time</small>
                  <strong>-</strong>
                  <small>Update Time</small>
                  <strong>{{ formatDate(item.updated_at) }}</strong>
                </td>
                <td>
                  <div class="actions">
                    <button title="Lihat varian" @click="toggle(item.product_id)">{{ expanded[item.product_id] ? 'Hide' : 'Show' }}</button>
                    <button v-if="unified" :disabled="mirrorBusy || !mirrorTargets.length || loading || repairBusy || Boolean(updatingSkuKey) || Boolean(deletingVariantKey) || Boolean(syncingProductId)" @click="emit('mirror-stock', { type: 'product', view_account_key: accountKey, product_id: String(item.product_id) })">Samakan Stok Produk</button>
                    <button title="Refresh produk ini" @click="syncProduct(item)" :disabled="mirrorBusy || (syncingProductId === item.product_id)">
                      {{ syncingProductId === item.product_id ? 'Memuat...' : 'Refresh' }}
                    </button>
                    <button v-if="repairRows([item]).length" @click="openSkuRepair(item)" :disabled="mirrorBusy || (repairBusy || Boolean(updatingSkuKey))">Perbaiki SKU ({{ repairRows([item]).length }})</button>
                  </div>
                </td>
              </tr>
              <tr v-if="expanded[item.product_id]" class="variant-row">
                <td></td>
                <td colspan="8">
                  <div class="variant-list">
                    <div v-for="(sku, index) in item.skus" :key="`${item.product_id}-${sku.sku_id || sku.sku_name || index}`" :class="['variant-item', { 'missing-sku-row': missingTiktokSku(sku) }]">
                      <span class="variant-name">
                        <img v-if="sku.image_url" :src="sku.image_url" :alt="sku.sku_name || 'Varian TikTok'" />
                        <span v-else class="variant-thumb-fallback">{{ initials(sku.sku_name) }}</span>
                        <span>{{ sku.sku_name || '-' }}</span>
                      </span>
                      <span class="variant-code">
                        <small>SKU Real</small>
                        <strong>{{ tiktokRealSku(sku) || 'Tidak ada SKU' }}</strong>
                        <small v-if="sku.sku_repair_blocked">{{ sku.sku_repair_blocked }}</small>
                        <small v-else-if="templateSku(item, sku) && tiktokRealSku(sku) !== templateSku(item, sku)">SKU berbeda dari Agni</small>
                        <small>SKU Acuan Agni</small>
                        <span class="copy-line">
                          <code>{{ templateSku(item, sku) }}</code>
                          <button type="button" title="Copy SKU Template" @click="copyVariationCode(item, sku)" :disabled="mirrorBusy || (!templateSku(item, sku))">Copy</button>
                        </span>
                      </span>
                      <span>SKU ID: {{ sku.sku_id || sku.tiktok_sku || '-' }}</span>
                      <span class="variant-price">
                        <strong>{{ formatCurrency(sku.price || 0) }}</strong>
                      </span>
                      <strong>Stock {{ sku.stock_qty || 0 }}</strong>
                      <span class="variant-actions">
                        <button v-if="unified" :disabled="mirrorBusy || !mirrorTargets.length || loading || repairBusy || Boolean(updatingSkuKey) || Boolean(deletingVariantKey) || Boolean(syncingProductId)" @click="emit('mirror-stock', { type: 'variant', view_account_key: accountKey, product_id: String(item.product_id), variant_id: String(sku.sku_id || sku.tiktok_sku || '') })">Samakan Stok Varian</button>
                        <button v-if="templateSku(item, sku) && tiktokRealSku(sku) !== templateSku(item, sku)" class="update-sku-btn" @click="openSkuRepair(item, sku)" :disabled="mirrorBusy || (repairBusy || Boolean(updatingSkuKey))">Perbaiki sesuai Agni</button>
                        <button
                          type="button"
                          class="update-sku-btn"
                          title="Buka preview SKU dari Agni"
                          @click="updateMissingSku(item, sku)"
                          :disabled="mirrorBusy || (!canUpdateMissingSku(item, sku) || updatingSkuKey === tiktokSkuKey(item, sku))"
                        >
                          {{ updatingSkuKey === tiktokSkuKey(item, sku) ? 'Updating...' : 'Update SKU' }}
                        </button>
                        <button
                          type="button"
                          class="delete-variant-btn"
                          title="Hapus varian ini dari TikTok"
                          @click="openDeleteVariantModal(item, sku)"
                          :disabled="mirrorBusy || (!canDeleteTiktokVariant(item, sku) || deletingVariantKey === tiktokSkuKey(item, sku))"
                        >
                          {{ deletingVariantKey === tiktokSkuKey(item, sku) ? 'Deleting...' : 'Hapus' }}
                        </button>
                      </span>
                    </div>
                    <div v-if="!item.skus?.length" class="variant-empty">Tidak ada varian tersimpan.</div>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!filteredItems.length">
              <td colspan="9" class="empty">{{ loading ? 'Sedang memuat produk...' : 'Belum ada produk yang cocok dengan filter.' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="filteredItems.length" class="pagination">
        <button type="button" :disabled="mirrorBusy || (currentPage === 1)" @click="setPage(currentPage - 1)">Prev</button>
        <span>Halaman {{ currentPage }} dari {{ totalPages }}</span>
        <button type="button" :disabled="mirrorBusy || (currentPage === totalPages)" @click="setPage(currentPage + 1)">Next</button>
      </div>
    </div>

    <div v-if="repairModal.open" class="modal-backdrop" @click.self="closeSkuRepair" @keydown.esc="closeSkuRepair">
      <section class="confirm-modal repair-modal" role="dialog" aria-modal="true" aria-labelledby="tiktok-repair-title">
        <h2 id="tiktok-repair-title">Perbaiki SKU TikTok</h2>
        <p>{{ repairModal.accountName }} · {{ repairModal.productName }}</p>
        <p>SKU disalin dari varian Shopee Agni yang cocok. Perubahan dikirim ke TikTok setelah konfirmasi.</p>
        <div class="repair-rows">
          <div v-for="row in repairModal.rows" :key="`${row.productId}:${row.skuId}`" class="repair-row">
            <small>{{ row.productName }} · {{ row.productId }}</small>
            <strong>{{ row.name }}</strong>
            <small>SKU saat ini</small><code>{{ row.current || '(kosong)' }}</code>
            <small>SKU yang benar</small><code>{{ row.target || '-' }}</code>
            <p v-if="row.blocked || row.status">{{ row.blocked || row.message || row.status }}</p>
          </div>
        </div>
        <p role="status">{{ repairModal.message }}</p>
        <div class="modal-actions">
          <button class="ghost" @click="closeSkuRepair" :disabled="mirrorBusy || (repairBusy)">Tutup</button>
          <button class="bulk-sku-action" @click="submitSkuRepair" :disabled="mirrorBusy || (repairBusy || !repairPending.length)">{{ repairBusy ? 'Memproses...' : `Perbaiki ${repairPending.length} SKU` }}</button>
        </div>
      </section>
    </div>

    <div v-if="deleteModal.open" class="modal-backdrop" @click.self="closeDeleteVariantModal">
      <section class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="delete-variant-title">
        <h2 id="delete-variant-title">Hapus Varian TikTok</h2>
        <p class="danger-copy">Aksi ini akan menghapus varian langsung dari marketplace TikTok.</p>
        <div class="delete-details">
          <div>
            <span>Produk</span>
            <strong>{{ deleteModal.productName }}</strong>
          </div>
          <div>
            <span>Varian</span>
            <strong>{{ deleteModal.skuName }}</strong>
          </div>
          <div>
            <span>Product ID</span>
            <strong>{{ deleteModal.productId }}</strong>
          </div>
          <div>
            <span>SKU Mapping</span>
            <strong>{{ deleteModal.mappingSku }}</strong>
          </div>
        </div>
        <label class="confirm-field">
          <span>Ketik SKU Mapping untuk konfirmasi</span>
          <input v-model.trim="deleteModal.confirmMappingSku" type="text" :placeholder="deleteModal.mappingSku" />
        </label>
        <p v-if="deleteModal.error" class="modal-error">{{ deleteModal.error }}</p>
        <div class="modal-actions">
          <button type="button" class="ghost" @click="closeDeleteVariantModal" :disabled="mirrorBusy || (Boolean(deletingVariantKey))">Batal</button>
          <button
            type="button"
            class="danger-action"
            @click="confirmDeleteVariant"
            :disabled="mirrorBusy || (deleteModal.confirmMappingSku !== deleteModal.mappingSku || Boolean(deletingVariantKey))"
          >
            {{ deletingVariantKey ? 'Menghapus...' : 'Hapus dari TikTok' }}
          </button>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup>
import OrphanVariantCleanup from '@/components/OrphanVariantCleanup.vue'
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { omnichannelService } from '@/services'
import { tiktokTemplateSku, tiktokSkuRepairRows as repairRows } from './tiktokSkuRepairState'

const props = defineProps({
  accountKey: { type: String, default: '' },
  accountName: { type: String, default: 'TikTok' },
  mirrorBusy: { type: Boolean, default: false },
  mirrorTargets: { type: Array, default: () => [] },
  unified: { type: Boolean, default: false }
})

const emit = defineEmits(['mirror-stock', 'busy'])
const accountParams = () => props.accountKey ? { account_key: props.accountKey } : {}
const cleanupBusy = ref(false)
const repairBusy = ref(false)
const repairModal = reactive({ open: false, rows: [], accountKey: '', accountName: '', productName: '', message: '' })
const repairPending = computed(() => repairModal.rows.filter(row => !row.blocked && !row.status))
let repairMounted = true
onBeforeUnmount(() => { repairMounted = false })
const closeSkuRepair = () => { if (!repairBusy.value) repairModal.open = false }
const openSkuRepair = async (item = null, sku = null, emptyOnly = false) => {
  if (props.mirrorBusy) return
  if (repairBusy.value) return
  repairBusy.value = true
  const accountKey = props.accountKey || 'tiktok-agnishopbjm'
  Object.assign(repairModal, {
    open: true, rows: [], accountKey, accountName: props.accountName,
    productName: item?.product_name || 'Semua produk dan varian toko ini (termasuk di luar filter / halaman)',
    message: 'Mengambil katalog TikTok terbaru. Proses ini dapat memerlukan beberapa menit...'
  })
  try {
    const source = await omnichannelService.shopeeItems(true, { account_key: 'shopee-agnishopbjm' })
    if (!repairMounted) return
    if (source.data.sync?.status !== 'ok') throw new Error('Data SKU Agni belum dapat diperbarui. Periksa akun sumber Agni.')
    const response = await omnichannelService.tiktokItems(true, { account_key: accountKey, ...(item ? { product_id: item.product_id } : {}) })
    if (!repairMounted) return
    if (response.data.sync?.status !== 'ok') throw new Error(response.data.sync?.message || 'Sync TikTok gagal.')
    items.value = response.data.items || []
    const products = item ? items.value.filter(candidate => String(candidate.product_id) === String(item.product_id)) : items.value
    if (item && !products.length) throw new Error('Produk tidak ditemukan di katalog terbaru.')
    repairModal.rows = repairRows(products, emptyOnly).filter(row => !sku || row.skuId === String(sku.sku_id))
    repairModal.message = repairModal.rows.length
      ? `${repairModal.rows.length} SKU pada ${new Set(repairModal.rows.map(row => row.productId)).size} produk. Periksa preview sebelum konfirmasi.`
      : 'Semua SKU sudah sesuai.'
  } catch (error) {
    repairModal.rows = []
    repairModal.message = error.response?.data?.message || error.message
  } finally { repairBusy.value = false }
}
const submitSkuRepair = async () => {
  if (props.mirrorBusy) return
  if (repairBusy.value) return
  repairBusy.value = true
  const pending = [...repairPending.value]
  const awaitingProducts = new Set()
  try {
    for (const row of pending) {
      if (!repairMounted) break
      if (awaitingProducts.has(row.productId)) {
        row.status = 'Ditunda'
        row.message = 'Menunggu perubahan SKU sebelumnya pada produk ini terverifikasi. Sync sebelum mencoba ulang.'
        continue
      }
      row.status = 'Memproses'
      try {
        const response = await omnichannelService.updateMarketplaceVariantSku({
          channel: 'tiktok', account_key: repairModal.accountKey, product_id: row.productId,
          sku_id: row.skuId, seller_sku: row.target, repair_template: true,
          expected_sku: row.current, expected_name: row.name
        })
        if (response.data?.status === 'submitted_unverified') {
          awaitingProducts.add(row.productId)
          row.status = 'Menunggu verifikasi'
          row.message = response.data.message
        } else {
          if (response.data?.status !== 'ok') throw new Error(response.data?.response?.message || 'TikTok menolak perubahan.')
          row.status = 'Berhasil'
          const product = items.value.find(item => String(item.product_id) === row.productId)
          const sku = product?.skus?.find(sku => String(sku.sku_id) === row.skuId)
          if (sku) sku.seller_sku = response.data.seller_sku
        }
      } catch (error) {
        awaitingProducts.add(row.productId)
        row.status = 'Gagal'
        row.message = error.response?.data?.response?.message || error.response?.data?.message || error.message
      }
      repairModal.message = `Diproses ${pending.filter(row => row.status && row.status !== 'Memproses').length} / ${pending.length} SKU.`
    }
    repairModal.message = ['Berhasil', 'Gagal', 'Menunggu verifikasi', 'Ditunda'].map(status => `${status}: ${repairModal.rows.filter(row => row.status === status).length}`).join(' | ')
      + ` | Perlu diperiksa: ${repairModal.rows.filter(row => row.blocked).length}. Sync sebelum mencoba ulang.`
  } finally { repairBusy.value = false }
}

const items = ref([])
const expanded = ref({})
const loading = ref(false)
const syncingProductId = ref('')
const updatingSkuKey = ref('')
const deletingVariantKey = ref('')
const activeTab = ref('live')
const page = ref(1)
const PAGE_SIZE = 20
const syncMessage = ref('')
const syncTone = ref('info')
const lastSyncAt = ref('')
const copyTimer = ref(null)
const deleteModal = reactive({
  open: false,
  productId: '',
  skuId: '',
  mappingSku: '',
  productName: '',
  skuName: '',
  confirmMappingSku: '',
  error: ''
})
const filters = reactive({
  store: 'all',
  status: 'all',
  minimumStock: null,
  price: 'all',
  searchBy: 'all',
  search: '',
  sort: 'updated_desc'
})

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0)
const totalStock = (skus) => (skus || []).reduce((sum, item) => sum + Number(item.stock_qty || 0), 0)
const totalValue = (skus) => (skus || []).reduce((sum, item) => sum + Number(item.subtotal || 0), 0)
const isSoldOut = (item) => totalStock(item.skus) <= 0
const isLive = () => true
const variantCount = computed(() => items.value.reduce((sum, item) => sum + (item.skus?.length || 0), 0))
const grandStock = computed(() => filteredItems.value.reduce((sum, item) => sum + totalStock(item.skus), 0))
const grandValue = computed(() => filteredItems.value.reduce((sum, item) => sum + totalValue(item.skus), 0))
const liveCount = computed(() => items.value.filter((item) => isLive(item) && !isSoldOut(item)).length)
const soldOutCount = computed(() => items.value.filter((item) => isSoldOut(item)).length)
const inactiveCount = computed(() => items.value.filter((item) => !isLive(item)).length)
const storeOptions = computed(() => [...new Set(items.value.map((item) => item.shop_name || 'TikTok Shop AgniShopBJM'))].sort())

const normalizedSearch = computed(() => filters.search.toLowerCase())
const tiktokSkuHaystack = (item) => [
  item.product_id,
  ...(item.skus || []).flatMap((sku) => [
    sku.sku_name,
    sku.seller_sku,
    sku.kode_variasi,
    sku.sku_id,
    sku.tiktok_sku,
    variationCode(item, sku)
  ])
].filter(Boolean).join(' ')
const tiktokSearchHaystack = (item) => ({
  all: [item.product_name, item.product_id, tiktokSkuHaystack(item)].filter(Boolean).join(' '),
  name: item.product_name,
  sku: tiktokSkuHaystack(item),
  product_id: item.product_id
}[filters.searchBy] || item.product_name)

const filteredItems = computed(() => {
  const query = normalizedSearch.value

  return items.value
    .filter((item) => {
      const stock = totalStock(item.skus)
      if (activeTab.value === 'live' && (!isLive(item) || isSoldOut(item))) return false
      if (activeTab.value === 'soldout' && !isSoldOut(item)) return false
      if (activeTab.value === 'inactive' && isLive(item)) return false
      if (filters.status === 'live' && (!isLive(item) || isSoldOut(item))) return false
      if (filters.status === 'soldout' && !isSoldOut(item)) return false
      if (filters.status === 'inactive' && isLive(item)) return false
      if (filters.store !== 'all' && (item.shop_name || 'TikTok Shop AgniShopBJM') !== filters.store) return false
      if (Number(filters.minimumStock || 0) > 0 && stock < Number(filters.minimumStock)) return false
      if (filters.price === 'high' && maxSkuPrice(item.skus) <= 50000) return false
      if (filters.price === 'promo') return false
      if (!query) return true

      return String(tiktokSearchHaystack(item) || '').toLowerCase().includes(query)
    })
    .sort((a, b) => {
      if (filters.sort === 'stock_desc') return totalStock(b.skus) - totalStock(a.skus)
      if (filters.sort === 'name_asc') return String(a.product_name || '').localeCompare(String(b.product_name || ''))
      if (filters.sort === 'created_desc') return new Date(b.created_at || 0) - new Date(a.created_at || 0)
      return new Date(b.updated_at || 0) - new Date(a.updated_at || 0)
    })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredItems.value.length / PAGE_SIZE)))
const currentPage = computed(() => Math.min(page.value, totalPages.value))
const pagedItems = computed(() => filteredItems.value.slice((currentPage.value - 1) * PAGE_SIZE, currentPage.value * PAGE_SIZE))
const lastSyncLabel = computed(() => lastSyncAt.value ? `Terakhir sinkron: ${formatDate(lastSyncAt.value)}` : 'Belum pernah sinkron.')

const skuSummary = (item) => `${item.skus?.length || 0} varian`
const initials = (name) => String(name || 'TT').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const marketplaceSku = (value) => {
  const sku = String(value || '').trim()
  return sku === '-' ? '' : sku
}
const skuFragment = (value) => String(value || 'VARIAN').trim().toUpperCase().replace(/[^A-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30) || 'VARIAN'
const maxSkuPrice = (skus) => Math.max(0, ...(skus || []).map((sku) => Number(sku.price || 0)))
const tiktokRealSku = (sku) => marketplaceSku(sku?.seller_sku)
const firstRealSku = (rows, key) => (rows || []).map((row) => marketplaceSku(row?.[key])).find(Boolean) || ''
const missingTiktokSku = (sku) => !tiktokRealSku(sku)
const itemHasMissingSku = (item) => (item?.skus || []).some((sku) => missingTiktokSku(sku))
const templateSku = tiktokTemplateSku
const variationCode = templateSku
const tiktokSkuKey = (item, sku) => `${item?.product_id || ''}:${sku?.sku_id || sku?.tiktok_sku || ''}`
const targetSku = (item, sku) => templateSku(item, sku)
const canUpdateMissingSku = (item, sku) => Boolean(item?.product_id && (sku?.sku_id || sku?.tiktok_sku) && targetSku(item, sku))
const deleteConfirmationSku = sku => tiktokRealSku(sku) || String(sku?.delete_confirmation_sku || '')
const canDeleteTiktokVariant = (item, sku) => Boolean(item?.product_id && (sku?.sku_id || sku?.tiktok_sku) && deleteConfirmationSku(sku) && (item?.skus?.length || 0) > 1)
const copyText = async (value) => {
  const text = String(value || '').trim()
  if (!text) return

  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      const textarea = document.createElement('textarea')
      textarea.value = text
      textarea.setAttribute('readonly', '')
      textarea.style.position = 'fixed'
      textarea.style.opacity = '0'
      document.body.appendChild(textarea)
      textarea.select()
      document.execCommand('copy')
      document.body.removeChild(textarea)
    }

    syncMessage.value = `SKU disalin: ${text}`
    syncTone.value = 'success'
    clearTimeout(copyTimer.value)
    copyTimer.value = setTimeout(() => {
      if (syncMessage.value === `SKU disalin: ${text}`) syncMessage.value = ''
    }, 1600)
  } catch (error) {
    syncMessage.value = 'SKU belum bisa disalin.'
    syncTone.value = 'error'
  }
}
const copyVariationCode = (item, sku) => copyText(variationCode(item, sku))
const updateMissingSku = async (item, sku) => {
  if (props.mirrorBusy) return
  return openSkuRepair(item, sku)
}
const openDeleteVariantModal = (item, sku) => {
  if (props.mirrorBusy) return
  if (!canDeleteTiktokVariant(item, sku)) {
    syncMessage.value = 'Varian ini tidak bisa dihapus dari tool karena SKU Mapping/SKU ID kosong atau varian terakhir.'
    syncTone.value = 'warning'
    return
  }

  deleteModal.open = true
  deleteModal.productId = String(item?.product_id || '')
  deleteModal.skuId = String(sku?.sku_id || sku?.tiktok_sku || '')
  deleteModal.mappingSku = deleteConfirmationSku(sku)
  deleteModal.productName = String(item?.product_name || '-')
  deleteModal.skuName = String(sku?.sku_name || '-')
  deleteModal.confirmMappingSku = ''
  deleteModal.error = ''
}
const closeDeleteVariantModal = () => {
  if (deletingVariantKey.value) return

  deleteModal.open = false
  deleteModal.productId = ''
  deleteModal.skuId = ''
  deleteModal.mappingSku = ''
  deleteModal.productName = ''
  deleteModal.skuName = ''
  deleteModal.confirmMappingSku = ''
  deleteModal.error = ''
}
const removeDeletedVariantFromState = (productId, skuId) => {
  const item = items.value.find((row) => String(row.product_id) === String(productId))
  if (!item) return

  item.skus = (item.skus || []).filter((sku) => String(sku.sku_id || sku.tiktok_sku || '') !== String(skuId))
}
const confirmDeleteVariant = async () => {
  if (props.mirrorBusy) return
  if (deleteModal.confirmMappingSku !== deleteModal.mappingSku || deletingVariantKey.value) return

  const key = `${deleteModal.productId}:${deleteModal.skuId}`
  deletingVariantKey.value = key
  deleteModal.error = ''
  syncMessage.value = ''
  let shouldCloseDeleteModal = false

  try {
    const response = await omnichannelService.tiktokDeleteVariant({
      product_id: deleteModal.productId,
      sku_id: deleteModal.skuId,
      confirm_mapping_sku: deleteModal.confirmMappingSku
    })

    removeDeletedVariantFromState(deleteModal.productId, deleteModal.skuId)
    syncMessage.value = response.data?.message || 'Varian TikTok berhasil dihapus.'
    syncTone.value = response.data?.status === 'error' ? 'error' : 'success'
    shouldCloseDeleteModal = true
  } catch (error) {
    const message = error.response?.data?.message || error.response?.data?.response?.message || 'Hapus varian TikTok gagal.'
    deleteModal.error = message
    syncMessage.value = message
    syncTone.value = 'error'
  } finally {
    deletingVariantKey.value = ''
    if (shouldCloseDeleteModal) closeDeleteVariantModal()
  }
}
const qualityNote = (item) => isSoldOut(item) ? 'Stok perlu dicek' : 'Produk sedang dijual'
const rowStatus = (item) => {
  if (isSoldOut(item)) return { label: 'Sold Out', tone: 'warning' }
  if (!isLive(item)) return { label: item.status || 'Tidak Live', tone: 'muted' }
  return { label: 'Live', tone: 'success' }
}
const priceRange = (skus) => {
  const prices = (skus || []).map((sku) => Number(sku.price || 0)).filter(Boolean)
  if (!prices.length) return '-'
  const min = Math.min(...prices)
  const max = Math.max(...prices)
  return min === max ? formatCurrency(min) : `${formatCurrency(min)} - ${formatCurrency(max)}`
}
const formatDate = (value) => {
  if (!value) return '-'
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

const resetFilters = () => {
  filters.store = 'all'
  filters.status = 'all'
  filters.minimumStock = null
  filters.price = 'all'
  filters.searchBy = 'all'
  filters.search = ''
  filters.sort = 'updated_desc'
  activeTab.value = 'live'
  page.value = 1
}

const setActiveTab = (tab) => {
  activeTab.value = tab
  page.value = 1
}

watch(() => filters.search, () => {
  page.value = 1
  const query = normalizedSearch.value
  if (!query || !['all', 'sku'].includes(filters.searchBy)) return

  filteredItems.value.slice(0, PAGE_SIZE).forEach((item) => {
    if (tiktokSkuHaystack(item).toLowerCase().includes(query)) {
      expanded.value[item.product_id] = true
    }
  })
})

watch(() => filters.searchBy, () => {
  page.value = 1
})

const toggle = (id) => {
  expanded.value[id] = !expanded.value[id]
}

const setPage = (nextPage) => {
  page.value = Math.min(Math.max(Number(nextPage) || 1, 1), totalPages.value)
}

watch(() => Boolean(cleanupBusy.value || repairBusy.value || loading.value || updatingSkuKey.value || deletingVariantKey.value || syncingProductId.value), value => emit('busy', value), { flush: 'sync', immediate: true })
onBeforeUnmount(() => emit('busy', false))

const loadData = async (syncMode = false) => {
  loading.value = true
  syncMessage.value = ''
  try {
    const response = await omnichannelService.tiktokItems(syncMode, accountParams())
    items.value = response.data.items || []
    lastSyncAt.value = response.data.last_sync_at || response.data.sync?.last_sync_at || ''
    syncMessage.value = response.data.sync?.message || response.data.message || ''
    syncTone.value = response.data.sync?.status === 'empty' ? 'warning' : 'info'
    page.value = 1
  } catch (error) {
    syncMessage.value = error.response?.data?.message || 'Data TikTok gagal dimuat.'
    syncTone.value = 'error'
  } finally {
    loading.value = false
  }
}

const syncAndLoad = async () => {
  if (props.mirrorBusy) return
  await loadData(true)
}

const syncProduct = async (item) => {
  if (props.mirrorBusy) return
  syncingProductId.value = item.product_id
  syncMessage.value = ''
  try {
    const response = await omnichannelService.tiktokItems(true, { ...accountParams(), product_id: item.product_id })
    items.value = response.data.items || []
    lastSyncAt.value = response.data.last_sync_at || response.data.sync?.last_sync_at || ''
    syncMessage.value = response.data.sync?.message || response.data.message || ''
    syncTone.value = response.data.sync?.status === 'error' ? 'error' : 'success'
  } catch (error) {
    syncMessage.value = error.response?.data?.message || 'Sinkronisasi produk TikTok gagal.'
    syncTone.value = 'error'
  } finally {
    syncingProductId.value = ''
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 16px; }
.page-header p { color: #64748b; margin-bottom: 4px; font-size: 13px; }
.page-header h1 { font-size: 26px; letter-spacing: 0; }
.header-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; }
.bulk-sku-action { background: #1d4ed8; color: #fff; }
.bulk-sku-action:disabled { opacity: .55; cursor: not-allowed; }
.repair-modal > p { margin: 10px 0; color: #475569; }
.repair-rows { max-height: 50vh; overflow: auto; }
.repair-row { display: grid; gap: 5px; padding: 12px; border: 1px solid #d9e2ec; border-radius: 6px; margin: 8px 0; }
.repair-row small { color: #64748b; }
.repair-row code { overflow-wrap: anywhere; }
button, input, select { font-size: 13px; }
button { border: 0; border-radius: 6px; padding: 9px 12px; cursor: pointer; }
.primary { color: #fff; }
.primary:disabled { opacity: .65; cursor: wait; }
.tiktok { background: #111827; }
.ghost { color: #334155; background: #fff; border: 1px solid #d7dde8; }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
.metric { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; }
.metric span { color: #64748b; display: block; font-size: 12px; margin-bottom: 6px; }
.metric strong { color: #111827; font-size: 22px; }
.filter-panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; margin-bottom: 12px; }
.filter-row { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 10px; align-items: end; }
.filter-row + .filter-row { margin-top: 10px; grid-template-columns: 2fr 1fr; }
label span { color: #64748b; display: block; font-size: 12px; margin-bottom: 6px; }
select, input { width: 100%; height: 36px; border: 1px solid #d7dde8; border-radius: 4px; background: #fff; color: #111827; padding: 0 10px; }
.search-box { display: grid; grid-template-columns: 160px 1fr; }
.search-box select { border-radius: 4px 0 0 4px; }
.search-box input { border-left: 0; border-radius: 0 4px 4px 0; }
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 14px 0 8px; }
.tabs { display: flex; gap: 18px; overflow-x: auto; }
.tabs button { background: transparent; border-radius: 0; color: #64748b; padding: 10px 0; border-bottom: 2px solid transparent; white-space: nowrap; }
.tabs button.active { color: #0f5fc7; border-bottom-color: #0f5fc7; }
.result-count { color: #64748b; font-size: 13px; white-space: nowrap; }
.sync-message { border: 1px solid #d9e2ec; border-radius: 6px; font-size: 13px; margin: 0 0 10px; padding: 10px 12px; }
.sync-message.info { color: #334155; background: #f8fafc; }
.sync-message.success { color: #166534; background: #ecfdf5; border-color: #86efac; }
.sync-message.warning { color: #9a3412; background: #fff7ed; border-color: #fed7aa; }
.sync-message.error { color: #991b1b; background: #fef2f2; border-color: #fecaca; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; overflow: hidden; }
.table-wrap { max-height: 68vh; overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1180px; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
thead th { position: sticky; top: 0; background: #f8fafc; color: #0f172a; z-index: 2; }
.check-col { width: 34px; text-align: center; }
.product-row:hover { background: #fbfdff; }
.product-row.missing-sku-row { background: #fffbeb; }
.product-row.missing-sku-row:hover { background: #fef3c7; }
.product-cell { display: grid; grid-template-columns: 72px 1fr; gap: 10px; min-width: 380px; }
.thumb-image { width: 72px; height: 72px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.thumb-fallback { width: 72px; height: 72px; border-radius: 6px; display: grid; place-items: center; background: #eef2f7; color: #64748b; font-weight: 800; }
strong { display: block; color: #0f172a; line-height: 1.35; }
small { display: block; color: #64748b; line-height: 1.55; }
.store-pill { display: inline-block; margin-top: 6px; padding: 4px 8px; color: #64748b; background: #f6f7fb; border-radius: 4px; font-size: 12px; }
.price-cell, .variant-price { display: grid; gap: 3px; align-content: start; }
.variant-price strong { font-size: 13px; }
.status-badge { display: inline-block; border-radius: 999px; padding: 4px 8px; margin-bottom: 4px; font-size: 12px; font-weight: 700; }
.success { color: #047857; background: #d1fae5; }
.warning { color: #b45309; background: #fef3c7; }
.muted { color: #64748b; background: #eef2f7; }
.actions { display: grid; gap: 6px; }
.actions button { color: #0f5fc7; background: #eaf1ff; padding: 7px 9px; }
.variant-row td { background: #fafafa; padding-top: 0; }
.variant-list { border-top: 1px dashed #d7dde8; padding-top: 8px; display: grid; gap: 6px; }
.variant-item { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, .8fr) minmax(0, .65fr) minmax(0, .35fr) minmax(132px, .48fr); gap: 10px; padding: 8px; background: #fff; border: 1px solid #edf0f5; border-radius: 6px; }
.variant-item.missing-sku-row { background: #fffbeb; border-color: #facc15; }
.variant-name { display: grid; grid-template-columns: 42px minmax(0, 1fr); gap: 10px; align-items: center; }
.variant-name img, .variant-thumb-fallback { width: 42px; height: 42px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.variant-thumb-fallback { display: grid; place-items: center; color: #64748b; font-size: 11px; font-weight: 800; }
.variant-code { display: grid; gap: 3px; min-width: 0; align-content: start; }
.variant-code code { color: #0f172a; font-family: inherit; font-size: 12px; font-weight: 700; line-height: 1.4; overflow-wrap: anywhere; }
.variant-code small { color: #64748b; font-size: 11px; line-height: 1.25; }
.variant-code strong { font-size: 12px; overflow-wrap: anywhere; }
.copy-line { display: flex; align-items: center; gap: 6px; min-width: 0; }
.variant-code button { flex: 0 0 auto; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 4px; padding: 4px 7px; font-size: 11px; font-weight: 700; }
.variant-code button:disabled { cursor: not-allowed; opacity: .45; }
.variant-actions { display: grid; gap: 6px; align-content: start; justify-items: stretch; }
.manual-sku-input { width: 100%; min-width: 0; height: 30px; font-size: 12px; padding: 0 8px; }
.update-sku-btn { border: 1px solid #f59e0b; background: #fef3c7; color: #92400e; border-radius: 5px; padding: 6px 8px; font-size: 11px; font-weight: 800; }
.update-sku-btn:disabled { cursor: not-allowed; opacity: .55; }
.delete-variant-btn { border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; border-radius: 5px; padding: 6px 8px; font-size: 11px; font-weight: 800; }
.delete-variant-btn:disabled { cursor: not-allowed; opacity: .5; }
.variant-empty, .empty { color: #64748b; text-align: center; padding: 24px; }
.pagination { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 12px 14px; border-top: 1px solid #e5e7eb; background: #fff; }
.pagination button { color: #334155; background: #fff; border: 1px solid #cbd5e1; font-weight: 700; }
.pagination button:disabled { cursor: not-allowed; opacity: .45; }
.pagination span { color: #64748b; font-size: 13px; }
.modal-backdrop { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; background: rgba(15, 23, 42, .42); padding: 16px; }
.confirm-modal { width: min(520px, 100%); background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 24px 70px rgba(15, 23, 42, .22); padding: 18px; }
.repair-modal { width: min(760px, 100%); max-height: 90vh; overflow: auto; }
.confirm-modal h2 { color: #0f172a; font-size: 20px; letter-spacing: 0; margin: 0 0 8px; }
.danger-copy { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; font-size: 13px; line-height: 1.45; margin: 0 0 12px; padding: 10px; }
.delete-details { display: grid; gap: 8px; margin-bottom: 12px; }
.delete-details div { display: grid; grid-template-columns: 92px minmax(0, 1fr); gap: 10px; align-items: start; }
.delete-details span { color: #64748b; font-size: 12px; }
.delete-details strong { font-size: 13px; overflow-wrap: anywhere; }
.confirm-field { display: block; margin-bottom: 12px; }
.confirm-field input { margin-top: 6px; }
.modal-error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; font-size: 13px; line-height: 1.45; margin: 0 0 12px; padding: 10px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
.danger-action { color: #fff; background: #dc2626; font-weight: 800; }
.danger-action:disabled { cursor: not-allowed; opacity: .55; }
@media (max-width: 1100px) {
  .summary-grid, .filter-row, .filter-row + .filter-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 820px) {
  .page-shell { margin-left: 0; padding: 16px; }
  .page-header, .toolbar { align-items: stretch; flex-direction: column; }
  .header-actions { width: 100%; }
  .header-actions button { flex: 1; }
  .summary-grid, .filter-row, .filter-row + .filter-row, .search-box { grid-template-columns: 1fr; }
}
</style>

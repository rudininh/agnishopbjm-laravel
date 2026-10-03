<template>
  <section class="page-shell" :inert="cleanupBusy">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>{{ unified ? `Stok Shopee · ${accountName}` : 'Stok Shopee' }}</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="resetFilters">Reset Filter</button>
        <button
          class="bulk-sku-action"
          type="button"
          :disabled="loading || bulkSkuUpdating || repairBusy || missingSkuVariantCount === 0"
          @click="openBulkSkuModal"
        >
          {{ bulkSkuUpdating ? 'Mengisi SKU...' : `Isi SKU Kosong (${missingSkuVariantCount})` }}
        </button>
        <button class="bulk-sku-action" type="button" @click="openSkuRepair()" :disabled="loading || bulkSkuUpdating || repairBusy || Boolean(updatingSkuKey) || Boolean(syncingItemId)">
          {{ repairBusy ? 'Memproses SKU...' : 'Perbaiki SKU Semua Produk & Varian' }}
        </button>
        <OrphanVariantCleanup :account-key="accountKey" :account-name="accountName"
          :disabled="loading || bulkSkuUpdating || repairBusy || Boolean(updatingSkuKey) || Boolean(syncingItemId) || Boolean(deletingVariantKey)"
          @busy="cleanupBusy = $event" @completed="loadData(false)" />
        <button class="primary shopee" @click="syncAndLoad" :disabled="loading || bulkSkuUpdating || repairBusy">
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
              <option value="item_id">Item ID</option>
            </select>
            <input v-model.trim="filters.search" type="search" placeholder="Cari produk / SKU Shopee" />
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
            <template v-for="item in pagedItems" :key="item.item_id">
              <tr :class="['product-row', { 'missing-sku-row': itemHasMissingSku(item) }]">
                <td class="check-col"><input type="checkbox" /></td>
                <td>
                  <div class="product-cell">
                    <img v-if="item.image_url && !brokenImages[item.item_id]" :src="item.image_url" :alt="item.nama" @error="markImageBroken(item.item_id)" />
                    <div v-else class="thumb-fallback">{{ initials(item.nama) }}</div>
                    <div>
                      <strong>{{ item.nama }}</strong>
                      <small>Item ID: {{ item.item_id }}</small>
                      <small>Sales: {{ item.sales || 0 }} | Likes: {{ item.likes || 0 }}</small>
                      <span class="store-pill">{{ item.shop_name || 'Agni Shop Banjarmasin' }}</span>
                    </div>
                  </div>
                </td>
                <td>--</td>
                <td>
                  <strong>{{ firstRealSku(item.models, 'model_sku') || 'Tidak ada SKU' }}</strong>
                  <small>{{ modelSummary(item) }}</small>
                </td>
                <td>
                  <div class="price-cell">
                    <small v-if="hasDiscount(item)">Harga asli: {{ originalPriceRange(item) }}</small>
                    <strong>{{ hasDiscount(item) ? 'Harga diskon' : 'Harga' }}: {{ discountedPriceRange(item) }}</strong>
                    <span v-if="hasDiscount(item)" class="discount-pill">{{ discountLabel(item) }}</span>
                  </div>
                </td>
                <td>
                  <strong>{{ totalStock(item.models) }}</strong>
                  <small>{{ formatCurrency(totalValue(item.models)) }}</small>
                </td>
                <td>
                  <span :class="['status-badge', rowStatus(item).tone]">{{ rowStatus(item).label }}</span>
                  <small>{{ qualityNote(item) }}</small>
                </td>
                <td>
                  <small>Create Time</small>
                  <strong>{{ formatDate(item.created_at) }}</strong>
                  <small>Update Time</small>
                  <strong>{{ formatDate(item.updated_at) }}</strong>
                </td>
                <td>
                  <div class="actions">
                    <button title="Lihat varian" @click="toggle(item.item_id)">{{ expanded[item.item_id] ? 'Hide' : 'Show' }}</button>
                    <button title="Refresh produk ini" @click="syncProduct(item)" :disabled="syncingItemId === item.item_id">
                      {{ syncingItemId === item.item_id ? 'Syncing...' : 'Sync' }}
                    </button>
                    <button v-if="skuRepairRows(item).length" class="bulk-sku-action" @click="openSkuRepair(item)" :disabled="repairBusy || Boolean(updatingSkuKey)">
                      Perbaiki SKU ({{ skuRepairRows(item).length }})
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="expanded[item.item_id]" class="variant-row">
                <td></td>
                <td colspan="8">
                  <div class="variant-list">
                    <div v-for="model in item.models" :key="model.model_id" :class="['variant-item', { 'missing-sku-row': missingShopeeSku(model) }]">
                      <span class="variant-name">
                        <img v-if="model.image_url" :src="model.image_url" :alt="model.name || 'Varian Shopee'" />
                        <span v-else class="variant-thumb-fallback">{{ initials(model.name) }}</span>
                        <span>{{ model.name || 'Tanpa Varian' }}</span>
                      </span>
                      <span class="variant-code">
                        <small>SKU Real</small>
                        <strong>{{ shopeeRealSku(model) || 'Tidak ada SKU' }}</strong>
                        <span v-if="missingShopeeSku(model)" class="missing-sku-badge">SKU Shopee Kosong</span>
                        <span v-else-if="shopeeRealSku(model) !== templateSku(item, model)" class="missing-sku-badge">SKU tidak sesuai template</span>
                        <small>SKU Template</small>
                        <span class="copy-line">
                          <code>{{ templateSku(item, model) }}</code>
                          <button type="button" title="Copy SKU Template" @click="copyVariationCode(item, model)" :disabled="!templateSku(item, model)">Copy</button>
                        </span>
                      </span>
                      <span>SKU ID: {{ model.model_id || '-' }}</span>
                      <span class="variant-price">
                        <small v-if="modelHasDiscount(model)" class="original-price">Harga asli: {{ formatCurrency(modelOriginalPrice(model)) }}</small>
                        <small v-if="modelHasDiscount(model)" class="discount-label">Harga Diskon <span title="Diskon dari harga asli">?</span>:</small>
                        <strong>{{ modelDiscountDetail(model) }}</strong>
                      </span>
                      <strong>Stock {{ model.stock || 0 }}</strong>
                      <span class="variant-actions">
                        <button v-if="shopeeRealSku(model) !== templateSku(item, model)" class="update-sku-btn" @click="openSkuRepair(item, model)" :disabled="repairBusy || Boolean(updatingSkuKey)">Perbaiki sesuai template</button>
                        <input
                          v-model.trim="manualSkuDrafts[shopeeSkuKey(item, model)]"
                          class="manual-sku-input"
                          type="text"
                          :placeholder="templateSku(item, model) || 'Ketik SKU manual'"
                          title="Isi SKU manual jika SKU template tidak sesuai"
                          @keyup.enter="updateMissingSku(item, model)"
                        />
                        <button
                          type="button"
                          class="update-sku-btn"
                          title="Update SKU real Shopee dari input manual atau SKU template"
                          @click="updateMissingSku(item, model)"
                          :disabled="!canUpdateMissingSku(item, model) || updatingSkuKey === shopeeSkuKey(item, model)"
                        >
                          {{ updatingSkuKey === shopeeSkuKey(item, model) ? 'Updating...' : 'Update SKU' }}
                        </button>
                        <button
                          type="button"
                          class="delete-variant-btn"
                          title="Hapus varian ini dari Shopee"
                          @click="openDeleteVariantModal(item, model)"
                          :disabled="!canDeleteShopeeVariant(item, model) || deletingVariantKey === shopeeSkuKey(item, model)"
                        >
                          {{ deletingVariantKey === shopeeSkuKey(item, model) ? 'Deleting...' : 'Hapus' }}
                        </button>
                      </span>
                    </div>
                    <div v-if="!item.models?.length" class="variant-empty">Tidak ada varian tersimpan.</div>
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
        <button type="button" :disabled="currentPage === 1" @click="setPage(currentPage - 1)">Prev</button>
        <span>Halaman {{ currentPage }} dari {{ totalPages }}</span>
        <button type="button" :disabled="currentPage === totalPages" @click="setPage(currentPage + 1)">Next</button>
      </div>
    </div>

    <div v-if="repairModal.open" class="modal-backdrop" @click.self="closeSkuRepair" @keydown.esc="closeSkuRepair">
      <section class="confirm-modal repair-modal" role="dialog" aria-modal="true" aria-labelledby="repair-sku-title">
        <h2 id="repair-sku-title">Perbaiki SKU Shopee</h2>
        <p>{{ repairModal.accountName }} · {{ repairModal.productName }}</p>
        <p>Template: INT-ITEM_ID-NAMA-VARIAN. Perubahan dikirim langsung ke toko Shopee ini. Stok dan harga tetap.</p>
        <div class="repair-rows">
          <div v-for="row in repairModal.rows" :key="`${row.itemId}:${row.modelId}`" class="repair-row">
            <small v-if="repairModal.allProducts">{{ row.productName }} · Item ID: {{ row.itemId }}</small>
            <strong>{{ row.name }}</strong>
            <small>SKU saat ini</small><code>{{ row.current || '(kosong)' }}</code>
            <small>SKU yang benar</small><code>{{ row.target }}</code>
            <p v-if="row.blocked || row.status" :class="{ 'modal-error': row.blocked || row.status === 'Gagal' }">{{ row.blocked || row.message || row.status }}</p>
          </div>
        </div>
        <p v-if="repairModal.message" role="status">{{ repairModal.message }}</p>
        <div class="modal-actions">
          <button class="ghost" @click="closeSkuRepair" :disabled="repairBusy">Tutup</button>
          <button class="bulk-sku-action" @click="submitSkuRepair" :disabled="repairBusy || !repairPending.length">{{ repairBusy ? 'Memperbaiki...' : `Perbaiki ${repairPending.length} SKU` }}</button>
        </div>
      </section>
    </div>

    <div v-if="bulkSkuModal.open" class="modal-backdrop" @click.self="closeBulkSkuModal">
      <section class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-sku-title">
        <h2 id="bulk-sku-title">Isi semua SKU Shopee kosong?</h2>
        <p class="bulk-sku-copy">{{ missingSkuVariantCount }} varian akan memakai SKU template internal. SKU Shopee yang sudah terisi tidak akan diubah.</p>
        <div class="modal-actions">
          <button type="button" class="ghost" @click="closeBulkSkuModal" :disabled="bulkSkuUpdating">Batal</button>
          <button type="button" class="bulk-sku-action" @click="confirmBulkSkuUpdate" :disabled="bulkSkuUpdating">
            {{ bulkSkuUpdating ? 'Mengisi SKU...' : 'Isi Semua SKU' }}
          </button>
        </div>
      </section>
    </div>

    <div v-if="deleteModal.open" class="modal-backdrop" @click.self="closeDeleteVariantModal">
      <section class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="delete-variant-title">
        <h2 id="delete-variant-title">Hapus Varian Shopee</h2>
        <p class="danger-copy">Aksi ini akan menghapus varian langsung dari marketplace Shopee.</p>
        <div class="delete-details">
          <div>
            <span>Produk</span>
            <strong>{{ deleteModal.productName }}</strong>
          </div>
          <div>
            <span>Varian</span>
            <strong>{{ deleteModal.modelName }}</strong>
          </div>
          <div>
            <span>Item ID</span>
            <strong>{{ deleteModal.itemId }}</strong>
          </div>
          <div>
            <span>Model ID</span>
            <strong>{{ deleteModal.modelId }}</strong>
          </div>
        </div>
        <label class="confirm-field">
          <span>Ketik Model ID untuk konfirmasi</span>
          <input v-model.trim="deleteModal.confirmModelId" type="text" :placeholder="deleteModal.modelId" />
        </label>
        <p v-if="deleteModal.error" class="modal-error">{{ deleteModal.error }}</p>
        <div class="modal-actions">
          <button type="button" class="ghost" @click="closeDeleteVariantModal" :disabled="Boolean(deletingVariantKey)">Batal</button>
          <button
            type="button"
            class="danger-action"
            @click="confirmDeleteVariant"
            :disabled="deleteModal.confirmModelId !== deleteModal.modelId || Boolean(deletingVariantKey)"
          >
            {{ deletingVariantKey ? 'Menghapus...' : 'Hapus dari Shopee' }}
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
import { shopeeTemplateSku, shopeeSkuRepairRows as skuRepairRows, shopeeAllSkuRepairRows } from './shopeeSkuRepairState'

const props = defineProps({
  accountKey: { type: String, default: '' },
  accountName: { type: String, default: 'Shopee' },
  unified: { type: Boolean, default: false }
})

const accountParams = () => props.accountKey ? { account_key: props.accountKey } : {}
const cleanupBusy = ref(false)
const repairBusy = ref(false)
const repairModal = reactive({ open: false, rows: [], allProducts: false, accountKey: '', accountName: '', productName: '', message: '' })
const repairPending = computed(() => repairModal.rows.filter(row => !row.blocked && !row.status))
let repairMounted = true
onBeforeUnmount(() => { repairMounted = false })
const closeSkuRepair = () => { if (!repairBusy.value) repairModal.open = false }
const openSkuRepair = async (item = null, model = null) => {
  if (repairBusy.value) return
  repairBusy.value = true
  const accountKey = props.accountKey || 'shopee-agnishopbjm'
  Object.assign(repairModal, {
    open: true, rows: [], allProducts: !item, accountKey, accountName: props.accountName,
    productName: item?.nama || 'Semua produk dan varian toko ini (termasuk di luar filter / halaman)',
    message: item ? 'Mengambil data produk terbaru...' : 'Mengambil seluruh produk terbaru dari Shopee. Proses ini dapat memerlukan beberapa menit...'
  })
  try {
    const response = await omnichannelService.shopeeItems(true, { account_key: accountKey, ...(item ? { item_id: item.item_id } : {}) })
    if (!repairMounted) return
    if (response.data.sync?.status !== 'ok') throw new Error(response.data.sync?.message || 'Sync produk gagal.')
    items.value = response.data.items || []
    const fresh = item ? items.value.find(candidate => String(candidate.item_id) === String(item.item_id)) : null
    if (item && !fresh) throw new Error('Produk tidak ditemukan pada toko ini.')
    const rows = (item ? skuRepairRows(fresh) : shopeeAllSkuRepairRows(items.value))
      .filter(row => !model || row.modelId === String(model.model_id))
    Object.assign(repairModal, { rows, message: rows.length
      ? `${rows.length} SKU tidak sesuai template pada ${new Set(rows.map(row => row.itemId)).size} produk. Periksa preview sebelum memperbaiki.`
      : 'Semua SKU sudah sesuai template.' })
  } catch (error) {
    repairModal.rows = []
    repairModal.message = error.response?.data?.message || error.message
    syncMessage.value = error.response?.data?.message || error.message
    syncTone.value = 'error'
  } finally { repairBusy.value = false }
}
const submitSkuRepair = async () => {
  if (repairBusy.value) return
  repairBusy.value = true
  const pending = [...repairPending.value]
  try {
    for (const row of pending) {
      if (!repairMounted) break
      row.status = 'Memproses'
      try {
        const response = await omnichannelService.updateMarketplaceVariantSku({
          channel: 'shopee', account_key: repairModal.accountKey, item_id: row.itemId,
          model_id: row.modelId, seller_sku: row.target, repair_template: true,
          expected_sku: row.current, expected_name: row.name
        })
        if (response.data?.status !== 'ok') throw new Error(response.data?.response?.message || 'Shopee menolak perubahan.')
        row.status = 'Berhasil'
        const item = items.value.find(item => String(item.item_id) === row.itemId)
        const model = item?.models?.find(model => String(model.model_id) === row.modelId)
        if (model) model.model_sku = response.data.seller_sku
      } catch (error) {
        row.status = 'Gagal'
        row.message = error.response?.data?.response?.message || error.response?.data?.message || error.message
      }
      repairModal.message = `Diproses ${pending.filter(candidate => ['Berhasil', 'Gagal'].includes(candidate.status)).length} / ${pending.length} SKU.`
    }
    repairModal.message = `Berhasil ${repairModal.rows.filter(row => row.status === 'Berhasil').length} | Gagal ${repairModal.rows.filter(row => row.status === 'Gagal').length} | Perlu diperiksa ${repairModal.rows.filter(row => row.blocked).length}. Tutup dan buka ulang untuk mengecek data terbaru.`
  } finally { repairBusy.value = false }
}

const items = ref([])
const expanded = ref({})
const loading = ref(false)
const syncingItemId = ref('')
const updatingSkuKey = ref('')
const bulkSkuUpdating = ref(false)
const deletingVariantKey = ref('')
const manualSkuDrafts = reactive({})
const activeTab = ref('live')
const page = ref(1)
const PAGE_SIZE = 20
const brokenImages = ref({})
const syncMessage = ref('')
const syncTone = ref('info')
const copyTimer = ref(null)
const bulkSkuModal = reactive({ open: false })
const deleteModal = reactive({
  open: false,
  itemId: '',
  modelId: '',
  productName: '',
  modelName: '',
  confirmModelId: '',
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
const totalStock = (models) => (models || []).reduce((sum, item) => sum + Number(item.stock || 0), 0)
const totalValue = (models) => (models || []).reduce((sum, item) => sum + Number(item.stock || 0) * Number(item.price || 0), 0)
const numberValue = (value) => Number(value || 0)
const isSoldOut = (item) => totalStock(item.models) <= 0
const isLive = (item) => Boolean(item.is_live)
const liveCount = computed(() => items.value.filter((item) => isLive(item) && !isSoldOut(item)).length)
const soldOutCount = computed(() => items.value.filter((item) => isSoldOut(item)).length)
const inactiveCount = computed(() => items.value.filter((item) => !isLive(item)).length)
const variantCount = computed(() => items.value.reduce((sum, item) => sum + (item.models?.length || 0), 0))
const grandStock = computed(() => visibleItems.value.reduce((sum, item) => sum + totalStock(item.models), 0))
const grandValue = computed(() => visibleItems.value.reduce((sum, item) => sum + totalValue(item.models), 0))
const storeOptions = computed(() => [...new Set(items.value.map((item) => item.shop_name || 'Agni Shop Banjarmasin'))].sort())

const normalizedSearch = computed(() => filters.search.toLowerCase())
const shopeeSkuHaystack = (item) => [
  item.sku,
  item.item_id,
  ...(item.models || []).flatMap((model) => [
    model.model_sku,
    model.kode_variasi,
    model.model_id,
    model.name,
    variationCode(item, model)
  ])
].filter(Boolean).join(' ')
const shopeeSearchHaystack = (item) => ({
  all: [item.nama, item.item_id, shopeeSkuHaystack(item)].filter(Boolean).join(' '),
  name: item.nama,
  sku: shopeeSkuHaystack(item),
  item_id: item.item_id
}[filters.searchBy] || item.nama)

const filteredItems = computed(() => {
  const query = normalizedSearch.value

  return items.value
    .filter((item) => {
      if (activeTab.value === 'live' && (!isLive(item) || isSoldOut(item))) return false
      if (activeTab.value === 'soldout' && !isSoldOut(item)) return false
      if (activeTab.value === 'inactive' && isLive(item)) return false
      if (filters.status === 'live' && (!isLive(item) || isSoldOut(item))) return false
      if (filters.status === 'soldout' && !isSoldOut(item)) return false
      if (filters.status === 'inactive' && isLive(item)) return false
      if (filters.store !== 'all' && (item.shop_name || 'Agni Shop Banjarmasin') !== filters.store) return false
      if (Number(filters.minimumStock || 0) > 0 && totalStock(item.models) < Number(filters.minimumStock)) return false
      if (filters.price === 'high' && Number(item.price_max || item.price_min || 0) <= 50000) return false
      if (filters.price === 'promo' && Number(item.price_min || 0) >= Number(item.price_max || item.price_min || 0)) return false
      if (!query) return true

      return String(shopeeSearchHaystack(item) || '').toLowerCase().includes(query)
    })
    .sort((a, b) => {
      const missingGroupDifference = Number(itemHasMissingSku(b)) - Number(itemHasMissingSku(a))
      if (missingGroupDifference !== 0) return missingGroupDifference

      if (filters.sort === 'stock_desc') return totalStock(b.models) - totalStock(a.models)
      if (filters.sort === 'sales_desc') return Number(b.sales || 0) - Number(a.sales || 0)
      if (filters.sort === 'name_asc') return String(a.nama || '').localeCompare(String(b.nama || ''))
      if (filters.sort === 'created_desc') return new Date(b.created_at || 0) - new Date(a.created_at || 0)
      return new Date(b.updated_at || 0) - new Date(a.updated_at || 0)
    })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredItems.value.length / PAGE_SIZE)))
const currentPage = computed(() => Math.min(page.value, totalPages.value))
const visibleItems = filteredItems
const pagedItems = computed(() => {
  const start = (currentPage.value - 1) * PAGE_SIZE

  return filteredItems.value.slice(start, start + PAGE_SIZE)
})

const setPage = (nextPage) => {
  page.value = Math.min(Math.max(Number(nextPage) || 1, 1), totalPages.value)
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
    if (shopeeSkuHaystack(item).toLowerCase().includes(query)) {
      expanded.value[item.item_id] = true
    }
  })
})

watch(() => filters.searchBy, () => {
  page.value = 1
})

const toggle = (id) => {
  expanded.value[id] = !expanded.value[id]
}

const markImageBroken = (id) => {
  brokenImages.value[id] = true
}

const initials = (name) => String(name || 'SP').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const marketplaceSku = (value) => {
  const sku = String(value || '').trim()
  return sku === '-' ? '' : sku
}
const skuFragment = (value) => String(value || 'VARIAN').trim().toUpperCase().replace(/[^A-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30) || 'VARIAN'
const shopeeRealSku = (model) => marketplaceSku(model?.model_sku)
const firstRealSku = (rows, key) => (rows || []).map((row) => marketplaceSku(row?.[key])).find(Boolean) || ''
const missingShopeeSku = (model) => !shopeeRealSku(model)
const itemHasMissingSku = (item) => (item?.models || []).some((model) => missingShopeeSku(model))
const missingSkuVariantCount = computed(() => items.value.reduce(
  (count, item) => count + (item.models || []).filter(missingShopeeSku).length,
  0
))
const templateSku = shopeeTemplateSku
const variationCode = templateSku
const shopeeSkuKey = (item, model) => `${item?.item_id || ''}:${model?.model_id || ''}`
const manualSkuValue = (key) => String(manualSkuDrafts[key] || '').trim()
const targetSku = (item, model) => manualSkuValue(shopeeSkuKey(item, model)) || templateSku(item, model)
const canUpdateMissingSku = (item, model) => Boolean(item?.item_id && model?.model_id && targetSku(item, model))
const canDeleteShopeeVariant = (item, model) => {
  const modelId = String(model?.model_id || '').trim()

  return Boolean(item?.item_id && modelId && modelId !== '0' && (item?.models?.length || 0) > 1)
}
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
const copyVariationCode = (item, model) => copyText(variationCode(item, model))
const updateMissingSku = async (item, model) => {
  if (!canUpdateMissingSku(item, model)) return

  const key = shopeeSkuKey(item, model)
  updatingSkuKey.value = key
  syncMessage.value = ''

  try {
    const sellerSku = targetSku(item, model)
    const response = await omnichannelService.updateMarketplaceVariantSku({
      channel: 'shopee',
      ...accountParams(),
      item_id: item.item_id,
      model_id: model.model_id,
      seller_sku: sellerSku
    })

    model.model_sku = sellerSku
    delete manualSkuDrafts[key]
    syncMessage.value = response.data?.message || `SKU Shopee diupdate: ${sellerSku}`
    syncTone.value = response.data?.status === 'error' ? 'error' : 'success'
  } catch (error) {
    syncMessage.value = error.response?.data?.response?.message || error.response?.data?.message || 'Update SKU Shopee gagal.'
    syncTone.value = 'error'
  } finally {
    updatingSkuKey.value = ''
  }
}
const openDeleteVariantModal = (item, model) => {
  if (!canDeleteShopeeVariant(item, model)) {
    syncMessage.value = 'Varian ini tidak bisa dihapus dari tool karena model default atau varian terakhir.'
    syncTone.value = 'warning'
    return
  }

  deleteModal.open = true
  deleteModal.itemId = String(item?.item_id || '')
  deleteModal.modelId = String(model?.model_id || '')
  deleteModal.productName = String(item?.nama || '-')
  deleteModal.modelName = String(model?.name || 'Tanpa Varian')
  deleteModal.confirmModelId = ''
  deleteModal.error = ''
}
const closeDeleteVariantModal = () => {
  if (deletingVariantKey.value) return

  deleteModal.open = false
  deleteModal.itemId = ''
  deleteModal.modelId = ''
  deleteModal.productName = ''
  deleteModal.modelName = ''
  deleteModal.confirmModelId = ''
  deleteModal.error = ''
}
const removeDeletedVariantFromState = (itemId, modelId) => {
  const item = items.value.find((row) => String(row.item_id) === String(itemId))
  if (!item) return

  item.models = (item.models || []).filter((model) => String(model.model_id) !== String(modelId))
  item.stok = totalStock(item.models)
}
const confirmDeleteVariant = async () => {
  if (deleteModal.confirmModelId !== deleteModal.modelId || deletingVariantKey.value) return

  const key = `${deleteModal.itemId}:${deleteModal.modelId}`
  deletingVariantKey.value = key
  deleteModal.error = ''
  syncMessage.value = ''
  let shouldCloseDeleteModal = false

  try {
    const response = await omnichannelService.shopeeDeleteVariant({
      item_id: deleteModal.itemId,
      model_id: deleteModal.modelId,
      confirm_model_id: deleteModal.confirmModelId
    })

    removeDeletedVariantFromState(deleteModal.itemId, deleteModal.modelId)
    syncMessage.value = response.data?.message || 'Varian Shopee berhasil dihapus.'
    syncTone.value = response.data?.status === 'error' ? 'error' : 'success'
    shouldCloseDeleteModal = true
  } catch (error) {
    const message = error.response?.data?.message || error.response?.data?.response?.message || 'Hapus varian Shopee gagal.'
    deleteModal.error = message
    syncMessage.value = message
    syncTone.value = 'error'
  } finally {
    deletingVariantKey.value = ''
    if (shouldCloseDeleteModal) closeDeleteVariantModal()
  }
}
const modelSummary = (item) => `${item.models?.length || 0} varian`
const qualityNote = (item) => isSoldOut(item) ? 'Stok perlu dicek' : 'Produk sedang dijual'
const rowStatus = (item) => {
  if (isSoldOut(item)) return { label: 'Sold Out', tone: 'warning' }
  if (!isLive(item)) return { label: item.status || 'Tidak Live', tone: 'muted' }
  return { label: 'Live', tone: 'success' }
}
const priceRange = (item) => {
  const prices = (item.models || []).map((model) => numberValue(model.price)).filter(Boolean)
  const min = Math.min(...prices, numberValue(item.price_min) || Infinity)
  const max = Math.max(...prices, numberValue(item.price_max) || 0)

  if (!Number.isFinite(min) || !max) return '-'
  return min === max ? formatCurrency(min) : `${formatCurrency(min)} - ${formatCurrency(max)}`
}
const modelOriginalPrice = (model) => Math.max(numberValue(model.original_price), numberValue(model.price))
const modelDiscountPrice = (model) => numberValue(model.price)
const modelHasDiscount = (model) => modelOriginalPrice(model) > numberValue(model.price)
const modelDiscountPercent = (model) => {
  const original = modelOriginalPrice(model)
  const current = numberValue(model.price)

  if (!original || current >= original) return 0
  return Math.round(((original - current) / original) * 100)
}
const modelDiscountDetail = (model) => {
  const current = formatCurrency(modelDiscountPrice(model))
  const percent = modelDiscountPercent(model)

  return percent ? `${current} (${percent}% DISKON)` : current
}
const modelPrices = (item, key) => (item.models || [])
  .map((model) => key === 'original' ? modelOriginalPrice(model) : numberValue(model.price))
  .filter(Boolean)
const rangeText = (values, fallback = 0) => {
  const prices = values.filter(Boolean)
  if (fallback) prices.push(numberValue(fallback))
  if (!prices.length) return '-'

  const min = Math.min(...prices)
  const max = Math.max(...prices)
  return min === max ? formatCurrency(min) : `${formatCurrency(min)} - ${formatCurrency(max)}`
}
const discountedPriceRange = (item) => priceRange(item)
const originalPriceRange = (item) => rangeText(modelPrices(item, 'original'), numberValue(item.price_before_discount) || numberValue(item.price_max))
const hasDiscount = (item) => {
  const currentPrices = modelPrices(item, 'current')
  const originalPrices = modelPrices(item, 'original')
  const currentMin = currentPrices.length ? Math.min(...currentPrices) : numberValue(item.price_min)
  const originalMax = originalPrices.length ? Math.max(...originalPrices) : Math.max(numberValue(item.price_before_discount), numberValue(item.price_max))

  return originalMax > currentMin
}
const discountLabel = (item) => {
  const percents = (item.models || []).map(modelDiscountPercent).filter(Boolean)
  if (!percents.length && hasDiscount(item)) {
    const current = numberValue(item.price_min)
    const original = Math.max(numberValue(item.price_before_discount), numberValue(item.price_max))
    const percent = original && current < original ? Math.round(((original - current) / original) * 100) : 0
    return percent ? `-${percent}%` : 'Promo'
  }

  const min = Math.min(...percents)
  const max = Math.max(...percents)
  return min === max ? `-${min}%` : `-${min}% s/d -${max}%`
}
const formatDate = (value) => {
  if (!value) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(new Date(value))
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

const openBulkSkuModal = () => {
  if (loading.value || bulkSkuUpdating.value || missingSkuVariantCount.value === 0) return
  bulkSkuModal.open = true
}

const closeBulkSkuModal = () => {
  if (!bulkSkuUpdating.value) bulkSkuModal.open = false
}

const confirmBulkSkuUpdate = async () => {
  bulkSkuUpdating.value = true
  syncMessage.value = ''

  try {
    const { data } = await omnichannelService.bulkUpdateShopeeEmptyVariantSkus()
    const summary = [
      data.message || 'Pengisian SKU Shopee selesai.',
      `Kandidat: ${data.total_candidates || 0}`,
      `Berhasil: ${data.updated || 0}`,
      `Dilewati: ${data.skipped || 0}`,
      `Gagal: ${data.failed || 0}`
    ].join(' | ')

    await loadData(false)
    syncMessage.value = summary
    syncTone.value = data.status === 'success' ? 'success' : 'warning'
    bulkSkuModal.open = false
  } catch (error) {
    syncMessage.value = error.response?.data?.message || 'Pengisian SKU Shopee gagal.'
    syncTone.value = 'error'
  } finally {
    bulkSkuUpdating.value = false
  }
}

const loadData = async (syncMode = false) => {
  loading.value = true
  syncMessage.value = ''
  try {
    const response = await omnichannelService.shopeeItems(syncMode, accountParams())
    items.value = response.data.items || []
    const syncResult = response.data.sync

    if (syncResult?.message) {
      syncMessage.value = syncResult.message
      syncTone.value = syncResult.status === 'error' ? 'error' : syncResult.status === 'partial' ? 'warning' : syncResult.status === 'cached' ? 'info' : 'success'
    }

    page.value = 1
  } catch (error) {
    syncMessage.value = error.response?.data?.message || 'Pengambilan produk Shopee gagal.'
    syncTone.value = 'error'
  } finally {
    loading.value = false
  }
}

const syncAndLoad = async () => {
  await loadData(true)
}

const syncProduct = async (item) => {
  syncingItemId.value = item.item_id
  syncMessage.value = ''
  try {
    const response = await omnichannelService.shopeeItems(true, { ...accountParams(), item_id: item.item_id })
    items.value = response.data.items || []
    syncMessage.value = response.data.sync?.message || response.data.message || ''
    syncTone.value = response.data.sync?.status === 'error' ? 'error' : 'success'
  } catch (error) {
    syncMessage.value = error.response?.data?.message || 'Sinkronisasi produk Shopee gagal.'
    syncTone.value = 'error'
  } finally {
    syncingItemId.value = ''
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
button, input, select { font-size: 13px; }
button { border: 0; border-radius: 6px; padding: 9px 12px; cursor: pointer; }
.primary { color: #fff; }
.primary:disabled { opacity: .65; cursor: wait; }
.shopee { background: #ee4d2d; }
.bulk-sku-action { color: #fff; background: #1d4ed8; }
.bulk-sku-action:disabled { cursor: not-allowed; opacity: .55; }
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
.tabs button.active { color: #4f2ec7; border-bottom-color: #4f2ec7; }
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
.product-row.missing-sku-row { background: #dbeafe; box-shadow: inset 4px 0 0 #2563eb; }
.product-row.missing-sku-row:hover { background: #bfdbfe; }
.product-cell { display: grid; grid-template-columns: 72px 1fr; gap: 10px; min-width: 380px; }
.product-cell img, .thumb-fallback { width: 72px; height: 72px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.thumb-fallback { display: grid; place-items: center; color: #64748b; font-weight: 800; }
strong { display: block; color: #0f172a; line-height: 1.35; }
small { display: block; color: #64748b; line-height: 1.55; }
.store-pill { display: inline-block; margin-top: 6px; padding: 4px 8px; color: #64748b; background: #f6f7fb; border-radius: 4px; font-size: 12px; }
.price-cell, .variant-price { display: grid; gap: 3px; align-content: start; }
.price-cell small, .variant-price .original-price { text-decoration: line-through; color: #94a3b8; }
.variant-price strong { font-size: 13px; }
.discount-label { color: #64748b; font-size: 11px; line-height: 1.25; text-decoration: none; }
.discount-label span { display: inline-grid; place-items: center; width: 13px; height: 13px; border: 1px solid #cbd5e1; border-radius: 999px; color: #64748b; font-size: 9px; font-weight: 700; }
.discount-pill { display: inline-flex; width: max-content; align-items: center; border-radius: 999px; padding: 2px 7px; color: #b42318; background: #fee4e2; font-size: 11px; font-weight: 800; line-height: 1.4; }
.status-badge { display: inline-block; border-radius: 999px; padding: 4px 8px; margin-bottom: 4px; font-size: 12px; font-weight: 700; }
.success { color: #047857; background: #d1fae5; }
.warning { color: #b45309; background: #fef3c7; }
.muted { color: #64748b; background: #eef2f7; }
.actions { display: grid; gap: 6px; }
.actions button { color: #4f2ec7; background: #f1efff; padding: 7px 9px; }
.variant-row td { background: #fafafa; padding-top: 0; }
.variant-list { border-top: 1px dashed #d7dde8; padding-top: 8px; display: grid; gap: 6px; }
.variant-item { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, .8fr) minmax(0, .65fr) minmax(0, .35fr) minmax(132px, .48fr); gap: 10px; padding: 8px; background: #fff; border: 1px solid #edf0f5; border-radius: 6px; }
.variant-item.missing-sku-row { background: #dbeafe; border-color: #2563eb; box-shadow: inset 4px 0 0 #2563eb; }
.variant-name { display: grid; grid-template-columns: 42px minmax(0, 1fr); gap: 10px; align-items: center; }
.variant-name img, .variant-thumb-fallback { width: 42px; height: 42px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.variant-thumb-fallback { display: grid; place-items: center; color: #64748b; font-size: 11px; font-weight: 800; }
.variant-code { display: grid; gap: 3px; min-width: 0; align-content: start; }
.variant-code code { color: #0f172a; font-family: inherit; font-size: 12px; font-weight: 700; line-height: 1.4; overflow-wrap: anywhere; }
.missing-sku-badge { display: inline-flex; width: max-content; align-items: center; border-radius: 4px; padding: 3px 6px; color: #fff; background: #2563eb; font-size: 10px; font-weight: 800; line-height: 1.3; }
.variant-code small { color: #64748b; font-size: 11px; line-height: 1.25; }
.variant-code strong { font-size: 12px; overflow-wrap: anywhere; }
.copy-line { display: flex; align-items: center; gap: 6px; min-width: 0; }
.variant-code button { flex: 0 0 auto; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 4px; padding: 4px 7px; font-size: 11px; font-weight: 700; }
.variant-code button:disabled { cursor: not-allowed; opacity: .45; }
.variant-actions { display: grid; gap: 6px; align-content: start; justify-items: stretch; }
.manual-sku-input { width: 100%; min-width: 0; height: 30px; font-size: 12px; padding: 0 8px; }
.update-sku-btn { border: 1px solid #2563eb; background: #dbeafe; color: #1d4ed8; border-radius: 5px; padding: 6px 8px; font-size: 11px; font-weight: 800; }
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
.repair-modal > p { margin: 10px 0; color: #475569; }
.repair-rows { max-height: 50vh; overflow: auto; }
.repair-row { display: grid; gap: 5px; padding: 12px; border: 1px solid #d9e2ec; border-radius: 6px; margin: 8px 0; }
.repair-row small { color: #64748b; }
.repair-row code { overflow-wrap: anywhere; }
.confirm-modal h2 { color: #0f172a; font-size: 20px; letter-spacing: 0; margin: 0 0 8px; }
.bulk-sku-copy { color: #1e3a8a; background: #dbeafe; border: 1px solid #93c5fd; border-radius: 6px; font-size: 13px; line-height: 1.45; margin: 0 0 12px; padding: 10px; }
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

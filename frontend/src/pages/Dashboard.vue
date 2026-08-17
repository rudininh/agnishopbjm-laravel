<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>AgniShop Banjarmasin</p>
        <h1>Dashboard Omnichannel</h1>
      </div>
      <button class="primary" @click="loadData" :disabled="loading">
        {{ loading ? 'Memuat...' : 'Refresh' }}
      </button>
    </header>

    <div class="stats">
      <article v-for="card in cards" :key="card.label" class="stat">
        <span>{{ card.label }}</span>
        <strong>{{ formatNumber(card.value) }}</strong>
      </article>
    </div>

    <section class="panel">
      <div class="panel-title">
        <h2>Status Integrasi</h2>
      </div>
      <p v-if="loadError" class="action-message error">{{ loadError }}</p>
      <div class="integration-grid">
        <article class="integration" v-for="account in marketplaceAccounts" :key="account.key">
          <span>{{ account.name }}</span>
          <strong>{{ accountStatus(account) }}</strong>
          <small>{{ accountLatestDate(account) }}</small>
        </article>
        <article class="integration">
          <span>Database</span>
          <strong>Aktif</strong>
          <small>{{ data.database?.name || '-' }}</small>
        </article>
      </div>
    </section>

    <section class="panel">
      <div class="panel-title">
        <h2>Aksi Cepat</h2>
      </div>
      <div class="quick-actions">
        <RouterLink class="action shopee" to="/stok-shopee">Stok Shopee</RouterLink>
        <RouterLink class="action tiktok" to="/stok-tiktok">Stok TikTok</RouterLink>
        <RouterLink class="action master" to="/stock-master">Stock Master</RouterLink>
        <RouterLink class="action sync" to="/marketplace/auto-sync">Auto Sync</RouterLink>
      </div>
    </section>

    <section class="panel">
      <div class="panel-title">
        <div>
          <h2>Token Marketplace</h2>
          <p>Kelola auth dan refresh token Shopee/TikTok.</p>
        </div>
        <button
          class="primary"
          :disabled="busyAction === 'stb-token-sync'"
          @click="pullStbMarketplaceTokens"
        >
          {{ busyAction === 'stb-token-sync' ? 'Menarik token...' : 'Tarik Token dari STB' }}
        </button>
      </div>

      <div class="token-accounts">
        <article v-for="account in marketplaceAccounts" :key="account.key" :class="['token-account', account.channel]">
          <div class="token-account-head">
            <span>{{ account.channelLabel }}</span>
            <strong>{{ account.name }}</strong>
          </div>
          <div class="token-actions">
            <button
              :class="['token-button', account.channel]"
              :disabled="busyAction === account.connectAction"
              @click="runTokenAction(account.connectAction)"
            >
              <span>{{ busyAction === account.connectAction ? 'Memproses...' : 'AUTH / REFRESH' }}</span>
            </button>
          </div>
        </article>
      </div>

      <p v-if="message" class="action-message">{{ message }}</p>

      <div class="token-tables">
        <section v-for="section in tokenTableSections" :key="section.key" class="token-history">
          <div class="token-history-head">
            <div>
              <span>{{ section.channelLabel }}</span>
              <h3>{{ section.name }}</h3>
            </div>
            <small>{{ section.rows.length }} riwayat token</small>
          </div>

          <div class="token-table-wrap">
            <table class="token-table">
              <thead>
                <tr>
                  <th>Akun</th>
                  <th>Status</th>
                  <th>Shop ID</th>
                  <th>Access Token</th>
                  <th>Refresh Token</th>
                  <th>Expire</th>
                  <th>Request ID</th>
                  <th>Dibuat</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="token in section.pageRows" :key="`${section.key}-${token.id || token.created_at}`">
                  <td>{{ token.account_name || section.name }}</td>
                  <td>
                    <span :class="['token-status', token.is_active ? 'active' : 'inactive']">
                      {{ token.is_active ? 'Aktif' : 'Nonaktif' }}
                    </span>
                  </td>
                  <td>{{ token.shop_id || '-' }}</td>
                  <td>{{ token.access_token_available ? 'Tersedia' : 'Tidak tersedia' }}</td>
                  <td>{{ token.refresh_token_available ? 'Tersedia' : 'Tidak tersedia' }}</td>
                  <td>{{ token.expire_at || token.expire_in || '-' }}</td>
                  <td class="mono">{{ token.request_id || '-' }}</td>
                  <td>{{ token.created_at || '-' }}</td>
                </tr>
                <tr v-if="!section.rows.length">
                  <td colspan="8" class="empty-state">Belum ada token {{ section.name }} tersimpan.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="section.totalPages > 1" class="pagination">
            <button type="button" :disabled="section.page === 1" @click="setTokenPage(section.key, section.page - 1)">
              Prev
            </button>
            <span>Halaman {{ section.page }} dari {{ section.totalPages }}</span>
            <button type="button" :disabled="section.page === section.totalPages" @click="setTokenPage(section.key, section.page + 1)">
              Next
            </button>
          </div>
        </section>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { omnichannelService } from '@/services'

const loading = ref(false)
const busyAction = ref('')
const message = ref('')
const loadError = ref('')
const data = ref({ summary: {}, tokens: {} })
const tokenPages = ref({})
const TOKEN_PAGE_SIZE = 5

const marketplaceAccounts = [
  {
    key: 'shopee-agnishopbjm',
    name: 'Shopee AgniShopBJM',
    channel: 'shopee',
    channelLabel: 'Shopee',
    connectAction: 'connect-shopee-agnishopbjm'
  },
  {
    key: 'shopee-gitacollectionbjm',
    name: 'Shopee GitaCollectionBJM',
    channel: 'shopee',
    channelLabel: 'Shopee',
    connectAction: 'connect-shopee-gitacollectionbjm'
  },
  {
    key: 'tiktok-agnishopbjm',
    name: 'TikTok AgniShopBJM',
    channel: 'tiktok',
    channelLabel: 'TikTok Shop',
    connectAction: 'connect-tiktok-agnishopbjm'
  }
]

const cards = computed(() => [
  { label: 'Stock Master', value: data.value.summary?.stock_master || 0 },
  { label: 'Produk Shopee', value: data.value.summary?.shopee_products || 0 },
  { label: 'Varian Shopee', value: data.value.summary?.shopee_variants || 0 },
  { label: 'Produk TikTok', value: data.value.summary?.tiktok_products || 0 },
  { label: 'SKU TikTok', value: data.value.summary?.tiktok_skus || 0 },
  { label: 'SKU Mapping', value: data.value.summary?.sku_mappings || 0 }
])

const shopeeTokenRows = computed(() => {
  const rows = data.value.token_rows?.shopee || []

  if (rows.length) {
    return rows
  }

  return data.value.tokens?.shopee ? [data.value.tokens.shopee] : []
})

const tiktokTokenRows = computed(() => {
  const rows = data.value.token_rows?.tiktok || []

  if (rows.length) {
    return rows
  }

  return data.value.tokens?.tiktok ? [data.value.tokens.tiktok] : []
})

const tokenTableSections = computed(() => marketplaceAccounts.map((account) => {
  const sourceRows = account.channel === 'shopee' ? shopeeTokenRows.value : tiktokTokenRows.value
  const rows = sourceRows.filter((token) => (token.account_key || account.key) === account.key)
  const totalPages = Math.max(1, Math.ceil(rows.length / TOKEN_PAGE_SIZE))
  const page = Math.min(tokenPages.value[account.key] || 1, totalPages)
  const start = (page - 1) * TOKEN_PAGE_SIZE

  return {
    ...account,
    rows,
    page,
    totalPages,
    pageRows: rows.slice(start, start + TOKEN_PAGE_SIZE)
  }
}))

const formatNumber = (value) => new Intl.NumberFormat('id-ID').format(value || 0)

const accountLabel = (key) => marketplaceAccounts.find((account) => account.key === key)?.name || '-'

const accountToken = (account) => {
  if (account.channel === 'shopee') {
    return shopeeTokenRows.value.find((token) => token.account_key === account.key)
  }

  return tiktokTokenRows.value.find((token) => (token.account_key || account.key) === account.key) || null
}

const accountStatus = (account) => accountToken(account) ? 'Terhubung' : 'Belum ada token'

const accountLatestDate = (account) => {
  const token = accountToken(account)

  return token?.created_at || token?.expire_at || '-'
}

const loadData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const response = await omnichannelService.dashboard()
    data.value = response.data
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Dashboard gagal memuat data dari API.'
  } finally {
    loading.value = false
  }
}

const setTokenPage = (key, page) => {
  tokenPages.value = {
    ...tokenPages.value,
    [key]: page
  }
}

const runTokenAction = async (action) => {
  busyAction.value = action
  message.value = ''

  try {
    const response = await omnichannelService.runTokenAction(action)
    message.value = response.data.message || 'Aksi berhasil diproses.'

    if (response.data.redirect_url) {
      window.location.href = response.data.redirect_url
      return
    }

    await loadData()
  } catch (error) {
    message.value = error.response?.data?.message || 'Aksi gagal diproses.'
  } finally {
    busyAction.value = ''
  }
}

const pullStbMarketplaceTokens = async () => {
  busyAction.value = 'stb-token-sync'
  message.value = ''

  try {
    const response = await omnichannelService.pullStbMarketplaceTokens()
    message.value = response.data?.data?.message || 'Sinkron token STB selesai.'
    await loadData()
  } catch (error) {
    message.value = error.response?.data?.message || 'Tarik token dari STB gagal.'
  } finally {
    busyAction.value = ''
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 28px; }
.page-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
.page-header p { color: #64748b; margin-bottom: 4px; }
h1 { font-size: 28px; }
.primary { background: #0f5fc7; color: #fff; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
.primary:disabled { cursor: wait; opacity: .72; }
.stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
.stat, .panel, .integration { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; }
.stat { padding: 16px; }
.stat span, .integration span { color: #64748b; display: block; margin-bottom: 6px; }
.stat strong { font-size: 26px; }
.panel { padding: 18px; margin-bottom: 18px; }
.panel-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; margin-bottom: 12px; }
.panel-title h2 { font-size: 18px; }
.panel-title p { color: #64748b; font-size: 13px; margin-top: 4px; }
.integration-grid, .quick-actions { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.integration { padding: 14px; }
.integration small { color: #64748b; display: block; margin-top: 8px; }
.quick-actions { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.action { color: #fff; text-decoration: none; border-radius: 6px; padding: 14px; font-weight: 700; text-align: center; }
.shopee { background: #f97316; }
.tiktok { background: #111827; }
.master { background: #15803d; }
.sync { background: #6d28d9; }
.token-accounts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.token-account { border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; background: #fff; }
.token-account-head { display: grid; gap: 4px; margin-bottom: 12px; }
.token-account-head span { color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; }
.token-account-head strong { color: #1f2933; font-size: 15px; }
.token-actions { display: grid; grid-template-columns: 1fr; gap: 10px; }
.token-button { min-height: 46px; border: 0; border-radius: 6px; color: #fff; padding: 12px 14px; font-size: 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-align: center; }
.token-button:disabled { cursor: wait; opacity: .72; }
.token-button.shopee { background: #ff5528; }
.token-button.tiktok { background: #000; }
.token-button.wide { grid-column: span 2; }
.button-icon { font-size: 15px; line-height: 1; }
.action-message { color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; margin-top: 12px; font-size: 13px; }
.action-message.error { color: #991b1b; background: #fef2f2; border-color: #fecaca; margin-top: 0; margin-bottom: 12px; }
.token-tables { display: grid; gap: 18px; margin-top: 16px; }
.token-history { border: 1px solid #d9e2ec; border-radius: 8px; background: #fff; overflow: hidden; }
.token-history-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #e5edf5; background: #f8fafc; }
.token-history-head span { color: #64748b; display: block; font-size: 12px; font-weight: 800; margin-bottom: 3px; text-transform: uppercase; }
.token-history-head h3 { color: #1f2933; font-size: 16px; margin: 0; }
.token-history-head small { color: #64748b; white-space: nowrap; }
.token-table-wrap { margin-top: 16px; overflow-x: auto; border: 1px solid #d9e2ec; border-radius: 8px; }
.token-history .token-table-wrap { margin-top: 0; border: 0; border-radius: 0; }
.token-table { width: 100%; border-collapse: collapse; min-width: 980px; background: #fff; }
.token-table th, .token-table td { padding: 11px 12px; border-bottom: 1px solid #e5edf5; text-align: left; font-size: 13px; vertical-align: top; }
.token-table th { color: #475569; background: #f8fafc; font-size: 12px; text-transform: uppercase; }
.token-table tr:last-child td { border-bottom: 0; }
.mono { font-family: Consolas, 'Courier New', monospace; color: #334155; }
.token-status { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 8px; font-size: 12px; font-weight: 700; }
.token-status.active { color: #166534; background: #dcfce7; }
.token-status.inactive { color: #475569; background: #e2e8f0; }
.empty-state { color: #64748b; text-align: center; }
.pagination { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 12px 16px; border-top: 1px solid #e5edf5; background: #fff; }
.pagination button { border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #334155; cursor: pointer; font-weight: 700; padding: 7px 10px; }
.pagination button:disabled { cursor: not-allowed; opacity: .45; }
.pagination span { color: #64748b; font-size: 13px; }
@media (max-width: 1040px) { .integration-grid, .token-accounts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 820px) { .page-shell { margin-left: 0; padding: 18px; } .stats, .integration-grid, .quick-actions, .token-accounts, .token-actions { grid-template-columns: 1fr; } .token-button.wide { grid-column: auto; } .token-history-head, .pagination { align-items: flex-start; flex-direction: column; } }
</style>

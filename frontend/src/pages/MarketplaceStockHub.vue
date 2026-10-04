<template>
  <section class="hub-shell">
    <header class="hub-header">
      <div>
        <p>Marketplace</p>
        <h1>Sinkronisasi Stok Semua Marketplace</h1>
        <span>Pilih toko, lalu gunakan filter dan aksi stok lengkap seperti halaman stok marketplace.</span>
      </div>

      <label class="account-picker">
        <span>Pilih Toko</span>
        <select v-model="selectedKey" :disabled="screenBusy">
          <option v-for="account in accounts" :key="account.key" :value="account.key">
            {{ account.name }} · {{ stockHubChannelLabel(account) }}
          </option>
        </select>
      </label>
    </header>

    <p v-if="message" class="hub-message">{{ message }}</p>

    <MarketplaceStockMirror ref="mirror" :account-key="selectedAccount?.key" :disabled="screenBusy" @busy="mirrorBusy = $event" @targets="mirrorTargets = $event" @completed="refreshKey++" />

    <component
      v-if="selectedAccount"
      :is="stockScreen"
      :key="`${selectedAccount.key}:${refreshKey}`"
      :account-key="selectedAccount.key"
      :account-name="selectedAccount.name"
      :mirror-busy="mirrorBusy"
      :mirror-targets="mirrorTargets"
      @mirror-stock="mirror?.request($event)"
      @busy="screenBusy = $event"
      unified
    />
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { omnichannelService } from '@/services'
import { normalizeMarketplaceAccounts } from './marketplaceAccountsState'
import {
  selectedStockHubAccount,
  stockHubAccounts,
  stockHubChannelLabel,
  stockHubViewReset
} from './marketplaceStockHubState'
import MarketplaceStockMirror from '@/components/MarketplaceStockMirror.vue'
import ShopeeStock from './ShopeeStock.vue'
import TiktokStock from './TiktokStock.vue'

const mirror = ref(null), mirrorBusy = ref(false), screenBusy = ref(false), mirrorTargets = ref([]), refreshKey = ref(0)
const accounts = ref([])
const selectedKey = ref('')
const message = ref('')
const viewReset = ref(stockHubViewReset())

const selectedAccount = computed(() => selectedStockHubAccount(accounts.value, selectedKey.value))
const stockScreen = computed(() => selectedAccount.value?.channel === 'tiktok' ? TiktokStock : ShopeeStock)

async function loadAccounts() {
  try {
    const response = await omnichannelService.marketplaceAccounts()
    accounts.value = stockHubAccounts(normalizeMarketplaceAccounts(response.data))
  } catch (error) {
    accounts.value = stockHubAccounts([])
    message.value = error.response?.data?.message || 'Daftar toko tidak dapat dimuat. Menggunakan daftar toko yang tersedia.'
  }

  selectedKey.value = selectedStockHubAccount(accounts.value, selectedKey.value)?.key || ''
}

watch(selectedKey, () => {
  viewReset.value = stockHubViewReset()
  message.value = ''
})

onMounted(loadAccounts)
</script>

<style scoped>
.hub-header {
  align-items: end;
  display: flex;
  gap: 18px;
  justify-content: space-between;
  margin-left: 240px;
  padding: 20px 28px 0;
}

.hub-header p,
.hub-header > div > span,
.account-picker > span {
  color: #64748b;
}

.hub-header p {
  font-size: 12px;
  margin-bottom: 5px;
}

.hub-header h1 {
  font-size: 24px;
  margin: 0 0 5px;
}

.hub-header > div > span {
  font-size: 13px;
}

.account-picker {
  display: grid;
  gap: 6px;
  min-width: min(360px, 100%);
}

.account-picker > span {
  font-size: 12px;
  font-weight: 700;
}

.account-picker select {
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
  font: inherit;
  padding: 10px 12px;
}

.hub-message {
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-radius: 7px;
  color: #9a3412;
  margin: 14px 28px 0 268px;
  padding: 10px 12px;
}

@media (max-width: 820px) {
  .hub-header {
    align-items: stretch;
    flex-direction: column;
    margin-left: 0;
    padding: 18px 18px 0;
  }

  .hub-message { margin-left: 18px; }
}
</style>

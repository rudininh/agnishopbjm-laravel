<template>
  <Transition name="sidebar-slide">
    <aside v-if="!isPosPage || sidebarOpen" class="sidebar" :class="{ 'pos-sidebar': isPosPage }">
      <RouterLink to="/dashboard" class="brand">
        <img class="brand-mark" src="/agni-logo.png?v=20260505" alt="Agni Shop Banjarmasin" />
        <span>
          <strong>Agni Shop</strong>
          <small>Omnichannel</small>
        </span>
      </RouterLink>

      <nav class="menu">
        <RouterLink to="/dashboard">Dashboard</RouterLink>
        <RouterLink to="/mobile/kelola-produk" class="menu-mobile-highlight">Kelola Produk Mobile</RouterLink>
        <div class="menu-group">
          <button
            type="button"
            class="menu-toggle"
            :aria-expanded="produkOpen"
            @click="produkOpen = !produkOpen"
          >
            <span>Produk</span>
            <strong>{{ produkOpen ? '-' : '+' }}</strong>
          </button>
          <div v-if="produkOpen" class="submenu">
            <RouterLink to="/mobile/kelola-produk" class="menu-mobile-highlight">ðŸ“± Kelola Produk Mobile</RouterLink>
            <RouterLink to="/stok-shopee">Stok Shopee</RouterLink>
            <RouterLink to="/stok-tiktok">Stok TikTok</RouterLink>
            <RouterLink to="/stock-master">Stock Master</RouterLink>
            <RouterLink to="/sku-mapping">SKU Mapping</RouterLink>
            <RouterLink to="/tambah-varian-tiktok">Tambah Varian TikTok</RouterLink>
            <RouterLink to="/tambah-semua-varian-tiktok">Tambah Semua Varian TikTok</RouterLink>
            <RouterLink to="/sinkronisasi-varian-marketplace">Sinkronisasi Varian Marketplace</RouterLink>
            <RouterLink to="/tambah-varian-shopee">Tambah Varian Shopee</RouterLink>
            <RouterLink to="/analisa-product-variant">Analisa Product & Variant</RouterLink>
            <RouterLink to="/anomali-gambar-variant">Anomali Gambar Variant</RouterLink>
            <RouterLink to="/detail-produk-marketplace">Detail Produk</RouterLink>
          </div>
        </div>
        <RouterLink to="/pos-offline">POS Offline</RouterLink>
        <div class="menu-group">
          <span>Marketplace</span>
          <RouterLink to="/marketplace/auto-sync">Sinkronisasi Otomatis</RouterLink>
          <RouterLink to="/marketplace/import">Import Marketplace</RouterLink>
          <RouterLink to="/marketplace/cetak-resi">Cetak Resi</RouterLink>
          <RouterLink to="/marketplace/stock-anomalies">Anomali Stok</RouterLink>
        </div>
      </nav>
    </aside>
  </Transition>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

const produkOpen = ref(false)
const sidebarOpen = ref(false)
const route = useRoute()
const isPosPage = computed(() => route.path === '/pos-offline')

watch(isPosPage, (active) => {
  sidebarOpen.value = true
}, { immediate: true })
</script>

<style scoped>
.sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  width: 240px;
  background: #0f5fc7;
  color: #fff;
  display: flex;
  flex-direction: column;
  padding: 18px;
  z-index: 10;
  overflow-y: auto;
}

.sidebar.pos-sidebar {
  align-items: center;
  flex-direction: row;
  gap: 18px;
  height: 66px;
  inset: 0 0 auto 0;
  overflow: visible;
  padding: 10px 18px;
  width: auto;
}

.sidebar-toggle {
  align-items: center;
  background: #fff;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  box-shadow: 0 10px 24px rgba(15, 23, 42, .16);
  cursor: pointer;
  display: grid;
  gap: 5px;
  height: 44px;
  justify-content: center;
  left: 14px;
  padding: 9px;
  position: fixed;
  top: 14px;
  z-index: 30;
  width: 48px;
}

.pos-sidebar .brand {
  flex-shrink: 0;
  margin-bottom: 0;
}

.pos-sidebar .brand-mark {
  height: 38px;
  width: 38px;
}

.pos-sidebar .menu {
  align-items: center;
  display: flex;
  gap: 6px;
  min-width: 0;
  overflow-x: auto;
  width: 100%;
}

.pos-sidebar .menu a,
.pos-sidebar .menu-toggle {
  white-space: nowrap;
}

.pos-sidebar .menu-group {
  align-items: center;
  display: flex;
  gap: 6px;
  margin-top: 0;
  position: relative;
}

.pos-sidebar .menu-group > span {
  display: none;
}

.pos-sidebar .submenu {
  background: #0f5fc7;
  border: 1px solid rgba(255, 255, 255, .18);
  border-radius: 8px;
  box-shadow: 0 14px 30px rgba(15, 23, 42, .22);
  left: 0;
  min-width: 230px;
  padding: 8px;
  position: absolute;
  top: calc(100% + 8px);
}

.pos-sidebar .submenu a {
  white-space: normal;
}

.pos-sidebar .menu > a.router-link-active {
  background: rgba(255, 255, 255, .2);
}

.sidebar-toggle span {
  background: #30343b;
  border-radius: 999px;
  display: block;
  height: 4px;
  transition: transform .24s ease, opacity .18s ease, background .2s ease;
  width: 28px;
}

.sidebar-toggle:hover span,
.sidebar-toggle.open span {
  background: #0f5fc7;
}

.sidebar-toggle.open span:nth-child(1) {
  transform: translateY(9px) rotate(45deg);
}

.sidebar-toggle.open span:nth-child(2) {
  opacity: 0;
}

.sidebar-toggle.open span:nth-child(3) {
  transform: translateY(-9px) rotate(-45deg);
}

.sidebar-slide-enter-active,
.sidebar-slide-leave-active {
  transition: transform .28s ease, opacity .22s ease;
}

.sidebar-slide-enter-from,
.sidebar-slide-leave-to {
  opacity: .82;
  transform: translateX(-100%);
}

.sidebar-slide-enter-to,
.sidebar-slide-leave-from {
  opacity: 1;
  transform: translateX(0);
}

.brand {
  display: flex;
  gap: 12px;
  align-items: center;
  text-decoration: none;
  margin-bottom: 28px;
}

.brand-mark {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  background: #fff;
  object-fit: cover;
  display: block;
  box-shadow: 0 1px 3px rgba(15, 23, 42, .18);
}

.brand strong,
.brand small {
  display: block;
}

.brand small {
  color: #dbeafe;
  margin-top: 2px;
}

.menu {
  display: grid;
  gap: 8px;
}

.menu a,
.menu-toggle,
.logout {
  border: 0;
  border-radius: 6px;
  color: #fff;
  background: transparent;
  text-align: left;
  text-decoration: none;
  padding: 10px 12px;
  font-size: 14px;
  cursor: pointer;
}

.menu-toggle {
  align-items: center;
  display: flex;
  justify-content: space-between;
  width: 100%;
}

.menu-toggle span {
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0;
  padding: 0;
  text-transform: none;
}

.menu-toggle strong {
  font-size: 18px;
  line-height: 1;
}

.menu a:hover,
.menu a.router-link-active,
.menu-toggle:hover {
  background: rgba(255, 255, 255, .16);
}

.menu-group {
  display: grid;
  gap: 6px;
  margin-top: 6px;
}

.menu-group > span {
  color: #bfdbfe;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  padding: 8px 12px 0;
  text-transform: uppercase;
}

.submenu {
  display: grid;
  gap: 6px;
  padding-left: 10px;
}

.submenu a {
  font-size: 13px;
}

.logout {
  margin-top: auto;
  background: rgba(185, 28, 28, .9);
}

@media (max-width: 820px) {
  .sidebar {
    position: static;
    width: 100%;
  }

  .sidebar.pos-sidebar {
    position: fixed;
    align-items: stretch;
    height: auto;
    overflow-x: auto;
    width: auto;
  }

  .pos-sidebar .brand span {
    display: none;
  }
}
</style>

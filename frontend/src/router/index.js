import { createRouter, createWebHistory } from 'vue-router'

import Dashboard from '@/pages/Dashboard.vue'
import ShopeeStock from '@/pages/ShopeeStock.vue'
import TiktokStock from '@/pages/TiktokStock.vue'
import StockMaster from '@/pages/StockMaster.vue'
import SkuMapping from '@/pages/SkuMapping.vue'
import TambahVarian from '@/pages/TambahVarian.vue'
import TambahVarianShopee from '@/pages/TambahVarianShopee.vue'
import BulkTambahVarianTiktok from '@/pages/BulkTambahVarianTiktok.vue'
import VariantMarketplaceReconciliation from '@/pages/VariantMarketplaceReconciliation.vue'
import ProductVariantAnalysis from '@/pages/ProductVariantAnalysis.vue'
import AnomaliGambarVariant from '@/pages/AnomaliGambarVariant.vue'
import DetailProdukMarketplace from '@/pages/DetailProdukMarketplace.vue'
import MarketplaceAutoSync from '@/pages/MarketplaceAutoSync.vue'
import ImportMarketplace from '@/pages/ImportMarketplace.vue'
import ImportMarketplaceMobile from '@/pages/ImportMarketplaceMobile.vue'
import StockAnomalies from '@/pages/StockAnomalies.vue'
import ShippingLabels from '@/pages/ShippingLabels.vue'
import POSOffline from '@/pages/POSOffline.vue'
import MobileProductManagement from '@/pages/MobileProductManagement.vue'

const routes = [
  {
    path: '/login',
    name: 'login',
    redirect: '/dashboard'
  },
  {
    path: '/',
    redirect: '/dashboard'
  },
  {
    path: '/dashboard',
    name: 'dashboard',
    component: Dashboard
  },
  {
    path: '/stok-shopee',
    name: 'shopee-stock',
    component: ShopeeStock
  },
  {
    path: '/stok-tiktok',
    name: 'tiktok-stock',
    component: TiktokStock
  },
  {
    path: '/stock-master',
    name: 'stock-master',
    component: StockMaster
  },
  {
    path: '/sku-mapping',
    name: 'sku-mapping',
    component: SkuMapping
  },
  {
    path: '/tambah-varian',
    redirect: '/tambah-varian-tiktok'
  },
  {
    path: '/tambah-varian-tiktok',
    name: 'tambah-varian-tiktok',
    component: TambahVarian,
    meta: { flow: 'shopee-to-tiktok' }
  },
  {
    path: '/tambah-semua-varian-tiktok',
    name: 'tambah-semua-varian-tiktok',
    component: BulkTambahVarianTiktok
  },
  {
    path: '/sinkronisasi-varian-marketplace',
    name: 'sinkronisasi-varian-marketplace',
    component: VariantMarketplaceReconciliation
  },
  {
    path: '/tambah-varian-shopee',
    name: 'tambah-varian-shopee',
    component: TambahVarianShopee,
    meta: { flow: 'tiktok-to-shopee' }
  },
  {
    path: '/analisa-product-variant',
    name: 'analisa-product-variant',
    component: ProductVariantAnalysis
  },
  {
    path: '/anomali-gambar-variant',
    name: 'anomali-gambar-variant',
    component: AnomaliGambarVariant
  },
  {
    path: '/detail-produk-marketplace',
    name: 'detail-produk-marketplace',
    component: DetailProdukMarketplace
  },
  {
    path: '/marketplace/auto-sync',
    name: 'marketplace-auto-sync',
    component: MarketplaceAutoSync
  },
  {
    path: '/marketplace/import',
    name: 'marketplace-import',
    component: ImportMarketplace
  },
  {
    path: '/marketplace/import-mobile',
    name: 'marketplace-import-mobile',
    component: ImportMarketplaceMobile
  },
  {
    path: '/marketplace/cetak-resi',
    name: 'marketplace-shipping-labels',
    component: ShippingLabels
  },
  {
    path: '/marketplace/stock-anomalies',
    name: 'marketplace-stock-anomalies',
    component: StockAnomalies
  },
  {
    path: '/pos-offline',
    name: 'pos-offline',
    component: POSOffline
  },
  {
    path: '/mobile/kelola-produk',
    name: 'mobile-product-management',
    component: MobileProductManagement
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard'
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router

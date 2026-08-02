import api from './api'

export const authService = {
  register(email, password, name) {
    return api.post('/auth/register', {
      email,
      password,
      password_confirmation: password,
      name
    })
  },

  login(email, password) {
    return api.post('/auth/login', {
      email,
      password
    })
  },

  logout() {
    return api.post('/auth/logout')
  }
}

export const productService = {
  getAll(page = 1, perPage = 20) {
    return api.get('/products', {
      params: { page, per_page: perPage }
    })
  },

  getById(id) {
    return api.get(`/products/${id}`)
  },

  create(data) {
    return api.post('/products', data)
  },

  update(id, data) {
    return api.put(`/products/${id}`, data)
  },

  delete(id) {
    return api.delete(`/products/${id}`)
  }
}

export const categoryService = {
  getAll(page = 1, perPage = 20) {
    return api.get('/categories', {
      params: { page, per_page: perPage }
    })
  },

  getById(id) {
    return api.get(`/categories/${id}`)
  },

  create(data) {
    return api.post('/categories', data)
  },

  update(id, data) {
    return api.put(`/categories/${id}`, data)
  },

  delete(id) {
    return api.delete(`/categories/${id}`)
  }
}

export const orderService = {
  getAll(page = 1, perPage = 20) {
    return api.get('/orders', {
      params: { page, per_page: perPage }
    })
  },

  getById(id) {
    return api.get(`/orders/${id}`)
  },

  checkout(data) {
    return api.post('/orders', data)
  }
}

export const posService = {
  stockMasterProducts() {
    return api.get('/pos/stock-master-products')
  },

  checkout(data) {
    return api.post('/pos/offline-orders', data)
  }
}

export const omnichannelService = {
  dashboard() {
    return api.get('/omnichannel/dashboard')
  },

  shopeeItems(sync = false, params = {}) {
    return api.get('/get-shopee-items', {
      params: { ...(sync ? { sync: 1 } : {}), ...params }
    })
  },

  tiktokItems(sync = false, params = {}) {
    return api.get('/get-tiktok-items', {
      params: { ...(sync ? { sync: 1 } : {}), ...params }
    })
  },

  stockMaster() {
    return api.get('/get-stock-master')
  },

  productVariantAnalysis(params = {}) {
    return api.get('/product-variant-analysis', { params })
  },

  confirmProductVariantAnalysisIssue(data) {
    return api.post('/product-variant-analysis/confirm', data)
  },

  imageVariantAnomalies(params = {}) {
    return api.get('/product-variant-image-anomalies', { params })
  },

  syncTiktokImagesFromShopee(data = {}) {
    return api.post('/product-variant-image-anomalies/sync-tiktok-from-shopee', data)
  },

  skuMapping(params = {}) {
    return api.get('/sku-mapping', { params })
  },

  saveSkuMapping(data) {
    return api.post('/sku-mapping', data)
  },

  syncSkuMappingMarketplaces() {
    return api.post('/sku-mapping/sync-marketplaces')
  },

  updateSkuMappingMarketplaceSku(data) {
    return api.post('/sku-mapping/update-marketplace-sku', data)
  },

  updateMarketplaceVariantSku(data) {
    return api.post('/sku-mapping/update-marketplace-variant-sku', data)
  },


  bulkUpdateShopeeEmptyVariantSkus() {
    return api.post('/sku-mapping/bulk-update-empty-shopee-variant-skus')
  },
  bulkTiktokMissingVariantsPreview() {
    return api.get('/tiktok/bulk-missing-variants')
  },

  bulkSubmitTiktokMissingVariants(data) {
    return api.post('/tiktok/bulk-missing-variants/submit', data)
  },

  tiktokVariantReconciliationProducts() { return api.get('/tiktok/variant-reconciliation/products') },
  tiktokVariantReconciliationPreview(params) { return api.get('/tiktok/variant-reconciliation/preview', { params }) },
  prepareMissingVariant(data) {
    return api.post('/sku-mapping/prepare-missing-variant', data)
  },

  tiktokVariantAction(data) {
    return api.post('/tiktok-variant/action', data)
  },

  tiktokDeleteVariant(data) {
    return api.post('/tiktok/delete-variant', data)
  },

  tiktokSubmitGeneratedPayload(data) {
    return api.post('/tiktok/submit-generated-payload', data, {
      responseType: 'text',
      validateStatus: () => true
    })
  },

  tiktokGetProduct(data) {
    return api.post('/tiktok/get-product', data, {
      responseType: 'text',
      validateStatus: () => true
    })
  },

  tiktokGetProductContext() {
    return api.get('/tiktok/get-product-context')
  },

  shopeeApiTest(data) {
    return api.post('/shopee/api-test', data, {
      responseType: 'text',
      validateStatus: () => true
    })
  },

  shopeeApiTestContext() {
    return api.get('/shopee/api-test-context')
  },

  shopeeAddVariant(data) {
    return api.post('/shopee/add-variant', data, {
      responseType: 'text',
      validateStatus: () => true
    })
  },

  shopeeDeleteVariant(data) {
    return api.post('/shopee/delete-variant', data)
  },

  autoSyncDashboard() {
    return api.get('/marketplace/auto-sync')
  },

  autoSyncRuntimeStatus() {
    return api.get('/marketplace/auto-sync/runtime-status')
  },

  stbRuntimeStatus() {
    return api.get('/runtime/stb-status')
  },

  autoSyncBridgeStatus() {
    return api.get('/marketplace/auto-sync/bridge-status')
  },

  autoSyncRuntimeReadiness() {
    return api.get('/marketplace/auto-sync/runtime-readiness')
  },

  autoSyncRuntimeEvents(params = {}) {
    return api.get('/marketplace/auto-sync/runtime-events', { params })
  },

  autoSyncRuntimeHeartbeat(data = {}) {
    return api.post('/marketplace/auto-sync/runtime-heartbeat', data)
  },

  updateAutoSyncRuntimeSettings(data = {}) {
    return api.post('/marketplace/auto-sync/runtime-settings', data)
  },

  autoSyncRuntimeOnlineBackupTick() {
    return api.post('/marketplace/auto-sync/runtime-online-backup-tick')
  },

  autoSyncBackupRunnerDryRun() {
    return api.post('/marketplace/auto-sync/backup-runner/dry-run')
  },

  autoSyncBackupRunnerRun(data = {}) {
    return api.post('/marketplace/auto-sync/backup-runner/run', data)
  },

  autoSyncBackupRunnerSchedulerTick(data = {}) {
    return api.post('/marketplace/auto-sync/backup-runner/scheduler-tick', data)
  },

  autoSyncWebhookLogs(params = {}) {
    return api.get('/marketplace/auto-sync/webhook-logs', { params })
  },

  autoSyncLogs(params = {}) {
    return api.get('/marketplace/auto-sync/sync-logs', { params })
  },

  autoSyncSafety(params = {}) {
    return api.get('/marketplace/auto-sync/safety-check', { params })
  },

  autoSyncOrderSync(params = {}) {
    return api.get('/marketplace/auto-sync/order-sync', { params })
  },

  shippingLabelOrders(params = {}) {
    return api.get('/marketplace/shipping-labels/orders', { params })
  },

  shippingLabelOrderDetail(params = {}) {
    return api.get('/marketplace/shipping-labels/order-detail', { params })
  },

  shippingLabelOfficialDocument(payload = {}) {
    return api.post('/marketplace/shipping-labels/official-document', payload)
  },

  markShippingLabelsPrinted(payload = {}) {
    return api.post('/marketplace/shipping-labels/mark-printed', payload)
  },

  autoSyncStockAnomalies(params = {}) {
    return api.get('/marketplace/auto-sync/stock-anomalies', { params })
  },

  autoSyncSkuChangeHistory(params = {}) {
    return api.get('/marketplace/auto-sync/sku-change-history', { params })
  },

  autoSyncOrderWatchdog(params = {}) {
    return api.get('/marketplace/auto-sync/order-watchdog', { params })
  },

  autoSyncReconciliationReport(params = {}) {
    return api.get('/marketplace/auto-sync/reconciliation-report', { params })
  },

  autoSyncQueueDashboard(params = {}) {
    return api.get('/marketplace/auto-sync/queue-dashboard', { params })
  },

  syncAutoSyncStockAnomaly(data) {
    return api.post('/marketplace/auto-sync/stock-anomalies/sync', data)
  },

  refreshAutoSyncStockAnomalyProducts(data) {
    return api.post('/marketplace/auto-sync/stock-anomalies/refresh-products', data)
  },

  exportAutoSyncOrderSync(params = {}) {
    return api.get('/marketplace/auto-sync/order-sync/export', {
      params,
      responseType: 'blob'
    })
  },

  autoSyncOrderSyncDetail(id) {
    return api.get(`/marketplace/auto-sync/order-sync/${id}`)
  },

  retryAutoSyncOrderSync(id) {
    return api.post(`/marketplace/auto-sync/order-sync/${id}/retry`)
  },

  runAutoSyncSafetyCheck() {
    return api.post('/marketplace/auto-sync/run-safety-check')
  },

  syncAutoSyncShopeeToTiktok() {
    return api.post('/marketplace/auto-sync/sync-shopee-to-tiktok')
  },

  instantAutoSyncCheck(marketplace = 'all') {
    return api.post('/marketplace/auto-sync/instant-check', { marketplace })
  },

  retryAutoSyncOpenIssues(limit = 10) {
    return api.post('/marketplace/auto-sync/retry-open-issues', { limit })
  },

  bulkUpdateAutoSyncEmptySkus(limit = 20, dryRun = false) {
    return api.post('/marketplace/auto-sync/bulk-update-empty-skus', { limit, dry_run: dryRun })
  },

  pollAutoSyncShopeeOrders(hours = 24) {
    return api.post('/marketplace/auto-sync/poll-shopee-orders', { hours })
  },

  pollAutoSyncTiktokOrders(hours = 24) {
    return api.post('/marketplace/auto-sync/poll-tiktok-orders', { hours })
  },

  downloadShopeeGitaMassUpdate() {
    return api.get('/marketplace/import/shopee-gita/mass-update', {
      responseType: 'blob'
    })
  },

  manualImportMarketplaceStockSync(payload = {}) {
    return api.post('/marketplace/import/manual-stock-sync', payload)
  },

  runTokenAction(action) {
    return api.post(`/omnichannel/${action}`)
  }
}

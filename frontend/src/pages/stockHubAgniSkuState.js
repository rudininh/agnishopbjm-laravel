import { createProductCreationController } from './stockHubProductCreationController.js'

export const agniSkuVerified = run => ['success', 'exists'].includes(run?.status)

export function createAgniSkuController(api, options = {}) {
  return createProductCreationController({
    source: api.agniSkuSource?.bind(api), start: api.startAgniSku?.bind(api),
    step: api.stepAgniSku?.bind(api), check: api.recoverAgniSku?.bind(api)
  }, {
    runningStatuses: ['preparing', 'submitting', 'verifying'],
    checkableStatuses: ['submitted_unverified', 'blocked', 'submitting', 'verifying'],
    newRunStatuses: ['reconciled'], retryStatuses: ['reconciled', 'blocked', 'rejected'],
    contextlessRequests: true,
    storageKey: 'stock-hub-agni-skus:identifiers'
  }, options)
}

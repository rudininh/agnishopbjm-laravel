import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const dashboardSource = await readFile(new URL('../src/pages/Dashboard.vue', import.meta.url), 'utf8')

test('dashboard provides a safe manual STB token pull action', () => {
  assert.match(dashboardSource, /@click=.{1}pullStbMarketplaceTokens/)
  assert.match(dashboardSource, /busyAction === 'stb-token-sync'/)
  assert.match(dashboardSource, /omnichannelService\.pullStbMarketplaceTokens\(\)/)
  assert.match(dashboardSource, /Sinkron token STB selesai\./)
  assert.match(dashboardSource, /await loadData\(\)/)
  assert.match(dashboardSource, /token\.access_token_available \? 'Tersedia' : 'Tidak tersedia'/)
  assert.match(dashboardSource, /token\.refresh_token_available \? 'Tersedia' : 'Tidak tersedia'/)
  assert.doesNotMatch(dashboardSource, /token\.access_token \|\| '-'/)
  assert.doesNotMatch(dashboardSource, /token\.refresh_token \|\| '-'/)
})

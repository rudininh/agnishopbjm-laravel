import test from 'node:test'
import assert from 'node:assert/strict'
import { variantCoverageInfo, matchesVariantCoverageFilter, modelVariantCoverageInfo, missingVariantEligible } from '../src/pages/marketplaceVariantCoverageState.js'

const tt = 'tiktok-agnishopbjm'
const gita = 'shopee-gitacollectionbjm'
const partial = { state: 'missing_variants', target_product_id: '900', source_count: 3, existing_count: 1, missing_count: 2, missing_model_ids: ['2', '3'] }
const item = { destination_variant_coverage: { [tt]: partial, [gita]: { ...partial, target_product_id: '800', missing_count: 1, missing_model_ids: ['1'] } } }

test('saved coverage labels show each destination and keep missing parents separate', () => {
  assert.deepEqual(variantCoverageInfo(item).map(info => info.label), ['TikTok: kurang 2 varian', 'Gitashop: kurang 1 varian'])
  assert.equal(variantCoverageInfo({}).length, 0)
  const other = { destination_variant_coverage: { [tt]: { state: 'unknown' }, [gita]: { state: 'missing_product' } } }
  assert.deepEqual(variantCoverageInfo(other).map(info => info.label), ['TikTok: Perlu diperiksa', 'Gitashop: produk belum ditemukan'])
  assert.equal(variantCoverageInfo({ destination_variant_coverage: { [tt]: { state: 'complete' } } })[0].label, 'TikTok: varian lengkap')
})

test('filters select partially populated parents per account and exclude unknown or absent parents', () => {
  assert.equal(matchesVariantCoverageFilter(item, tt), true)
  assert.equal(matchesVariantCoverageFilter(item, gita), true)
  assert.equal(matchesVariantCoverageFilter({}, 'all'), true)
  for (const state of ['complete', 'unknown', 'missing_product']) {
    assert.equal(matchesVariantCoverageFilter({ destination_variant_coverage: { [tt]: { ...partial, state } } }, tt), false)
  }
})

test('model indicators use exact source IDs and distinguish destination eligibility', () => {
  assert.equal(missingVariantEligible(item, { model_id: 2 }, tt), true)
  assert.equal(missingVariantEligible(item, { model_id: '1' }, tt), false)
  assert.equal(missingVariantEligible(item, { model_id: '1' }, gita), true)
  assert.deepEqual(modelVariantCoverageInfo(item, { model_id: '2' }).map(info => info.label), ['TikTok: varian belum ditemukan'])
  assert.deepEqual(modelVariantCoverageInfo(item, { model_id: '12' }), [])
  assert.equal(missingVariantEligible({ destination_variant_coverage: { [tt]: { ...partial, state: 'unknown' } } }, { model_id: '2' }, tt), false)
  assert.equal(missingVariantEligible({ destination_variant_coverage: { [tt]: { ...partial, target_product_id: null } } }, { model_id: '2' }, tt), false)
})

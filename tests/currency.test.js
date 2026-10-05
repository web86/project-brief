import { test } from 'node:test'
import assert from 'node:assert/strict'
import { getProjectCurrencySymbol } from '../src/utils/currency.js'

test('projects without currency continue to display rubles', () => {
  for (const project of [undefined, {}, { currency: '' }, { currency: '  ' }])
    assert.equal(getProjectCurrencySymbol(project), '₽')
})

test('project currency supports RUB, USD, EUR, TRY and an unknown ISO code', () => {
  for (const [currency, symbol] of Object.entries({ RUB: '₽', USD: '$', EUR: '€', TRY: '₺' }))
    assert.equal(getProjectCurrencySymbol({ currency }), symbol)
  assert.equal(getProjectCurrencySymbol({ currency: ' eur ' }), '€')
  assert.equal(getProjectCurrencySymbol({ currency: 'GBP' }), 'GBP')
})

const symbols = { RUB: '₽', USD: '$', EUR: '€', TRY: '₺' }

export function getProjectCurrencySymbol(project) {
  const currency =
    typeof project?.currency === 'string' ? project.currency.trim().toUpperCase() : ''
  return currency ? (Object.hasOwn(symbols, currency) ? symbols[currency] : currency) : symbols.RUB
}

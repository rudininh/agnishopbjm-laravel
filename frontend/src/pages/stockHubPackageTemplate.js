export function packageTemplateText(template) {
  const weight = template?.weight, size = template?.dimensions
  if (!weight || !size) return ''
  const number = value => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 6 }).format(Number(value))
  const weightUnit = weight.unit === 'GRAM' ? 'g' : 'kg'
  const sizeUnit = size.unit === 'INCH' ? 'inci' : 'cm'
  return `${number(weight.value)} ${weightUnit}; ${number(size.length)} × ${number(size.width)} × ${number(size.height)} ${sizeUnit}`
}

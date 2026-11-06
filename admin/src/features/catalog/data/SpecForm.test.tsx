import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'

import { SpecForm } from './SpecForm'
import type { FieldDef, States, Values } from './specs'
import type { Lists } from './useLists'

const defs: FieldDef[] = [
  { key: 'design', label: 'Diseño o modelo', kind: 'text', scope: 'product', group: 'features' },
  { key: 'packaging_type', label: 'Tipo de empaque', kind: 'option', scope: 'product', group: 'sku', catalog: 'packaging_type' },
  { key: 'weight', label: 'Peso', kind: 'measure', scope: 'product', group: 'sku', catalog: 'weight_unit' },
]

const opt = (code: string, es: string, meta: unknown = null) => ({ id: code, catalog: '', code, labels: [{ locale: 'es-MX', value: es }], meta, position: 0, isActive: true, inUse: false })
const lists = {
  defaultLocale: 'es-MX',
  byCatalog: { packaging_type: [opt('unitario', 'Unitario'), opt('por_caja', 'Por caja')], weight_unit: [opt('g', 'g'), opt('kg', 'kg')] },
  attributes: [],
  colors: [],
  attribute: () => undefined,
  label: (o: { labels: { value: string }[] }) => o.labels[0]!.value,
  loading: false,
} as unknown as Lists

function Harness({ required = ['design'] }: { required?: string[] }) {
  const [values, setValues] = useState<Values>({})
  const [states, setStates] = useState<States>({})
  return <SpecForm fields={defs} values={values} states={states} required={required} lists={lists} onChange={(k, v) => setValues((x) => ({ ...x, [k]: v }))} onState={(k, s) => setStates((x) => ({ ...x, [k]: x[k] === s ? 'pending' : s }))} />
}

describe('SpecForm', () => {
  it('marks required fields, and an empty field starts as pending', () => {
    render(<Harness />)
    expect(screen.getByText('(obligatorio)')).toBeInTheDocument()
    const pending = screen.getAllByRole('button', { name: 'Dato pendiente' })
    expect(pending).toHaveLength(3)
    expect(pending.every((b) => b.getAttribute('aria-pressed') === 'true')).toBe(true)
  })

  it('"No aplica" blocks the field; a value takes the pills out of play', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const [design] = screen.getAllByRole('button', { name: 'No aplica' })
    await user.click(design!)
    expect(screen.getByLabelText(/Diseño o modelo/)).toBeDisabled()
    expect(design).toHaveAttribute('aria-pressed', 'true')
    await user.click(design!) // pressing again goes back to pending
    expect(screen.getByLabelText(/Diseño o modelo/)).toBeEnabled()

    await user.type(screen.getByLabelText(/Diseño o modelo/), 'Liso')
    expect(screen.getAllByRole('button', { name: 'No aplica' })[0]).toBeDisabled()
    expect(screen.getAllByRole('button', { name: 'Dato pendiente' })[0]).toHaveAttribute('aria-pressed', 'false')
  })

  it('offers the list entries and a measure with its unit', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    expect(screen.getByRole('option', { name: 'Por caja' })).toBeInTheDocument()
    await user.type(screen.getByLabelText('Peso'), '120')
    await user.selectOptions(screen.getByLabelText('Unidad de Peso'), 'kg')
    expect(screen.getByLabelText('Peso')).toHaveValue('120')
    expect(screen.getByLabelText('Unidad de Peso')).toHaveValue('kg')
  })
})

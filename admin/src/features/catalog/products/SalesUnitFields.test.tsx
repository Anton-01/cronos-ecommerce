import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'

import { SalesUnitFields } from './SalesUnitFields'
import { PIECES, type SalesForm } from './salesUnit'

function Harness({ price = '18.00', initial = PIECES }: { price?: string; initial?: SalesForm }) {
  const [value, setValue] = useState(initial)
  return <SalesUnitFields value={value} onChange={setValue} price={price} />
}

describe('SalesUnitFields', () => {
  it('shows nothing about cuts or rolls for pieces', () => {
    render(<Harness />)

    expect(screen.getByText('Se vende por pieza.')).toBeInTheDocument()
    expect(screen.queryByLabelText(/Corte libre/)).not.toBeInTheDocument()
  })

  it('walks through configuring a ribbon sold by cut and by roll, with live examples', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await user.selectOptions(screen.getByLabelText('Se vende por'), 'METER')
    expect(screen.getByLabelText(/Corte libre/)).toBeChecked()
    expect(screen.getByLabelText('Paso (m)')).toHaveValue('0.5')

    await user.click(screen.getByLabelText('Rollo completo'))
    // A roll needs its length: the form says so instead of letting an invalid setup through.
    expect(screen.getByRole('alert')).toHaveTextContent('Indica cuántos metros tiene un rollo.')

    await user.type(screen.getByLabelText('Metros por rollo'), '25')
    await user.type(screen.getByLabelText('Precio por rollo'), '400')
    await user.type(screen.getByLabelText('Máximo (m)'), '50')

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    const summary = screen.getByLabelText('Resumen de venta')
    expect(summary).toHaveTextContent('Corte libre: desde 1 m, hasta 50 m, en pasos de 0.5 m.')
    expect(summary).toHaveTextContent('Rollo completo de 25 m a $400.00 por rollo.')
    expect(summary).toHaveTextContent('2 m de corte = $36.00')
    expect(summary).toHaveTextContent('Ahorro por rollo frente al corte: $50.00')
  })

  it('reports an off-grid minimum and recovers when fixed', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    await user.selectOptions(screen.getByLabelText('Se vende por'), 'METER')

    const min = screen.getByLabelText('Mínimo (m)')
    await user.clear(min)
    await user.type(min, '1.2')
    expect(screen.getByRole('alert')).toHaveTextContent('múltiplo del paso')

    await user.clear(min)
    await user.type(min, '1.5')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('returns to a clean piece configuration when switching back', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    await user.selectOptions(screen.getByLabelText('Se vende por'), 'METER')
    await user.selectOptions(screen.getByLabelText('Se vende por'), 'PIECE')

    expect(screen.getByText('Se vende por pieza.')).toBeInTheDocument()
  })
})

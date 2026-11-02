import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Preview } from './Preview'
import type { Navigation, Tokens } from './types'

const tokens = { identity: { siteName: 'Cronos', tagline: {}, themeColor: null }, layout: { showTopbar: false, showCategoryNav: false }, shape: { buttonStyle: 'filled' } } as unknown as Tokens
const navigation = { topbarLinks: [], footerNote: {} } as unknown as Navigation

describe('Preview', () => {
  it('paints with the css variables the server derived', () => {
    render(<Preview tokens={tokens} navigation={navigation} vars={{ '--c-primary': '#F59A57' }} fontsHref={null} logoUrl={null} />)
    const root = screen.getByTestId('theme-preview')
    expect(root.style.getPropertyValue('--c-primary')).toBe('#F59A57')
    expect(screen.getAllByText('Cronos').length).toBeGreaterThan(0)
  })

  it('shows the uploaded logo instead of the name in the header', () => {
    render(<Preview tokens={tokens} navigation={navigation} vars={{}} fontsHref={null} logoUrl="/logo.webp" />)
    expect(screen.getByRole('img', { name: 'Cronos' })).toHaveAttribute('src', '/logo.webp')
  })
})

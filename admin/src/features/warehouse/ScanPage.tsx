import { useQuery } from '@apollo/client/react'
import { Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import { WhSearchProductsDocument } from '@/graphql/generated'

import { Scanner } from './Scanner'
import { STATUS_LABEL, formatCode, normalizeCode, parseScanned } from './lib'

/** The front door of the warehouse: scan a label, type its code or look the product up by name. */
export function ScanPage() {
  const navigate = useNavigate()
  const [typed, setTyped] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const { data, loading } = useQuery(WhSearchProductsDocument, { variables: { search: debounced, first: 12 }, skip: debounced.length < 2 })

  const open = (text: string) => {
    const parsed = parseScanned(text)
    if (!parsed) {
      setError('Ese código no es válido. Revisa la etiqueta o escríbelo otra vez.')
      toast.error('Código no válido')
      return
    }
    setError(null)
    navigate(parsed.kind === 'item' ? `/warehouse/item/${parsed.code}` : `/warehouse/shelf/${encodeURIComponent(parsed.code)}`)
  }

  return (
    <div className="mx-auto max-w-xl space-y-6">
      <section aria-labelledby="scan-title" className="space-y-3">
        <h2 id="scan-title" className="text-lg font-semibold">
          Escanear etiqueta
        </h2>
        <Scanner onCode={open} />
      </section>

      <section aria-labelledby="code-title" className="space-y-2">
        <h2 id="code-title" className="text-lg font-semibold">
          O escribe el código
        </h2>
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            open(typed)
          }}
        >
          <label htmlFor="wh-code" className="sr-only">
            Código de la etiqueta
          </label>
          <Input id="wh-code" value={typed} onChange={(e) => setTyped(e.target.value)} placeholder="7K3M9Q2XA4-6" autoCapitalize="characters" autoComplete="off" spellCheck={false} className="h-12 font-mono text-base uppercase" aria-invalid={error !== null} aria-describedby="wh-code-help" />
          <Button type="submit" size="lg" className="h-12" disabled={typed.trim() === ''}>
            Abrir
          </Button>
        </form>
        <p id="wh-code-help" role={error ? 'alert' : undefined} className={error ? 'text-sm text-destructive' : 'text-xs text-muted-foreground'}>
          {error ?? 'Va debajo del QR. Se acepta con o sin guion, en mayúsculas o minúsculas.'}
        </p>
        {typed.trim() !== '' && normalizeCode(typed) && <p className="text-xs text-success">Código válido: {formatCode(normalizeCode(typed)!)}</p>}
      </section>

      <section aria-labelledby="find-title" className="space-y-2">
        <h2 id="find-title" className="text-lg font-semibold">
          O búscalo por nombre o SKU
        </h2>
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <label htmlFor="wh-search" className="sr-only">
            Buscar producto
          </label>
          <Input id="wh-search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Listón satén rojo…" className="h-12 pl-9" autoComplete="off" />
        </div>
        <ul className="divide-y rounded-lg border" aria-live="polite" aria-busy={loading}>
          {(data?.whSearchProducts ?? []).map((p) => (
            <li key={p.productId}>
              <Link to={`/warehouse/item/${p.stockCode}`} className="flex min-h-14 items-center justify-between gap-3 px-4 py-2 hover:bg-accent">
                <span className="min-w-0">
                  <span className="block truncate font-medium">{p.name ?? p.sku}</span>
                  <span className="block truncate text-xs text-muted-foreground">
                    {p.sku} · {p.stockCode ? formatCode(p.stockCode) : 'sin código'}
                  </span>
                </span>
                {p.status !== 'published' && <Badge variant="secondary">{STATUS_LABEL[p.status] ?? p.status}</Badge>}
              </Link>
            </li>
          ))}
          {debounced.length >= 2 && !loading && (data?.whSearchProducts ?? []).length === 0 && <li className="px-4 py-3 text-sm text-muted-foreground">Nada con «{debounced}».</li>}
        </ul>
      </section>
    </div>
  )
}

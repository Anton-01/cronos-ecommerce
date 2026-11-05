import { useApolloClient, useMutation, useQuery } from '@apollo/client/react'
import { Download, Printer, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect } from '@/features/catalog/shared'
import { Field } from '@/features/pricing/CurrenciesPage'
import { WhLocationsDocument, WhScanDocument, WhLabelStyleDocument, WhUpdateLabelStyleDocument } from '@/graphql/generated'

import { LocationSelect } from './LocationSelect'
import { ProductPicker } from './ProductPicker'
import { DEFAULT_STYLE, labelPng, printLabels, qrSvg, type LabelData, type LabelStyle } from './labels'
import { download, itemQrPayload, locationQrPayload } from './lib'
import { useWarehouse } from './useWarehouse'

interface Entry extends LabelData {
  key: string
  copies: number
}

/** QR labels for products and shelves: the look is set here (and saved for everyone), then downloaded as images or sent to the printer. */
export function LabelsPage() {
  const { can } = useAuth()
  const run = useRun()
  const client = useApolloClient()
  const { current } = useWarehouse()
  const canStyle = can('warehouse.locations')
  const { data } = useQuery(WhLabelStyleDocument)
  const [save, { loading: saving }] = useMutation(WhUpdateLabelStyleDocument)
  const saved: LabelStyle = useMemo(() => {
    if (!data?.whLabelStyle) return DEFAULT_STYLE
    const { foreground, background, errorCorrection, margin, widthMm, heightMm, qrSizeMm, headerText, showName, showSku, showCode } = data.whLabelStyle
    return { foreground, background, errorCorrection, margin, widthMm, heightMm, qrSizeMm, headerText, showName, showSku, showCode }
  }, [data])
  const [draft, setDraft] = useState<LabelStyle | null>(null)
  const style = draft ?? saved
  const setStyle = (next: LabelStyle | ((s: LabelStyle) => LabelStyle)) => setDraft((d) => (typeof next === 'function' ? next(d ?? saved) : next))
  const [extra, setList] = useState<Entry[]>([])
  const [dismissedUrl, setDismissedUrl] = useState(false)
  const [shelf, setShelf] = useState('')
  const [busy, setBusy] = useState(false)
  const [params] = useSearchParams()
  const origin = typeof window === 'undefined' ? '' : window.location.origin

  const addShelf = (code: string, name: string) => setList((l) => (l.some((e) => e.key === `s-${code}`) ? l : [...l, { key: `s-${code}`, payload: locationQrPayload(origin, code), name, sku: null, code, copies: 1 }]))
  const { data: locs } = useQuery(WhLocationsDocument, { variables: { warehouseId: current?.id ?? '', includeInactive: false }, skip: !current })
  // The link from the shelf tree pre-selects its shelf.
  const fromUrl = locs?.whLocations.find((l) => l.code === params.get('shelf'))
  const list: Entry[] = !dismissedUrl && fromUrl && !extra.some((e) => e.key === `s-${fromUrl.code}`) ? [{ key: `s-${fromUrl.code}`, payload: locationQrPayload(origin, fromUrl.code), name: fromUrl.name, sku: null, code: fromUrl.code, copies: 1 }, ...extra] : extra

  const dirty = JSON.stringify(style) !== JSON.stringify(saved)
  const set = <K extends keyof LabelStyle>(k: K, v: LabelStyle[K]) => setStyle((s) => ({ ...s, [k]: v }))
  const num = (v: string) => (v === '' ? 0 : Number(v))

  const addProduct = async (productId: string, code: string) => {
    const { data: d } = await client.query({ query: WhScanDocument, variables: { code }, fetchPolicy: 'network-only' })
    const s = d?.whScan
    if (!s) return toast.error('No encontramos ese producto.')
    setList((l) => (l.some((e) => e.key === `p-${productId}`) ? l : [...l, { key: `p-${productId}`, payload: itemQrPayload(origin, s.stockCode), name: s.name, sku: s.sku, code: s.stockCode, copies: 1 }]))
  }

  const sample: LabelData = useMemo(() => ({ payload: `${origin}/w/7K3M9Q2XA4B`, name: 'Listón de satén rojo 25 mm', sku: 'LIS-SAT-ROJ-25', code: '7K3M9Q2XA4B' }), [origin])
  const first = list[0] ?? sample
  const [preview, setPreview] = useState('')
  useEffect(() => {
    let cancelled = false
    qrSvg(first.payload, style).then((svg) => !cancelled && setPreview(svg)).catch(() => undefined)
    return () => {
      cancelled = true
    }
  }, [first.payload, style])

  const render = async (e: Entry) => labelPng(e, style)
  const downloadAll = async () => {
    setBusy(true)
    try {
      for (const e of list) download(`etiqueta-${e.sku ?? e.code}.png`, await render(e))
    } finally {
      setBusy(false)
    }
  }
  const print = async () => {
    setBusy(true)
    try {
      const blobs: Blob[] = []
      for (const e of list) {
        const png = await render(e)
        for (let i = 0; i < e.copies; i++) blobs.push(png)
      }
      await printLabels(blobs, style)
    } finally {
      setBusy(false)
    }
  }

  const total = list.reduce((n, e) => n + e.copies, 0)

  return (
    <div className="grid gap-6 lg:grid-cols-[22rem_1fr]">
      <section aria-labelledby="style-h" className="space-y-4 rounded-lg border p-4">
        <h2 id="style-h" className="font-semibold">
          Estilo de la etiqueta
        </h2>
        {!canStyle && <p className="text-xs text-muted-foreground">Solo quien administra ubicaciones puede guardar cambios; aquí puedes probarlos.</p>}
        <div className="grid grid-cols-2 gap-3">
          <Field id="st-fg" label="Color del QR">
            <Input id="st-fg" type="color" className="h-10 p-1" value={style.foreground} onChange={(e) => set('foreground', e.target.value.toUpperCase())} />
          </Field>
          <Field id="st-bg" label="Fondo">
            <Input id="st-bg" type="color" className="h-10 p-1" value={style.background} onChange={(e) => set('background', e.target.value.toUpperCase())} />
          </Field>
          <Field id="st-w" label="Ancho (mm)">
            <Input id="st-w" inputMode="numeric" value={style.widthMm || ''} onChange={(e) => set('widthMm', num(e.target.value))} />
          </Field>
          <Field id="st-h" label="Alto (mm)">
            <Input id="st-h" inputMode="numeric" value={style.heightMm || ''} onChange={(e) => set('heightMm', num(e.target.value))} />
          </Field>
          <Field id="st-q" label="Tamaño del QR (mm)">
            <Input id="st-q" inputMode="numeric" value={style.qrSizeMm || ''} onChange={(e) => set('qrSizeMm', num(e.target.value))} />
          </Field>
          <Field id="st-m" label="Margen del QR">
            <Input id="st-m" inputMode="numeric" value={style.margin} onChange={(e) => set('margin', num(e.target.value))} />
          </Field>
        </div>
        <Field id="st-ec" label="Resistencia a daños del QR">
          <NativeSelect id="st-ec" value={style.errorCorrection} onChange={(e) => set('errorCorrection', e.target.value)}>
            <option value="L">Baja (más pequeño)</option>
            <option value="M">Media (recomendada)</option>
            <option value="Q">Alta</option>
            <option value="H">Máxima (para etiquetas que se rayan)</option>
          </NativeSelect>
        </Field>
        <Field id="st-head" label="Encabezado (opcional)">
          <Input id="st-head" value={style.headerText} maxLength={40} onChange={(e) => set('headerText', e.target.value)} placeholder="Nombre de la empresa" />
        </Field>
        <fieldset className="space-y-1">
          <legend className="text-sm font-medium">Qué texto lleva</legend>
          {([['showName', 'Nombre'], ['showSku', 'SKU'], ['showCode', 'Código debajo del QR']] as const).map(([k, label]) => (
            <label key={k} className="flex items-center gap-2 text-sm">
              <input type="checkbox" className="size-4" checked={style[k]} onChange={(e) => set(k, e.target.checked)} /> {label}
            </label>
          ))}
        </fieldset>
        <div className="flex gap-2">
          <Button disabled={!canStyle || !dirty || saving} onClick={() => void run(() => save({ variables: { input: style } }).then(() => setDraft(null)), 'Estilo guardado para todos')}>
            Guardar estilo
          </Button>
          <Button variant="outline" disabled={!dirty} onClick={() => setDraft(null)}>
            Deshacer
          </Button>
        </div>

        <div aria-label="Vista previa de la etiqueta" role="img" className="overflow-hidden rounded-md border bg-muted/40 p-3">
          <div className="mx-auto flex items-center gap-2 border shadow-sm" style={{ width: `${Math.min(style.widthMm * 3, 290)}px`, aspectRatio: `${style.widthMm} / ${style.heightMm}`, background: style.background, color: style.foreground }}>
            <div className="h-full shrink-0 p-1" style={{ width: `${(Math.min(style.qrSizeMm, style.heightMm) / style.widthMm) * 100}%` }} dangerouslySetInnerHTML={{ __html: preview }} />
            <div className="min-w-0 flex-1 pr-1 text-[10px] leading-tight">
              {style.headerText && <p className="truncate font-semibold">{style.headerText}</p>}
              {style.showName && <p className="line-clamp-3 font-bold">{first.name}</p>}
              {style.showSku && <p>{first.sku}</p>}
              {style.showCode && <p className="font-mono">{first.code.slice(0, 10)}-{first.code.slice(10)}</p>}
            </div>
          </div>
          <p className="mt-2 text-center text-xs text-muted-foreground">
            {style.widthMm} × {style.heightMm} mm
          </p>
        </div>
      </section>

      <section aria-labelledby="print-h" className="space-y-4">
        <h2 id="print-h" className="font-semibold">
          Qué etiquetas
        </h2>
        <Tabs defaultValue="products">
          <TabsList>
            <TabsTrigger value="products">Productos</TabsTrigger>
            <TabsTrigger value="shelves">Anaqueles</TabsTrigger>
          </TabsList>
          <TabsContent value="products">
            <ProductPicker onPick={(p) => void addProduct(p.productId, p.stockCode)} />
          </TabsContent>
          <TabsContent value="shelves" className="space-y-2">
            {current && <LocationSelect warehouseId={current.id} value={shelf} onChange={(id) => { setShelf(id); const l = locs?.whLocations.find((x) => x.id === id); if (l) addShelf(l.code, l.name) }} />}
            <p className="text-xs text-muted-foreground">El QR del anaquel solo lleva su código; al escanearlo se ve lo que hay encima.</p>
          </TabsContent>
        </Tabs>

        <ul className="divide-y rounded-lg border">
          {list.length === 0 && <li className="p-4 text-sm text-muted-foreground">Agrega productos o anaqueles para imprimir. El QR de un producto sirve aunque no tenga existencias o esté inactivo: el estado se ve al escanear.</li>}
          {list.map((e) => (
            <li key={e.key} className="flex items-center justify-between gap-3 px-3 py-2">
              <span className="min-w-0">
                <span className="block truncate font-medium">{e.name ?? e.sku ?? e.code}</span>
                <span className="block font-mono text-xs text-muted-foreground">{e.code.length === 11 ? `${e.code.slice(0, 10)}-${e.code.slice(10)}` : e.code}</span>
              </span>
              <span className="flex items-center gap-2">
                <label className="sr-only" htmlFor={`c-${e.key}`}>
                  Copias de {e.name ?? e.code}
                </label>
                <Input id={`c-${e.key}`} className="h-9 w-16" inputMode="numeric" value={e.copies} onChange={(ev) => (e.key === `s-${fromUrl?.code}` && !extra.some((x) => x.key === e.key) ? setList((l) => [...l, { ...e, copies: Math.max(1, Math.min(200, Number(ev.target.value) || 1)) }]) : setList((l) => l.map((x) => (x.key === e.key ? { ...x, copies: Math.max(1, Math.min(200, Number(ev.target.value) || 1)) } : x))))} />
                <Button variant="ghost" size="icon" aria-label={`Quitar ${e.name ?? e.code}`} onClick={() => { setList((l) => l.filter((x) => x.key !== e.key)); if (e.key === `s-${fromUrl?.code}`) setDismissedUrl(true) }}>
                  <Trash2 className="size-4" />
                </Button>
              </span>
            </li>
          ))}
        </ul>

        <div className="flex flex-wrap gap-2">
          <Button disabled={list.length === 0 || busy} onClick={() => void print()}>
            <Printer className="size-4" /> Imprimir {total > 0 ? `(${total})` : ''}
          </Button>
          <Button variant="outline" disabled={list.length === 0 || busy} onClick={() => void downloadAll()}>
            <Download className="size-4" /> Descargar imágenes
          </Button>
        </div>
      </section>
    </div>
  )
}

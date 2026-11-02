import { useMutation, useQuery } from '@apollo/client/react'
import { ArrowDown, ArrowUp, Plus, Trash2, Upload } from 'lucide-react'
import { useRef, useState } from 'react'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NativeSelect } from '@/features/catalog/shared'
import { useRun } from '@/features/catalog/products/useRun'
import { MediaPicker } from '@/features/media/MediaPicker'
import { ImageSlotsDocument, RemoveThemeAssetDocument, SetThemeAssetDocument, UploadHeroImageDocument, UploadThemeAssetDocument, type ThemeAssetSlot } from '@/graphql/generated'
import { uploadFile } from '@/lib/upload'

import { CropDialog } from './CropDialog'
import type { CropInput } from './crop'
import { moveSection, newSection, removeSection, SECTION_LABEL, setSection, withLocale } from './sections'
import { COLOR_GROUPS, LOCALES, SLOTS, type Asset, type HeroSlide, type HomeSection, type NavLink, type Tokens } from './types'
import type { useThemeDraft } from './useThemeDraft'

type D = ReturnType<typeof useThemeDraft>
interface TabProps { d: D; canEdit: boolean }

function Field({ label, children, hint }: { label: string; children: React.ReactNode; hint?: string }) {
  return (
    <div className="space-y-1">
      <Label>{label}</Label>
      {children}
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader><CardTitle className="text-base">{title}</CardTitle></CardHeader>
      <CardContent className="space-y-3">{children}</CardContent>
    </Card>
  )
}

/** One text per language, side by side. */
function LocalizedInput({ label, value, onChange, disabled }: { label: string; value: Record<string, string> | undefined; onChange: (v: Record<string, string>) => void; disabled?: boolean }) {
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      {LOCALES.map((l) => (
        <Field key={l.code} label={`${label} (${l.label})`}>
          <Input disabled={disabled} value={value?.[l.code] ?? ''} onChange={(e) => onChange(withLocale(value, l.code, e.target.value))} />
        </Field>
      ))}
    </div>
  )
}

/* ------------------------------------------------------------ Identity */

export function IdentityTab({ d, canEdit }: TabProps) {
  const { tokens } = d.values!
  const set = (identity: Partial<Tokens['identity']>) => d.update({ tokens: { ...tokens, identity: { ...tokens.identity, ...identity } } })
  const { data } = useQuery(ImageSlotsDocument)
  const assets = d.theme!.draft.assets as Record<string, Asset | null>
  const run = useRun()
  const [upload, { loading: uploading }] = useMutation(UploadThemeAssetDocument)
  const [setAsset] = useMutation(SetThemeAssetDocument)
  const [remove] = useMutation(RemoveThemeAssetDocument)
  const [pending, setPending] = useState<{ file: File; slot: (typeof SLOTS)[number] } | null>(null)
  const [picking, setPicking] = useState<(typeof SLOTS)[number] | null>(null)
  const inputs = useRef<Record<string, HTMLInputElement | null>>({})

  const specFor = (imageSlot: string) => data?.imageSlots.find((s) => s.slot.toLowerCase() === imageSlot)
  const adopt = (theme: Parameters<D['adopt']>[0]) => d.adopt(theme)
  const send = async (crop: CropInput | null) => {
    if (!pending) return
    const ok = await run(async () => {
      await d.saveNow()
      const res = await upload({ variables: { slot: pending.slot.slot as ThemeAssetSlot, file: pending.file, crop } })
      adopt(res.data!.uploadThemeAsset)
    }, `${pending.slot.title} actualizado.`)
    if (ok) setPending(null)
  }

  return (
    <div className="space-y-4">
      <Section title="Nombre de la tienda">
        <Field label="Nombre"><Input disabled={!canEdit} value={tokens.identity.siteName} onChange={(e) => set({ siteName: e.target.value })} /></Field>
        <LocalizedInput label="Lema" value={tokens.identity.tagline} disabled={!canEdit} onChange={(tagline) => set({ tagline })} />
        <Field label="Color de la barra del navegador en el móvil" hint="Vacío = el color principal.">
          <Input type="color" className="h-9 w-20 p-1" disabled={!canEdit} value={tokens.identity.themeColor ?? tokens.colors.primary ?? '#000000'} onChange={(e) => set({ themeColor: e.target.value })} />
        </Field>
      </Section>
      {SLOTS.map((slot) => {
        const asset = assets?.[slot.key] ?? null
        const spec = specFor(slot.imageSlot)
        return (
          <Section key={slot.slot} title={slot.title}>
            <p className="text-sm text-muted-foreground">{slot.help}{spec && ` Tamaño recomendado: ${spec.recommendedWidth}×${spec.recommendedHeight} px, mínimo ${spec.minWidth}×${spec.minHeight} px.`}</p>
            <div className="flex flex-wrap items-center gap-4">
              <div className="flex h-20 min-w-32 items-center justify-center rounded-md border bg-[conic-gradient(#eee_25%,#fff_0_50%,#eee_0_75%,#fff_0)] bg-[length:16px_16px] p-2">
                {asset?.url ? <img src={asset.url} alt={slot.title} className="max-h-16 max-w-48 object-contain" /> : <span className="text-xs text-muted-foreground">Sin imagen</span>}
              </div>
              {asset && <span className="text-xs text-muted-foreground">{asset.kind === 'svg' ? 'SVG' : `${asset.width}×${asset.height} px · ${asset.files.length} archivos generados`}</span>}
              {canEdit && (
                <div className="flex flex-wrap gap-2">
                  <input ref={(el) => { inputs.current[slot.slot] = el }} type="file" hidden accept={slot.accepts} aria-label={`Subir ${slot.title}`} onChange={(e) => { const file = e.target.files?.[0]; if (file) setPending({ file, slot }); e.target.value = '' }} />
                  <Button size="sm" variant="outline" disabled={uploading} onClick={() => inputs.current[slot.slot]?.click()}><Upload className="size-4" /> Subir y recortar</Button>
                  <Button size="sm" variant="outline" onClick={() => setPicking(slot)}>Elegir de la biblioteca</Button>
                  {asset && <Button size="sm" variant="ghost" onClick={() => run(async () => { adopt((await remove({ variables: { slot: slot.slot as ThemeAssetSlot } })).data!.removeThemeAsset) }, 'Imagen quitada.')}><Trash2 className="size-4" /> Quitar</Button>}
                </div>
              )}
            </div>
          </Section>
        )
      })}
      <CropDialog
        file={pending?.file ?? null}
        title={pending ? `Ajustar: ${pending.slot.title}` : ''}
        ratio={pending ? (specFor(pending.slot.imageSlot)?.ratio ?? null) : null}
        min={{ width: specFor(pending?.slot.imageSlot ?? 'logo')?.minWidth ?? 1, height: specFor(pending?.slot.imageSlot ?? 'logo')?.minHeight ?? 1 }}
        allowTrim={pending?.slot.imageSlot === 'logo'}
        busy={uploading}
        onCancel={() => setPending(null)}
        onConfirm={send}
      />
      <MediaPicker
        open={picking !== null}
        onOpenChange={(o) => !o && setPicking(null)}
        onPick={(item) => picking && run(async () => { await d.saveNow(); adopt((await setAsset({ variables: { slot: picking.slot as ThemeAssetSlot, mediaId: item.id } })).data!.setThemeAsset) }, `${picking.title} actualizado.`)}
      />
    </div>
  )
}

/* -------------------------------------------------------------- Colors */

export function ColorsTab({ d, canEdit, onPreset, onReset }: TabProps & { onPreset: (key: string) => void; onReset: () => void }) {
  const { tokens } = d.values!
  const setColor = (key: string, value: string | null) => d.update({ tokens: { ...tokens, colors: { ...tokens.colors, [key]: value } } })
  const failing = d.contrast.filter((c) => !c.ok)

  return (
    <div className="space-y-4">
      <Section title="Preajustes">
        <div className="flex flex-wrap gap-2">
          {d.theme!.presets.map((p) => (
            <Button key={p.key} size="sm" variant="outline" disabled={!canEdit} title={p.description} onClick={() => onPreset(p.key)}>{p.label}</Button>
          ))}
          <Button size="sm" variant="ghost" disabled={!canEdit} onClick={onReset}>Restablecer</Button>
        </div>
        <p className="text-xs text-muted-foreground">Un preajuste sustituye los colores, la tipografía y las formas del borrador. Puedes ajustar cualquier valor después.</p>
      </Section>
      {failing.length > 0 && (
        <div role="status" className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
          <b>Legibilidad:</b> estas combinaciones se leen mal (mínimo recomendado WCAG AA). Puedes publicarlas, pero conviene corregirlas.
          <ul className="mt-1 list-disc pl-5">{failing.map((c) => <li key={c.pair}>{c.pair}: contraste {c.ratio.toFixed(2)} (mínimo {c.needed})</li>)}</ul>
        </div>
      )}
      {COLOR_GROUPS.map((group) => (
        <Section key={group.title} title={group.title}>
          <div className="grid gap-3 sm:grid-cols-2">
            {group.keys.map(({ key, label, optional }) => {
              const value = tokens.colors[key] ?? null
              return (
                <Field key={key} label={label}>
                  <div className="flex items-center gap-2">
                    <Input type="color" aria-label={label} className="h-9 w-12 p-1" disabled={!canEdit} value={value ?? '#000000'} onChange={(e) => setColor(key, e.target.value)} />
                    <Input aria-label={`${label} (hexadecimal)`} className="font-mono" disabled={!canEdit} value={value ?? ''} placeholder={optional ? 'Automático' : '#RRGGBB'} onChange={(e) => setColor(key, e.target.value === '' && optional ? null : e.target.value)} />
                  </div>
                </Field>
              )
            })}
          </div>
        </Section>
      ))}
    </div>
  )
}

/* --------------------------------------------------------------- Style */

export function StyleTab({ d, canEdit }: TabProps) {
  const { tokens } = d.values!
  const { typography: t, shape: s, layout: l } = tokens
  const setT = (p: Partial<typeof t>) => d.update({ tokens: { ...tokens, typography: { ...t, ...p } } })
  const setS = (p: Partial<typeof s>) => d.update({ tokens: { ...tokens, shape: { ...s, ...p } } })
  const setL = (p: Partial<typeof l>) => d.update({ tokens: { ...tokens, layout: { ...l, ...p } } })
  const num = (v: string) => (v === '' ? 0 : Number(v))
  const fonts = d.theme!.fonts
  const sel = (label: string, value: string, options: [string, string][], onChange: (v: string) => void) => (
    <Field label={label}>
      <NativeSelect aria-label={label} disabled={!canEdit} value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
      </NativeSelect>
    </Field>
  )
  const numField = (label: string, value: number, onChange: (v: number) => void, step = 1) => (
    <Field label={label}><Input aria-label={label} type="number" step={step} disabled={!canEdit} value={value} onChange={(e) => onChange(num(e.target.value))} /></Field>
  )

  return (
    <div className="space-y-4">
      <Section title="Tipografía">
        <div className="grid gap-3 sm:grid-cols-2">
          {sel('Fuente de títulos', t.headingFont, fonts.map((f) => [f.family, f.label]), (v) => setT({ headingFont: v }))}
          {sel('Fuente de texto', t.bodyFont, fonts.map((f) => [f.family, f.label]), (v) => setT({ bodyFont: v }))}
          {numField('Grosor de títulos', t.headingWeight, (v) => setT({ headingWeight: v }), 100)}
          {numField('Grosor del texto', t.bodyWeight, (v) => setT({ bodyWeight: v }), 100)}
          {numField('Tamaño base (px)', t.baseSize, (v) => setT({ baseSize: v }))}
          {numField('Interlineado', t.lineHeight, (v) => setT({ lineHeight: v }), 0.1)}
          {sel('Mayúsculas en títulos', t.headingTransform, [['none', 'Normal'], ['capitalize', 'Capitalizar'], ['uppercase', 'MAYÚSCULAS']], (v) => setT({ headingTransform: v as typeof t.headingTransform }))}
          {numField('Espaciado entre letras (px)', t.letterSpacing, (v) => setT({ letterSpacing: v }), 0.1)}
        </div>
      </Section>
      <Section title="Formas">
        <div className="grid gap-3 sm:grid-cols-2">
          {numField('Radio de tarjetas (px)', s.radiusCard, (v) => setS({ radiusCard: v }))}
          {numField('Radio de botones (px)', s.radiusButton, (v) => setS({ radiusButton: v }))}
          {numField('Radio de campos (px)', s.radiusInput, (v) => setS({ radiusInput: v }))}
          {numField('Radio de etiquetas (px)', s.radiusBadge, (v) => setS({ radiusBadge: v }))}
          {sel('Forma de las categorías', s.categoryShape, [['circle', 'Círculo'], ['rounded', 'Redondeada'], ['square', 'Cuadrada']], (v) => setS({ categoryShape: v as typeof s.categoryShape }))}
          {sel('Estilo de tarjetas', s.cardStyle, [['flat', 'Plana'], ['bordered', 'Con borde'], ['shadow', 'Con sombra']], (v) => setS({ cardStyle: v as typeof s.cardStyle }))}
          {sel('Estilo de botones', s.buttonStyle, [['filled', 'Rellenos'], ['outline', 'Con contorno']], (v) => setS({ buttonStyle: v as typeof s.buttonStyle }))}
        </div>
      </Section>
      <Section title="Distribución">
        <div className="grid gap-3 sm:grid-cols-2">
          {numField('Ancho máximo (px)', l.containerWidth, (v) => setL({ containerWidth: v }), 10)}
          {sel('Densidad', l.density, [['comfortable', 'Cómoda'], ['compact', 'Compacta']], (v) => setL({ density: v as typeof l.density }))}
          {numField('Columnas de productos (escritorio)', l.gridColumnsDesktop, (v) => setL({ gridColumnsDesktop: v }))}
          {numField('Columnas de productos (móvil)', l.gridColumnsMobile, (v) => setL({ gridColumnsMobile: v }))}
          {sel('Encabezado', l.headerStyle, [['classic', 'Clásico'], ['centered', 'Centrado']], (v) => setL({ headerStyle: v as typeof l.headerStyle }))}
          {sel('Fondo de la página', l.backgroundTexture, [['grain', 'Con grano sutil'], ['none', 'Liso']], (v) => setL({ backgroundTexture: v as typeof l.backgroundTexture }))}
        </div>
        {([['showTopbar', 'Mostrar la barra superior'], ['showCategoryNav', 'Mostrar la fila de categorías'], ['stickyHeader', 'Encabezado fijo al desplazarse']] as const).map(([key, text]) => (
          <div key={key} className="flex items-center gap-2">
            <Checkbox id={key} disabled={!canEdit} checked={l[key]} onCheckedChange={(v) => setL({ [key]: v === true })} />
            <Label htmlFor={key}>{text}</Label>
          </div>
        ))}
      </Section>
    </div>
  )
}

/* ---------------------------------------------------------- Navigation */

function LinkList({ links, onChange, disabled, addLabel }: { links: NavLink[]; onChange: (l: NavLink[]) => void; disabled: boolean; addLabel: string }) {
  return (
    <div className="space-y-2">
      {links.map((link, i) => (
        <div key={i} className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
          {LOCALES.map((l) => (
            <Input key={l.code} aria-label={`Texto del enlace ${i + 1} (${l.label})`} placeholder={l.label} disabled={disabled} value={link.label[l.code] ?? ''} onChange={(e) => onChange(links.map((x, j) => (j === i ? { ...x, label: withLocale(x.label, l.code, e.target.value) } : x)))} />
          ))}
          <div className="flex gap-1">
            <Input aria-label={`Dirección del enlace ${i + 1}`} placeholder="/ruta o https://…" disabled={disabled} value={link.url} onChange={(e) => onChange(links.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)))} />
            <Button type="button" size="icon" variant="ghost" aria-label={`Quitar enlace ${i + 1}`} disabled={disabled} onClick={() => onChange(links.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
          </div>
        </div>
      ))}
      <Button type="button" size="sm" variant="outline" disabled={disabled} onClick={() => onChange([...links, { label: { 'es-MX': '', 'en-US': '' }, url: '' }])}><Plus className="size-4" /> {addLabel}</Button>
    </div>
  )
}

export function NavigationTab({ d, canEdit }: TabProps) {
  const { navigation: n } = d.values!
  const set = (p: Partial<typeof n>) => d.update({ navigation: { ...n, ...p } })
  return (
    <div className="space-y-4">
      <Section title="Barra superior">
        <LinkList links={n.topbarLinks} disabled={!canEdit} addLabel="Agregar enlace" onChange={(topbarLinks) => set({ topbarLinks })} />
      </Section>
      <Section title="Cinta de avisos">
        <div className="flex items-center gap-2">
          <Checkbox id="ribbon" disabled={!canEdit} checked={n.showFreeShippingRibbon} onCheckedChange={(v) => set({ showFreeShippingRibbon: v === true })} />
          <Label htmlFor="ribbon">Mostrar «Envío gratis desde…» con el umbral de cada tienda</Label>
        </div>
        {n.announcements.map((a, i) => (
          <div key={i} className="space-y-2 rounded-md border p-3">
            <LocalizedInput label="Aviso" value={a.text} disabled={!canEdit} onChange={(text) => set({ announcements: n.announcements.map((x, j) => (j === i ? { ...x, text } : x)) })} />
            <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
              <Field label="Tiendas (códigos separados por coma; vacío = todas)"><Input disabled={!canEdit} value={a.markets.join(', ')} onChange={(e) => set({ announcements: n.announcements.map((x, j) => (j === i ? { ...x, markets: e.target.value.split(',').map((m) => m.trim().toUpperCase()).filter(Boolean) } : x)) })} /></Field>
              <Field label="Enlace (opcional)"><Input disabled={!canEdit} value={a.url ?? ''} onChange={(e) => set({ announcements: n.announcements.map((x, j) => (j === i ? { ...x, url: e.target.value || null } : x)) })} /></Field>
              <Button type="button" size="icon" variant="ghost" className="self-end" aria-label={`Quitar aviso ${i + 1}`} disabled={!canEdit} onClick={() => set({ announcements: n.announcements.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></Button>
            </div>
          </div>
        ))}
        <Button type="button" size="sm" variant="outline" disabled={!canEdit} onClick={() => set({ announcements: [...n.announcements, { markets: [], text: { 'es-MX': '', 'en-US': '' }, url: null }] })}><Plus className="size-4" /> Agregar aviso</Button>
      </Section>
      <Section title="Pie de página">
        {n.footerColumns.map((col, i) => (
          <div key={i} className="space-y-2 rounded-md border p-3">
            <div className="flex items-end gap-2">
              <div className="flex-1"><LocalizedInput label="Título de la columna" value={col.title} disabled={!canEdit} onChange={(title) => set({ footerColumns: n.footerColumns.map((x, j) => (j === i ? { ...x, title } : x)) })} /></div>
              <Button type="button" size="icon" variant="ghost" aria-label={`Quitar columna ${i + 1}`} disabled={!canEdit} onClick={() => set({ footerColumns: n.footerColumns.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></Button>
            </div>
            <LinkList links={col.links} disabled={!canEdit} addLabel="Agregar enlace" onChange={(links) => set({ footerColumns: n.footerColumns.map((x, j) => (j === i ? { ...x, links } : x)) })} />
          </div>
        ))}
        <Button type="button" size="sm" variant="outline" disabled={!canEdit} onClick={() => set({ footerColumns: [...n.footerColumns, { title: { 'es-MX': '', 'en-US': '' }, links: [] }] })}><Plus className="size-4" /> Agregar columna</Button>
        <LocalizedInput label="Nota al pie" value={n.footerNote} disabled={!canEdit} onChange={(footerNote) => set({ footerNote })} />
      </Section>
      <Section title="Redes sociales">
        <div className="grid gap-3 sm:grid-cols-2">
          {['facebook', 'instagram', 'tiktok', 'youtube', 'pinterest', 'x', 'whatsapp'].map((k) => (
            <Field key={k} label={k[0]!.toUpperCase() + k.slice(1)}><Input disabled={!canEdit} placeholder="https://…" value={n.social[k] ?? ''} onChange={(e) => set({ social: { ...n.social, [k]: e.target.value } })} /></Field>
          ))}
        </div>
      </Section>
    </div>
  )
}

/* ---------------------------------------------------------------- Home */

function ImagePick({ label, value, onChange, disabled }: { label: string; value: string | null; onChange: (id: string | null) => void; disabled: boolean }) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const input = useRef<HTMLInputElement>(null)
  const upload = async (file: File) => {
    setBusy(true)
    try {
      const res = await uploadFile(UploadHeroImageDocument, { file, slot: 'HERO' as never })
      res.uploadMedia.warnings?.forEach((w: string) => toast.warning(w))
      onChange(res.uploadMedia.id)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo subir la imagen.')
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className="flex flex-wrap items-center gap-2 text-sm">
      <span className="font-medium">{label}:</span>
      <span className="text-muted-foreground">{value ? 'Imagen elegida' : 'Sin imagen'}</span>
      <input ref={input} type="file" hidden accept="image/*" aria-label={`Subir ${label}`} onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = '' }} />
      <Button type="button" size="sm" variant="outline" disabled={disabled || busy} onClick={() => input.current?.click()}>{busy ? 'Subiendo…' : 'Subir'}</Button>
      <Button type="button" size="sm" variant="outline" disabled={disabled} onClick={() => setOpen(true)}>Biblioteca</Button>
      {value && <Button type="button" size="sm" variant="ghost" disabled={disabled} onClick={() => onChange(null)}>Quitar</Button>}
      <MediaPicker open={open} onOpenChange={setOpen} onPick={(item) => onChange(item.id)} />
    </div>
  )
}

function SectionEditor({ section, onChange, disabled }: { section: HomeSection; onChange: (s: Record<string, unknown>) => void; disabled: boolean }) {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- section settings differ per type and are validated by the server
  const st = section.settings as Record<string, any>
  const put = (p: Record<string, unknown>) => onChange({ ...st, ...p })
  if (section.reserved) return <p className="text-sm text-muted-foreground">Este bloque está reservado: aún no existe el módulo que lo alimenta.</p>

  if (section.type === 'hero') {
    const slides = (st.slides ?? []) as HeroSlide[]
    const setSlide = (i: number, p: Partial<HeroSlide>) => put({ slides: slides.map((s, j) => (j === i ? { ...s, ...p } : s)) })
    return (
      <div className="space-y-3">
        {slides.map((slide, i) => (
          <div key={i} className="space-y-2 rounded-md border p-3">
            <ImagePick label="Imagen de escritorio (2.4:1, 2560×1067 recomendado)" value={slide.image} disabled={disabled} onChange={(image) => setSlide(i, { image })} />
            <ImagePick label="Imagen de móvil (opcional)" value={slide.imageMobile} disabled={disabled} onChange={(imageMobile) => setSlide(i, { imageMobile })} />
            <LocalizedInput label="Título" value={slide.title} disabled={disabled} onChange={(title) => setSlide(i, { title })} />
            <LocalizedInput label="Subtítulo" value={slide.subtitle} disabled={disabled} onChange={(subtitle) => setSlide(i, { subtitle })} />
            <LocalizedInput label="Texto del botón" value={slide.ctaLabel} disabled={disabled} onChange={(ctaLabel) => setSlide(i, { ctaLabel })} />
            <div className="grid gap-2 sm:grid-cols-3">
              <Field label="Enlace del botón"><Input disabled={disabled} value={slide.ctaUrl ?? ''} onChange={(e) => setSlide(i, { ctaUrl: e.target.value || null })} /></Field>
              <Field label="Alineación"><NativeSelect disabled={disabled} value={slide.align} onChange={(e) => setSlide(i, { align: e.target.value as HeroSlide['align'] })}><option value="left">Izquierda</option><option value="center">Centro</option><option value="right">Derecha</option></NativeSelect></Field>
              <Field label="Oscurecer imagen (0–80 %)"><Input type="number" min={0} max={80} disabled={disabled} value={slide.overlay} onChange={(e) => setSlide(i, { overlay: Number(e.target.value) })} /></Field>
            </div>
            <Button type="button" size="sm" variant="ghost" disabled={disabled} onClick={() => put({ slides: slides.filter((_, j) => j !== i) })}><Trash2 className="size-4" /> Quitar diapositiva</Button>
          </div>
        ))}
        <Button type="button" size="sm" variant="outline" disabled={disabled} onClick={() => put({ slides: [...slides, { image: null, imageMobile: null, kicker: {}, title: { 'es-MX': '', 'en-US': '' }, subtitle: { 'es-MX': '', 'en-US': '' }, priceText: {}, ctaLabel: { 'es-MX': '', 'en-US': '' }, ctaUrl: null, align: 'left', overlay: 20 }] })}><Plus className="size-4" /> Agregar diapositiva</Button>
      </div>
    )
  }
  if (section.type === 'products') {
    return (
      <div className="space-y-2">
        <LocalizedInput label="Título" value={st.title} disabled={disabled} onChange={(title) => put({ title })} />
        <div className="grid gap-2 sm:grid-cols-2">
          <Field label="Qué mostrar"><NativeSelect disabled={disabled} value={st.source ?? 'newest'} onChange={(e) => put({ source: e.target.value })}><option value="newest">Novedades</option><option value="featured">Destacados</option><option value="best_selling">Más vendidos</option><option value="category">De una categoría</option><option value="manual">Elegidos a mano</option></NativeSelect></Field>
          <Field label="Cantidad"><Input type="number" min={1} max={24} disabled={disabled} value={st.limit ?? 10} onChange={(e) => put({ limit: Number(e.target.value) })} /></Field>
        </div>
      </div>
    )
  }
  if (section.type === 'categories') {
    return (
      <div className="space-y-2">
        <LocalizedInput label="Título" value={st.title} disabled={disabled} onChange={(title) => put({ title })} />
        <Field label="Cantidad máxima"><Input type="number" min={1} max={20} disabled={disabled} value={st.max ?? 10} onChange={(e) => put({ max: Number(e.target.value) })} /></Field>
      </div>
    )
  }
  if (section.type === 'banner') {
    return (
      <div className="space-y-2">
        <ImagePick label="Imagen" value={st.image ?? null} disabled={disabled} onChange={(image) => put({ image })} />
        <LocalizedInput label="Título" value={st.title} disabled={disabled} onChange={(title) => put({ title })} />
        <LocalizedInput label="Texto" value={st.text} disabled={disabled} onChange={(text) => put({ text })} />
        <LocalizedInput label="Texto del botón" value={st.ctaLabel} disabled={disabled} onChange={(ctaLabel) => put({ ctaLabel })} />
        <Field label="Enlace del botón"><Input disabled={disabled} value={st.ctaUrl ?? ''} onChange={(e) => put({ ctaUrl: e.target.value || null })} /></Field>
      </div>
    )
  }
  if (section.type === 'text') {
    return (
      <div className="space-y-2">
        <LocalizedInput label="Título" value={st.title} disabled={disabled} onChange={(title) => put({ title })} />
        <LocalizedInput label="Texto" value={st.body} disabled={disabled} onChange={(body) => put({ body })} />
      </div>
    )
  }
  return null
}

export function HomeTab({ d, canEdit }: TabProps) {
  const { home } = d.values!
  const types = d.theme!.homeSectionTypes.filter((t) => !t.reserved)
  const [adding, setAdding] = useState(types[0]?.type ?? 'text')
  const set = (next: HomeSection[]) => d.update({ home: next })
  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">Ordena y activa los bloques de la página de inicio. Los bloques de marcas, colecciones y blog están reservados para cuando existan esos módulos.</p>
      {home.map((section, i) => (
        <Section key={section.id} title={`${SECTION_LABEL[section.type] ?? section.type}${section.enabled ? '' : ' (oculto)'}`}>
          <div className="flex flex-wrap items-center gap-2">
            <Checkbox id={`en-${section.id}`} disabled={!canEdit || section.reserved} checked={section.enabled} onCheckedChange={(v) => set(setSection(home, section.id, { enabled: v === true }))} />
            <Label htmlFor={`en-${section.id}`}>Mostrar</Label>
            <Button type="button" size="icon" variant="ghost" aria-label="Subir bloque" disabled={!canEdit || i === 0} onClick={() => set(moveSection(home, i, i - 1))}><ArrowUp className="size-4" /></Button>
            <Button type="button" size="icon" variant="ghost" aria-label="Bajar bloque" disabled={!canEdit || i === home.length - 1} onClick={() => set(moveSection(home, i, i + 1))}><ArrowDown className="size-4" /></Button>
            <Button type="button" size="icon" variant="ghost" aria-label="Quitar bloque" disabled={!canEdit} onClick={() => set(removeSection(home, section.id))}><Trash2 className="size-4" /></Button>
          </div>
          <SectionEditor section={section} disabled={!canEdit} onChange={(settings) => set(setSection(home, section.id, { settings }))} />
        </Section>
      ))}
      {canEdit && (
        <div className="flex items-center gap-2">
          <NativeSelect aria-label="Tipo de bloque" value={adding} onChange={(e) => setAdding(e.target.value)} className="w-56">
            {types.map((t) => <option key={t.type} value={t.type}>{SECTION_LABEL[t.type] ?? t.type}</option>)}
          </NativeSelect>
          <Button type="button" size="sm" variant="outline" onClick={() => set([...home, newSection(adding, home)])}><Plus className="size-4" /> Agregar bloque</Button>
        </div>
      )}
    </div>
  )
}

/* ------------------------------------------------------------ Features */

export function FeaturesTab({ d, canEdit }: TabProps) {
  const { features } = d.values!
  return (
    <Section title="Funciones de la tienda">
      <p className="text-sm text-muted-foreground">Enciende o apaga partes de la tienda. Las marcadas como reservadas se activarán cuando exista su módulo.</p>
      {d.theme!.features.map((f) => (
        <div key={f.key} className="flex items-center gap-2">
          <Checkbox id={`f-${f.key}`} disabled={!canEdit || f.reserved} checked={!f.reserved && features[f.key] === true} onCheckedChange={(v) => d.update({ features: { ...features, [f.key]: v === true } })} />
          <Label htmlFor={`f-${f.key}`}>{f.label}{f.reserved ? ' (próximamente)' : ''}</Label>
        </div>
      ))}
    </Section>
  )
}

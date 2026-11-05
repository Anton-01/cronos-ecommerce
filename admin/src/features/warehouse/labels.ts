import QRCode from 'qrcode'

export interface LabelStyle {
  foreground: string
  background: string
  errorCorrection: string
  margin: number
  widthMm: number
  heightMm: number
  qrSizeMm: number
  headerText: string
  showName: boolean
  showSku: boolean
  showCode: boolean
}

export interface LabelData {
  /** What goes inside the QR (a URL carrying only the code). */
  payload: string
  /** Text under / beside the QR. */
  name?: string | null
  sku?: string | null
  code: string
}

export const DEFAULT_STYLE: LabelStyle = {
  foreground: '#111827',
  background: '#FFFFFF',
  errorCorrection: 'M',
  margin: 2,
  widthMm: 60,
  heightMm: 40,
  qrSizeMm: 28,
  headerText: '',
  showName: true,
  showSku: true,
  showCode: true,
}

export interface Layout {
  qr: { x: number; y: number; size: number }
  text: { x: number; y: number; width: number; height: number; align: 'left' | 'center' }
  stacked: boolean
}

const PAD = 2

/**
 * Where things go, in millimetres. Side by side when the label is wide enough to leave a readable text column, stacked
 * (QR on top, text under it) when it is narrow or tall. Pure so it can be tested without a canvas.
 */
export function layoutLabel(style: Pick<LabelStyle, 'widthMm' | 'heightMm' | 'qrSizeMm'>): Layout {
  const { widthMm: w, heightMm: h } = style
  const qr = Math.min(style.qrSizeMm, w - 2 * PAD, h - 2 * PAD)
  const sideRoom = w - qr - 3 * PAD
  if (sideRoom >= 18) {
    return { stacked: false, qr: { x: PAD, y: (h - qr) / 2, size: qr }, text: { x: qr + 2 * PAD, y: PAD, width: sideRoom, height: h - 2 * PAD, align: 'left' } }
  }
  const textHeight = h - qr - 3 * PAD
  return { stacked: true, qr: { x: (w - qr) / 2, y: PAD, size: qr }, text: { x: PAD, y: qr + 2 * PAD, width: w - 2 * PAD, height: Math.max(textHeight, 0), align: 'center' } }
}

/** Splits text into at most `maxLines` lines that fit `maxWidth` (measured by `measure`), ending with … when it is cut. */
export function wrapText(text: string, maxWidth: number, maxLines: number, measure: (s: string) => number): string[] {
  const words = text.split(/\s+/).filter(Boolean)
  const lines: string[] = []
  let line = ''
  for (const word of words) {
    const tryLine = line ? `${line} ${word}` : word
    if (measure(tryLine) <= maxWidth || !line) {
      line = tryLine
    } else {
      lines.push(line)
      line = word
    }
    if (lines.length === maxLines) break
  }
  if (lines.length < maxLines && line) lines.push(line)
  const used = lines.join(' ').length
  if (used < text.trim().length - 1 || lines.length > maxLines) {
    const last = lines.length - 1
    let cut = lines[last] ?? ''
    while (cut.length > 1 && measure(`${cut}…`) > maxWidth) cut = cut.slice(0, -1)
    lines[last] = `${cut}…`
  }
  // a single word longer than the column is trimmed too
  return lines.slice(0, maxLines).map((l) => {
    let s = l
    while (s.length > 1 && measure(s) > maxWidth) s = `${s.slice(0, -2)}…`
    return s
  })
}

const ec = (level: string): 'L' | 'M' | 'Q' | 'H' => (['L', 'M', 'Q', 'H'].includes(level) ? (level as 'L' | 'M' | 'Q' | 'H') : 'M')

/** The QR as an SVG string (crisp at any size, what the on-screen preview uses). */
export async function qrSvg(payload: string, style: LabelStyle): Promise<string> {
  return QRCode.toString(payload, { type: 'svg', errorCorrectionLevel: ec(style.errorCorrection), margin: style.margin, color: { dark: style.foreground, light: style.background } })
}

/** The whole label as a PNG (300 dpi by default): what is downloaded and what is printed. */
export async function labelPng(data: LabelData, style: LabelStyle, dpi = 300): Promise<Blob> {
  const px = (mm: number) => Math.round((mm / 25.4) * dpi)
  const canvas = document.createElement('canvas')
  canvas.width = px(style.widthMm)
  canvas.height = px(style.heightMm)
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Canvas not available')
  ctx.fillStyle = style.background
  ctx.fillRect(0, 0, canvas.width, canvas.height)

  const L = layoutLabel(style)
  const qrCanvas = document.createElement('canvas')
  await QRCode.toCanvas(qrCanvas, data.payload, { errorCorrectionLevel: ec(style.errorCorrection), margin: style.margin, width: px(L.qr.size), color: { dark: style.foreground, light: style.background } })
  ctx.imageSmoothingEnabled = false
  ctx.drawImage(qrCanvas, px(L.qr.x), px(L.qr.y), px(L.qr.size), px(L.qr.size))

  ctx.fillStyle = style.foreground
  ctx.textBaseline = 'top'
  ctx.textAlign = L.text.align
  const x = L.text.align === 'center' ? px(L.text.x + L.text.width / 2) : px(L.text.x)
  const maxW = px(L.text.width)
  let y = px(L.text.y)
  const bottom = px(L.text.y + L.text.height)
  const draw = (text: string, mmSize: number, weight: string, family = 'system-ui, sans-serif', maxLines = 1) => {
    const size = px(mmSize)
    ctx.font = `${weight} ${size}px ${family}`
    for (const line of wrapText(text, maxW, maxLines, (s) => ctx.measureText(s).width)) {
      if (y + size > bottom) return
      ctx.fillText(line, x, y)
      y += Math.round(size * 1.2)
    }
  }
  if (style.headerText) draw(style.headerText, 2.6, '600')
  if (style.showName && data.name) draw(data.name, 3.6, '700', undefined, L.stacked ? 2 : 3)
  if (style.showSku && data.sku) draw(data.sku, 3, '500')
  if (style.showCode) draw(data.code.length === 11 ? `${data.code.slice(0, 10)}-${data.code.slice(10)}` : data.code, 3.2, '600', 'ui-monospace, SFMono-Regular, Menlo, monospace')

  return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Could not render the label'))), 'image/png'))
}

/** Prints labels through a hidden frame: one page per label sized exactly like the label (roll printers) or flowing in a grid (sheets). */
export async function printLabels(blobs: Blob[], style: Pick<LabelStyle, 'widthMm' | 'heightMm'>): Promise<void> {
  const urls = await Promise.all(
    blobs.map(
      (b) =>
        new Promise<string>((resolve) => {
          const r = new FileReader()
          r.onload = () => resolve(String(r.result))
          r.readAsDataURL(b)
        }),
    ),
  )
  const frame = document.createElement('iframe')
  frame.setAttribute('aria-hidden', 'true')
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
  document.body.appendChild(frame)
  const doc = frame.contentDocument!
  doc.open()
  doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>Etiquetas</title><style>
    @page { size: ${style.widthMm}mm ${style.heightMm}mm; margin: 0 }
    html, body { margin: 0; padding: 0 }
    img { display: block; width: ${style.widthMm}mm; height: ${style.heightMm}mm; page-break-after: always; break-after: page }
    img:last-child { page-break-after: auto; break-after: auto }
  </style></head><body>${urls.map((u) => `<img src="${u}" alt="">`).join('')}</body></html>`)
  doc.close()
  await new Promise((r) => setTimeout(r, 250)) // let the images decode before the dialog opens
  frame.contentWindow!.focus()
  frame.contentWindow!.print()
  setTimeout(() => frame.remove(), 60_000)
}

import { useApolloClient, useQuery } from '@apollo/client/react'
import { Download, FileSpreadsheet } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { download, toCsv } from '@/features/warehouse/lib'
import { ImportProductsDocument, ProductImportTemplateDocument, type ImportProductsMutation } from '@/graphql/generated'
import { UploadError, uploadFile } from '@/lib/upload'

import { PageHeader } from '../shared'

type Report = ImportProductsMutation['importProducts']

/** Many products from a spreadsheet: check it first (nothing is saved), fix what it says, then import. A file with any mistake saves nothing. */
export function ImportPage() {
  const client = useApolloClient()
  const input = useRef<HTMLInputElement>(null)
  const { data: template } = useQuery(ProductImportTemplateDocument)
  const [loading, setLoading] = useState(false)
  const [file, setFile] = useState<File | null>(null)
  const [report, setReport] = useState<Report | null>(null)
  const [checkedClean, setCheckedClean] = useState(false)

  const send = async (apply: boolean) => {
    if (!file) return
    setLoading(true)
    try {
      const data = await uploadFile(ImportProductsDocument, { file, apply, warehouseId: null })
      const r = data.importProducts
      setReport(r)
      setCheckedClean(!apply && r.errors.length === 0)
      if (apply && r.applied) {
        toast.success(`${r.products} productos importados`)
        await client.refetchQueries({ include: ['AdminProducts'] })
      }
    } catch (e) {
      toast.error(e instanceof UploadError ? e.message : 'No se pudo leer el archivo.')
    } finally {
      setLoading(false)
    }
  }

  const downloadTemplate = () => {
    const t = template?.productImportTemplate
    if (t) download('plantilla-productos.csv', toCsv([t.example], t.headers.map((h, i) => ({ header: h, value: (r: string[]) => r[i] }))), 'text/csv;charset=utf-8')
  }

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader title="Importar productos" description="Una fila por variante. Las filas con el mismo «Grupo» forman un producto. Una celda vacía queda como dato pendiente; escribe «n/a» si no aplica." />
      <Card>
        <CardHeader>
          <CardTitle className="text-base">1. Descarga la plantilla</CardTitle>
          <CardDescription>Trae todas las columnas y una fila de ejemplo. Puedes subirla como Excel (.xlsx) o CSV.</CardDescription>
        </CardHeader>
        <CardContent>
          <Button variant="outline" onClick={downloadTemplate} disabled={!template}>
            <Download className="size-4" /> Descargar plantilla
          </Button>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle className="text-base">2. Revisa tu archivo</CardTitle>
          <CardDescription>La revisión hace todo menos guardar: te dice cuántos productos se crearían y qué fila tiene un problema.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <input ref={input} type="file" accept=".xlsx,.csv" className="sr-only" id="import-file" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setReport(null); setCheckedClean(false) }} />
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="outline" onClick={() => input.current?.click()}>
              <FileSpreadsheet className="size-4" /> {file ? file.name : 'Elegir archivo…'}
            </Button>
            <Button disabled={!file || loading} onClick={() => void send(false)}>
              Revisar
            </Button>
            <Button disabled={!file || loading || !checkedClean} onClick={() => void send(true)} title={checkedClean ? undefined : 'Primero revisa el archivo sin errores'}>
              Importar
            </Button>
          </div>
          {report && (
            <div className="space-y-3" role="status" aria-live="polite">
              <p className="text-sm">
                {report.rows} filas · {report.products} productos · {report.variants} variantes{' '}
                {report.applied ? <strong className="text-success">importados</strong> : report.errors.length === 0 ? <strong>listos para importar</strong> : <strong className="text-destructive">con problemas: no se guardó nada</strong>}
              </p>
              {report.errors.length > 0 && (
                <ul className="space-y-1 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">
                  {report.errors.map((er, i) => (
                    <li key={i}>
                      {er.row !== null && <strong>Fila {er.row}: </strong>}
                      {er.message}
                    </li>
                  ))}
                </ul>
              )}
              {report.created.length > 0 && (
                <ul className="divide-y rounded-lg border text-sm">
                  {report.created.map((c) => (
                    <li key={c.productId} className="flex flex-wrap justify-between gap-2 px-3 py-2">
                      <span>{c.group}</span>
                      <span className="font-mono text-xs text-muted-foreground">{c.skus.join(', ')}</span>
                      {report.applied && (
                        <Link to={`/catalog/products/${c.productId}`} className="text-xs underline">
                          Abrir
                        </Link>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

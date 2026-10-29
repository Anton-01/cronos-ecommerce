import { useQuery } from '@apollo/client/react'
import * as React from 'react'

import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { SupportedLocalesDocument } from '@/graphql/generated'
import { cn } from '@/lib/utils'

export function useSupportedLocales() {
  const { data } = useQuery(SupportedLocalesDocument)
  const locales = data?.supportedLocales ?? []
  return { locales, defaultLocale: locales.find((l) => l.isDefault)?.code ?? locales[0]?.code ?? 'es-MX' }
}

export const NativeSelect = React.forwardRef<HTMLSelectElement, React.ComponentProps<'select'>>(({ className, ...props }, ref) => (
  <select ref={ref} className={cn('h-9 w-full rounded-md border bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50', className)} {...props} />
))
NativeSelect.displayName = 'NativeSelect'

/** One labelled input per language, stored as { locale: text }. */
export function LocaleInputs({
  label,
  locales,
  values,
  onChange,
  disabled,
  required,
}: {
  label: string
  locales: { code: string; isDefault: boolean }[]
  values: Record<string, string>
  onChange: (next: Record<string, string>) => void
  disabled?: boolean
  required?: boolean
}) {
  return (
    <div className="space-y-1.5">
      <Label>{label}</Label>
      {locales.map((l) => (
        <div key={l.code} className="flex items-center gap-2">
          <span className="w-14 shrink-0 text-xs text-muted-foreground">{l.code}</span>
          <Input
            aria-label={`${label} (${l.code})`}
            value={values[l.code] ?? ''}
            disabled={disabled}
            required={required && l.isDefault}
            maxLength={255}
            onChange={(e) => onChange({ ...values, [l.code]: e.target.value })}
          />
        </div>
      ))}
    </div>
  )
}

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: React.ReactNode }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {description && <p className="text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions}
    </div>
  )
}

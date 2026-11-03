'use client'

import Link from 'next/link'
import { useActionState } from 'react'

import { login, register, type FormState } from '@/app/actions/auth'
import { href, LOCALES, type Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'

export function AuthForm({ mode, locale, next }: { mode: 'login' | 'register'; locale: Locale; next?: string }) {
  const t = messages(locale)
  const [state, action, pending] = useActionState<FormState, FormData>(mode === 'login' ? login : register, {})
  const err = (f: string) => state.fields?.[f]?.[0]
  return (
    <div className="container-x mx-auto my-10 max-w-md">
      <form action={action} className="card space-y-4 p-6" noValidate>
        <h1 className="text-3xl">{mode === 'login' ? t.login : t.register}</h1>
        <input type="hidden" name="locale" value={LOCALES[locale].prefix} />
        {next && <input type="hidden" name="next" value={next} />}
        {mode === 'register' && (
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">{t.firstName}<input name="firstName" autoComplete="given-name" className="field mt-1" /></label>
            <label className="block text-sm">{t.lastName}<input name="lastName" autoComplete="family-name" className="field mt-1" /></label>
          </div>
        )}
        <label className="block text-sm">{t.email}
          <input name="email" type="email" required autoComplete="email" aria-invalid={Boolean(err('input.email') ?? err('email'))} className="field mt-1" />
          {(err('input.email') ?? err('email')) && <span role="alert" className="text-xs text-danger">{err('input.email') ?? err('email')}</span>}
        </label>
        <label className="block text-sm">{t.password}
          <input name="password" type="password" required autoComplete={mode === 'login' ? 'current-password' : 'new-password'} minLength={mode === 'register' ? 8 : undefined} className="field mt-1" />
          {(err('input.password') ?? err('password')) && <span role="alert" className="text-xs text-danger">{err('input.password') ?? err('password')}</span>}
        </label>
        {mode === 'register' && <label className="flex items-start gap-2 text-sm"><input type="checkbox" name="newsletter" className="mt-1" /> {t.registerNewsletter}</label>}
        {state.error && !state.fields?.['input.email'] && !state.fields?.['input.password'] && <p role="alert" className="text-sm text-danger">{state.error === 'invalid' ? (locale === 'es-MX' ? 'Correo o contraseña incorrectos.' : 'Wrong email or password.') : state.error === 'unexpected' ? t.error : state.error}</p>}
        <button className="btn btn-primary w-full" disabled={pending}>{mode === 'login' ? t.signIn : t.signUp}</button>
        <p className="text-center text-sm">
          {mode === 'login' ? t.noAccount : t.haveAccount}{' '}
          <Link className="underline" href={href.page(locale, mode === 'login' ? 'register' : 'login')}>{mode === 'login' ? t.signUp : t.signIn}</Link>
        </p>
      </form>
    </div>
  )
}

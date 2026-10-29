import { lazy, Suspense } from 'react'
import { Route, Routes } from 'react-router-dom'

import { Skeleton } from '@/components/ui/skeleton'

import { LoginPage } from '@/features/auth/LoginPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { AdminLayout } from '@/layouts/AdminLayout'

// Heavy screens load on demand so the login page and dashboard stay small.
const UsersPage = lazy(() => import('@/features/users/UsersPage').then((m) => ({ default: m.UsersPage })))
const MediaPage = lazy(() => import('@/features/media/MediaPage').then((m) => ({ default: m.MediaPage })))
const SettingsPage = lazy(() => import('@/features/settings/SettingsPage').then((m) => ({ default: m.SettingsPage })))
const ProfilePage = lazy(() => import('@/features/profile/ProfilePage').then((m) => ({ default: m.ProfilePage })))
const ProductsPage = lazy(() => import('@/features/catalog/products/ProductsPage').then((m) => ({ default: m.ProductsPage })))
const CreateProductPage = lazy(() => import('@/features/catalog/products/CreateProductPage').then((m) => ({ default: m.CreateProductPage })))
const ProductEditor = lazy(() => import('@/features/catalog/products/ProductEditor').then((m) => ({ default: m.ProductEditor })))
const CategoriesPage = lazy(() => import('@/features/catalog/categories/CategoriesPage').then((m) => ({ default: m.CategoriesPage })))
const TagsPage = lazy(() => import('@/features/catalog/tags/TagsPage').then((m) => ({ default: m.TagsPage })))
const AttributesPage = lazy(() => import('@/features/catalog/attributes/AttributesPage').then((m) => ({ default: m.AttributesPage })))
const RolesPage = lazy(() => import('@/features/roles/RolesPage').then((m) => ({ default: m.RolesPage })))

const page = (node: React.ReactNode) => <Suspense fallback={<Skeleton className="h-64 w-full" />}>{node}</Suspense>

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<RequireAuth />}>
        <Route element={<AdminLayout />}>
          <Route index element={<DashboardPage />} />
          <Route element={<RequireAuth permission="users.view" />}>
            <Route path="users" element={page(<UsersPage />)} />
          </Route>
          <Route element={<RequireAuth permission="media.view" />}>
            <Route path="media" element={page(<MediaPage />)} />
          </Route>
          <Route element={<RequireAuth permission="settings.view" />}>
            <Route path="settings" element={page(<SettingsPage />)} />
          </Route>
          <Route element={<RequireAuth permission="catalog.view" />}>
            <Route path="catalog/products" element={page(<ProductsPage />)} />
            <Route path="catalog/products/new" element={page(<CreateProductPage />)} />
            <Route path="catalog/products/:id" element={page(<ProductEditor />)} />
            <Route path="catalog/categories" element={page(<CategoriesPage />)} />
            <Route path="catalog/tags" element={page(<TagsPage />)} />
            <Route path="catalog/attributes" element={page(<AttributesPage />)} />
          </Route>
          <Route path="profile" element={page(<ProfilePage />)} />
          <Route element={<RequireAuth permission="roles.view" />}>
            <Route path="roles" element={page(<RolesPage />)} />
          </Route>
          <Route path="*" element={<p className="text-sm text-muted-foreground">Página no encontrada.</p>} />
        </Route>
      </Route>
    </Routes>
  )
}

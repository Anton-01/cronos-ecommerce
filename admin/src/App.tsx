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
const CurrenciesPage = lazy(() => import('@/features/pricing/CurrenciesPage').then((m) => ({ default: m.CurrenciesPage })))
const TaxesPage = lazy(() => import('@/features/pricing/TaxesPage').then((m) => ({ default: m.TaxesPage })))
const CouponsPage = lazy(() => import('@/features/promotions/CouponsPage').then((m) => ({ default: m.CouponsPage })))
const PromotionsPage = lazy(() => import('@/features/promotions/PromotionsPage').then((m) => ({ default: m.PromotionsPage })))
const FraudPage = lazy(() => import('@/features/promotions/FraudPage').then((m) => ({ default: m.FraudPage })))
const AuditPage = lazy(() => import('@/features/audit/AuditPage').then((m) => ({ default: m.AuditPage })))
const PaymentsPage = lazy(() => import('@/features/payments/PaymentsPage').then((m) => ({ default: m.PaymentsPage })))
const OrdersPage = lazy(() => import('@/features/orders/OrdersPage').then((m) => ({ default: m.OrdersPage })))
const CustomOrdersPage = lazy(() => import('@/features/orders/CustomOrdersPage').then((m) => ({ default: m.CustomOrdersPage })))
const InventoryPage = lazy(() => import('@/features/inventory/InventoryPage').then((m) => ({ default: m.InventoryPage })))
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
          <Route element={<RequireAuth permission="inventory.view" />}>
            <Route path="inventory" element={page(<InventoryPage />)} />
          </Route>
          <Route element={<RequireAuth permission="orders.view" />}>
            <Route path="orders" element={page(<OrdersPage />)} />
          </Route>
          <Route element={<RequireAuth permission="custom-orders.view" />}>
            <Route path="custom-orders" element={page(<CustomOrdersPage />)} />
          </Route>
          <Route element={<RequireAuth permission="payments.view" />}>
            <Route path="payments" element={page(<PaymentsPage />)} />
          </Route>
          <Route element={<RequireAuth permission="pricing.view" />}>
            <Route path="finance/currencies" element={page(<CurrenciesPage />)} />
            <Route path="finance/taxes" element={page(<TaxesPage />)} />
          </Route>
          <Route element={<RequireAuth permission="promotions.view" />}>
            <Route path="finance/coupons" element={page(<CouponsPage />)} />
            <Route path="finance/promotions" element={page(<PromotionsPage />)} />
          </Route>
          <Route element={<RequireAuth permission="fraud.view" />}>
            <Route path="finance/fraud" element={page(<FraudPage />)} />
          </Route>
          <Route element={<RequireAuth permission="audits.view" />}>
            <Route path="audit" element={page(<AuditPage />)} />
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

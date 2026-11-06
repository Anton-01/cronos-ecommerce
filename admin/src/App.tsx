import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useParams } from 'react-router-dom'

import { Skeleton } from '@/components/ui/skeleton'

import { LoginPage } from '@/features/auth/LoginPage'
import { RequireAuth } from '@/features/auth/RequireAuth'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { useAuth } from '@/features/auth/AuthProvider'
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
const NewsletterPage = lazy(() => import('@/features/newsletter/NewsletterPage').then((m) => ({ default: m.NewsletterPage })))
const MessagesPage = lazy(() => import('@/features/contact/MessagesPage').then((m) => ({ default: m.MessagesPage })))
const PagesPage = lazy(() => import('@/features/content/PagesPage').then((m) => ({ default: m.PagesPage })))
const AppearancePage = lazy(() => import('@/features/appearance/AppearancePage').then((m) => ({ default: m.AppearancePage })))
const TaxesPage = lazy(() => import('@/features/pricing/TaxesPage').then((m) => ({ default: m.TaxesPage })))
const CouponsPage = lazy(() => import('@/features/promotions/CouponsPage').then((m) => ({ default: m.CouponsPage })))
const PromotionsPage = lazy(() => import('@/features/promotions/PromotionsPage').then((m) => ({ default: m.PromotionsPage })))
const FraudPage = lazy(() => import('@/features/promotions/FraudPage').then((m) => ({ default: m.FraudPage })))
const AuditPage = lazy(() => import('@/features/audit/AuditPage').then((m) => ({ default: m.AuditPage })))
const PaymentsPage = lazy(() => import('@/features/payments/PaymentsPage').then((m) => ({ default: m.PaymentsPage })))
const OrdersPage = lazy(() => import('@/features/orders/OrdersPage').then((m) => ({ default: m.OrdersPage })))
const CustomOrdersPage = lazy(() => import('@/features/orders/CustomOrdersPage').then((m) => ({ default: m.CustomOrdersPage })))
const MarketsPage = lazy(() => import('@/features/markets/MarketsPage').then((m) => ({ default: m.MarketsPage })))
const InventoryPage = lazy(() => import('@/features/inventory/InventoryPage').then((m) => ({ default: m.InventoryPage })))
const OptionsPage = lazy(() => import('@/features/catalog/data/OptionsPage').then((m) => ({ default: m.OptionsPage })))
const TypesPage = lazy(() => import('@/features/catalog/data/TypesPage').then((m) => ({ default: m.TypesPage })))
const QuickCreatePage = lazy(() => import('@/features/catalog/data/QuickCreatePage').then((m) => ({ default: m.QuickCreatePage })))
const ImportPage = lazy(() => import('@/features/catalog/data/ImportPage').then((m) => ({ default: m.ImportPage })))
const MissingDataPage = lazy(() => import('@/features/catalog/data/MissingDataPage').then((m) => ({ default: m.MissingDataPage })))
const RolesPage = lazy(() => import('@/features/roles/RolesPage').then((m) => ({ default: m.RolesPage })))

const WarehouseShell = lazy(() => import('@/features/warehouse/WarehouseShell').then((m) => ({ default: m.WarehouseShell })))
const MorePage = lazy(() => import('@/features/warehouse/WarehouseShell').then((m) => ({ default: m.MorePage })))
const ScanPage = lazy(() => import('@/features/warehouse/ScanPage').then((m) => ({ default: m.ScanPage })))
const ItemPage = lazy(() => import('@/features/warehouse/ItemPage').then((m) => ({ default: m.ItemPage })))
const ShelfPage = lazy(() => import('@/features/warehouse/ShelfPage').then((m) => ({ default: m.ShelfPage })))
const PickPage = lazy(() => import('@/features/warehouse/PickPage').then((m) => ({ default: m.PickPage })))
const CountPage = lazy(() => import('@/features/warehouse/CountPage').then((m) => ({ default: m.CountPage })))
const AlertsPage = lazy(() => import('@/features/warehouse/AlertsPage').then((m) => ({ default: m.AlertsPage })))
const LocationsPage = lazy(() => import('@/features/warehouse/LocationsPage').then((m) => ({ default: m.LocationsPage })))
const ApprovalsPage = lazy(() => import('@/features/warehouse/ApprovalsPage').then((m) => ({ default: m.ApprovalsPage })))
const TrailPage = lazy(() => import('@/features/warehouse/TrailPage').then((m) => ({ default: m.TrailPage })))
const RulesPage = lazy(() => import('@/features/warehouse/RulesPage').then((m) => ({ default: m.RulesPage })))
const LabelsPage = lazy(() => import('@/features/warehouse/LabelsPage').then((m) => ({ default: m.LabelsPage })))

/** The warehouse team has no dashboard of sales: their home is the warehouse. */
function Home() {
  const { can } = useAuth()
  return can('warehouse.view') && !can('reports.view') && !can('inventory.view') ? <Navigate to="/warehouse" replace /> : <DashboardPage />
}

/** A label's QR opens here: `/w/<code>` for a product, `/w/loc/<code>` for a shelf. */
function ScanRedirect({ shelf }: { shelf?: boolean }) {
  const { code = '' } = useParams()
  return <Navigate to={`/warehouse/${shelf ? 'shelf' : 'item'}/${encodeURIComponent(code)}`} replace />
}

const page = (node: React.ReactNode) => <Suspense fallback={<Skeleton className="h-64 w-full" />}>{node}</Suspense>

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<RequireAuth />}>
        <Route element={<AdminLayout />}>
          <Route index element={<Home />} />
          <Route element={<RequireAuth permission="warehouse.view" />}>
            <Route path="w/loc/:code" element={<ScanRedirect shelf />} />
            <Route path="w/:code" element={<ScanRedirect />} />
            <Route path="warehouse" element={page(<WarehouseShell />)}>
              <Route index element={page(<ScanPage />)} />
              <Route path="more" element={page(<MorePage />)} />
              <Route path="item/:code" element={page(<ItemPage />)} />
              <Route path="shelf/:code" element={page(<ShelfPage />)} />
              <Route path="pick" element={page(<PickPage />)} />
              <Route path="count" element={page(<CountPage />)} />
              <Route path="count/:id" element={page(<CountPage />)} />
              <Route path="alerts" element={page(<AlertsPage />)} />
              <Route path="locations" element={page(<LocationsPage />)} />
              <Route path="labels" element={page(<LabelsPage />)} />
              <Route element={<RequireAuth permission="warehouse.approve" />}>
                <Route path="approvals" element={page(<ApprovalsPage />)} />
                <Route path="rules" element={page(<RulesPage />)} />
              </Route>
              <Route element={<RequireAuth permission="warehouse.audit" />}>
                <Route path="trail" element={page(<TrailPage />)} />
              </Route>
            </Route>
          </Route>
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
            <Route path="catalog/lists" element={page(<OptionsPage />)} />
            <Route path="catalog/types" element={page(<TypesPage />)} />
            <Route path="catalog/missing" element={page(<MissingDataPage />)} />
            <Route path="catalog/products/:id" element={page(<ProductEditor />)} />
            <Route path="catalog/categories" element={page(<CategoriesPage />)} />
            <Route path="catalog/tags" element={page(<TagsPage />)} />
            <Route path="catalog/attributes" element={page(<AttributesPage />)} />
          </Route>
          <Route element={<RequireAuth permission="catalog.manage" />}>
            <Route path="catalog/products/quick" element={page(<QuickCreatePage />)} />
            <Route path="catalog/import" element={page(<ImportPage />)} />
          </Route>
          <Route element={<RequireAuth permission="inventory.view" />}>
            <Route path="inventory" element={page(<InventoryPage />)} />
          </Route>
          <Route element={<RequireAuth permission="markets.view" />}>
            <Route path="markets" element={page(<MarketsPage />)} />
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
          <Route element={<RequireAuth permission="newsletter.view" />}>
            <Route path="newsletter" element={page(<NewsletterPage />)} />
          </Route>
          <Route element={<RequireAuth permission="contact.view" />}>
            <Route path="messages" element={page(<MessagesPage />)} />
          </Route>
          <Route element={<RequireAuth permission="content.view" />}>
            <Route path="content" element={page(<PagesPage />)} />
          </Route>
          <Route element={<RequireAuth permission="appearance.view" />}>
            <Route path="appearance" element={page(<AppearancePage />)} />
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

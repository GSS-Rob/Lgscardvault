import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './context/AuthContext'
import AppLayout from './components/layout/AppLayout'
import AuthLayout from './components/layout/AuthLayout'
import AdminLayout from './components/layout/AdminLayout'
import ProtectedRoute from './components/ProtectedRoute'
import HomePage from './pages/HomePage'
import AccountPage from './pages/AccountPage'
import LoginPage from './pages/LoginPage'
import RegisterPage from './pages/RegisterPage'
import OwnerOnboardingWizard from './pages/OwnerOnboardingWizard'
import SsoCallbackPage from './pages/SsoCallbackPage'
import StorePage from './pages/StorePage'
import MassSearchPage from './pages/MassSearchPage'
import CommanderSynergyPage from './pages/CommanderSynergyPage'
import CartPage from './pages/CartPage'
import CardDetailsPage from './pages/CardDetailsPage'
import CaseCardsPage from './pages/CaseCardsPage'
import CustomerProfilePage from './pages/CustomerProfilePage'
import StoreAdminPage from './pages/StoreAdminPage'
import ImportRunDetailsPage from './pages/store-admin/ImportRunDetailsPage'
import PlatformAdminPage from './pages/PlatformAdminPage'
import SyncJobsPage from './pages/platform-admin/SyncJobsPage'
import PatchNotesTab from './pages/store-admin/PatchNotesTab'
import SellTradePage from './pages/SellTradePage'
import PlatformStoreImportsPage from './pages/PlatformStoreImportsPage'

const queryClient = new QueryClient()

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            {/* Full-screen auth flow (no app navbar) */}
            <Route element={<AuthLayout />}>
              <Route path="login" element={<LoginPage />} />
              <Route path="register" element={<Navigate to="/register/customer" replace />} />
              <Route path="register/owner" element={<OwnerOnboardingWizard />} />
              <Route path="register/customer" element={<RegisterPage accountType="customer" />} />
              <Route path="auth/sso/callback" element={<SsoCallbackPage />} />
            </Route>

            <Route element={<AppLayout />}>
              <Route index element={<HomePage />} />
              {/* Global identity settings + "your stores" — one account across the marketplace */}
              <Route
                path="account"
                element={
                  <ProtectedRoute>
                    <AccountPage />
                  </ProtectedRoute>
                }
              />
              <Route path="s/:slug" element={<StorePage />} />
              <Route path="s/:slug/mass-search" element={<MassSearchPage />} />
              <Route path="s/:slug/deck-builder" element={<CommanderSynergyPage />} />
              <Route path="s/:slug/sell" element={<SellTradePage />} />
              <Route path="s/:slug/cart" element={<CartPage />} />
              <Route path="s/:slug/cards/:id" element={<CardDetailsPage />} />
              <Route path="s/:slug/case-cards" element={<CaseCardsPage />} />
              <Route
                path="s/:slug/account"
                element={
                  <ProtectedRoute>
                    <CustomerProfilePage />
                  </ProtectedRoute>
                }
              />
            </Route>
            {/* Store admin — slug lives on the layout route so the sidebar can build section links */}
            <Route
              path="s/:slug/admin"
              element={
                <ProtectedRoute requireStoreOwner>
                  <AdminLayout />
                </ProtectedRoute>
              }
            >
              <Route index element={<StoreAdminPage />} />
              <Route path="imports/:importId" element={<ImportRunDetailsPage />} />
              <Route path=":section" element={<StoreAdminPage />} />
            </Route>
            {/* Platform admin */}
            <Route
              path="platform/admin"
              element={
                <ProtectedRoute requireSuperAdmin>
                  <AdminLayout />
                </ProtectedRoute>
              }
            >
              <Route index element={<PlatformAdminPage />} />
              <Route path="patch-notes" element={<PatchNotesTab />} />
              <Route path="sync-jobs" element={<SyncJobsPage />} />
              <Route path="stores/:slug/imports" element={<PlatformStoreImportsPage />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}

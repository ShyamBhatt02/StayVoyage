import { Suspense, useEffect, useState, type ReactNode } from 'react';
import { Navigate, NavLink, Outlet, ScrollRestoration, useLocation, useMatches } from 'react-router-dom';
import {
  BadgePercent, BarChart3, BedDouble, Bell, Building2, CalendarCheck, ClipboardList, Compass, Crown, CreditCard, FileClock, Hotel, LayoutDashboard,
  ListChecks, LogOut, Map, Menu, MessageSquareText, Package, Settings, Shield, Star, Ticket, UserCog, Users, Heart, X, Wallet,
} from 'lucide-react';
import { Header } from './Header';
import { Footer } from './Footer';
import { Logo } from '@/components/brand/Logo';
import { Avatar, Spinner } from '@/components/ui/misc';
import { useAuth } from '@/providers/AuthProvider';
import { cn } from '@/lib/format';

function PageFallback() {
  return <Spinner className="min-h-[50vh]" />;
}

export function PublicLayout() {
  const matches = useMatches();
  const transparent = matches.some((m) => (m.handle as { transparentHeader?: boolean } | undefined)?.transparentHeader);
  return (
    <div className="flex min-h-screen flex-col">
      <Header transparent={transparent} />
      <main id="main" className="flex-1">
        <Suspense fallback={<PageFallback />}><Outlet /></Suspense>
      </main>
      <Footer />
      <ScrollRestoration />
    </div>
  );
}

// ------------------------------------------------------------------ guards
export function RequireAuth({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const location = useLocation();
  if (loading) return <PageFallback />;
  if (!user) return <Navigate to={`/login?redirect=${encodeURIComponent(location.pathname + location.search)}`} replace />;
  return <>{children}</>;
}

export function RequireStaff({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const location = useLocation();
  if (loading) return <PageFallback />;
  if (!user) return <Navigate to={`/login?redirect=${encodeURIComponent(location.pathname)}`} replace />;
  if (!user.is_staff) return <Navigate to="/account" replace />;
  return <>{children}</>;
}

export function RequirePartner({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  if (loading) return <PageFallback />;
  if (!user) return <Navigate to="/login?redirect=/partner" replace />;
  if (!user.is_partner) return <Navigate to="/account" replace />;
  return <>{children}</>;
}

export function GuestOnly({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  if (loading) return <PageFallback />;
  if (user) return <Navigate to={user.is_staff ? '/admin' : '/account'} replace />;
  return <>{children}</>;
}

// ------------------------------------------------------------------ account
const ACCOUNT_NAV = [
  { to: '/account', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/account/bookings', label: 'My bookings', icon: CalendarCheck },
  { to: '/account/membership', label: 'Membership', icon: Crown },
  { to: '/account/trips', label: 'Saved trips', icon: Map },
  { to: '/account/saved', label: 'Wishlist', icon: Heart },
  { to: '/account/payments', label: 'Payments', icon: Wallet },
  { to: '/account/notifications', label: 'Notifications', icon: Bell },
  { to: '/account/profile', label: 'Profile & security', icon: Settings },
];

export function AccountLayout() {
  const { user } = useAuth();
  return (
    <RequireAuth>
      <div className="container-page grid gap-8 py-8 lg:grid-cols-[250px_1fr]">
        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="card mb-4 hidden items-center gap-3 p-4 lg:flex">
            <Avatar name={user?.name} src={user?.avatar_url} />
            <div className="min-w-0">
              <p className="truncate font-semibold text-ink-900">{user?.name}</p>
              <p className="truncate text-xs text-ink-500">{user?.email}</p>
            </div>
          </div>
          <nav aria-label="Account" className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1 scrollbar-none lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0">
            {ACCOUNT_NAV.map((n) => (
              <NavLink key={n.to} to={n.to} end={n.end}
                className={({ isActive }) => cn('flex shrink-0 items-center gap-2.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold transition',
                  isActive ? 'bg-brand-600 text-white shadow-sm' : 'bg-white text-ink-700 hover:bg-brand-50 hover:text-brand-700 lg:bg-transparent')}>
                <n.icon className="h-4 w-4" />{n.label}
              </NavLink>
            ))}
          </nav>
        </aside>
        <div className="min-w-0">
          <Suspense fallback={<PageFallback />}><Outlet /></Suspense>
        </div>
      </div>
    </RequireAuth>
  );
}

// ------------------------------------------------------------------ admin / partner shell
interface NavItem { to: string; label: string; icon: typeof Hotel; perm?: string; end?: boolean }

const ADMIN_NAV: { section: string; items: NavItem[] }[] = [
  { section: 'Overview', items: [{ to: '/admin', label: 'Dashboard', icon: LayoutDashboard, perm: 'dashboard.view', end: true }, { to: '/admin/reports', label: 'Reports', icon: BarChart3, perm: 'reports.view' }] },
  { section: 'Sales', items: [
    { to: '/admin/bookings', label: 'Bookings', icon: CalendarCheck, perm: 'bookings.view' },
    { to: '/admin/payments', label: 'Payments & refunds', icon: CreditCard, perm: 'payments.view' },
    { to: '/admin/memberships', label: 'Members & wallets', icon: Crown, perm: 'memberships.view' },
    { to: '/admin/membership-plans', label: 'Membership plans', icon: Ticket, perm: 'memberships.view' },
  ] },
  { section: 'Inventory', items: [
    { to: '/admin/hotels', label: 'Hotels & rooms', icon: Hotel, perm: 'hotels.view' },
    { to: '/admin/destinations', label: 'Destinations', icon: Compass, perm: 'destinations.manage' },
    { to: '/admin/tour-packages', label: 'Tour packages', icon: Package, perm: 'tours.manage' },
    { to: '/admin/catalogue', label: 'Tour catalogue', icon: ListChecks, perm: 'tours.manage' },
  ] },
  { section: 'Customers', items: [
    { to: '/admin/users', label: 'Users', icon: Users, perm: 'users.view' },
    { to: '/admin/reviews', label: 'Reviews', icon: Star, perm: 'reviews.moderate' },
    { to: '/admin/coupons', label: 'Coupons', icon: BadgePercent, perm: 'coupons.manage' },
    { to: '/admin/content', label: 'FAQs & messages', icon: MessageSquareText, perm: 'content.manage' },
  ] },
  { section: 'System', items: [
    { to: '/admin/roles', label: 'Roles & staff', icon: UserCog, perm: 'roles.manage' },
    { to: '/admin/settings', label: 'Settings', icon: Settings, perm: 'settings.manage' },
    { to: '/admin/audit-logs', label: 'Audit logs', icon: FileClock, perm: 'audit.view' },
  ] },
];

const PARTNER_NAV: { section: string; items: NavItem[] }[] = [
  { section: 'My property', items: [
    { to: '/partner', label: 'Overview', icon: LayoutDashboard, end: true },
    { to: '/partner/bookings', label: 'Bookings', icon: ClipboardList },
  ] },
];

function Shell({ nav, title, badge }: { nav: { section: string; items: NavItem[] }[]; title: string; badge: ReactNode }) {
  const { user, can, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const location = useLocation();
  useEffect(() => setOpen(false), [location.pathname]);

  const sidebar = (
    <div className="flex h-full flex-col">
      <div className="flex h-16 items-center justify-between px-5"><Logo to={nav === PARTNER_NAV ? '/partner' : '/admin'} /></div>
      <div className="px-5 pb-3">{badge}</div>
      <nav aria-label={title} className="flex-1 space-y-5 overflow-y-auto px-3 pb-6">
        {nav.map((s) => {
          const items = s.items.filter((i) => !i.perm || can(i.perm));
          if (!items.length) return null;
          return (
            <div key={s.section}>
              <p className="px-3 pb-1.5 text-[11px] font-bold uppercase tracking-widest text-ink-400">{s.section}</p>
              {items.map((i) => (
                <NavLink key={i.to} to={i.to} end={i.end}
                  className={({ isActive }) => cn('flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold transition', isActive ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900')}>
                  <i.icon className="h-4 w-4" />{i.label}
                </NavLink>
              ))}
            </div>
          );
        })}
      </nav>
      <div className="border-t border-ink-100 p-3">
        <NavLink to="/" className="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 hover:bg-ink-100"><BedDouble className="h-4 w-4" /> View website</NavLink>
        <button onClick={() => logout().then(() => (window.location.href = '/'))} className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-danger-600 hover:bg-danger-50"><LogOut className="h-4 w-4" /> Log out</button>
      </div>
    </div>
  );

  return (
    <div className="min-h-screen bg-ink-50">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 border-r border-ink-200 bg-white lg:block">{sidebar}</aside>
      {open && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div className="absolute inset-0 bg-ink-950/40" onClick={() => setOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-72 bg-white shadow-lift animate-[fade-up_.2s]">
            <button onClick={() => setOpen(false)} className="absolute right-3 top-4 rounded-lg p-1.5 hover:bg-ink-100" aria-label="Close menu"><X className="h-5 w-5" /></button>
            {sidebar}
          </aside>
        </div>
      )}
      <div className="lg:pl-64">
        <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-ink-200 bg-white/90 px-4 backdrop-blur sm:px-6">
          <button onClick={() => setOpen(true)} className="rounded-lg p-2 hover:bg-ink-100 lg:hidden" aria-label="Open menu"><Menu className="h-5 w-5" /></button>
          <p className="font-display text-lg font-semibold text-ink-900">{title}</p>
          <div className="ml-auto flex items-center gap-3">
            <div className="hidden text-right sm:block">
              <p className="text-sm font-semibold text-ink-800">{user?.name}</p>
              <p className="text-xs text-ink-500">{user?.email}</p>
            </div>
            <Avatar name={user?.name} src={user?.avatar_url} size="h-9 w-9" />
          </div>
        </header>
        <main id="main" className="p-4 sm:p-6 lg:p-8">
          <Suspense fallback={<PageFallback />}><Outlet /></Suspense>
        </main>
      </div>
      <ScrollRestoration />
    </div>
  );
}

export function AdminLayout() {
  const { user } = useAuth();
  return (
    <RequireStaff>
      <Shell nav={ADMIN_NAV} title="Admin" badge={<span className="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-bold text-brand-700"><Shield className="h-3.5 w-3.5" /> {user?.roles?.includes('super-admin') ? 'Super admin' : 'Staff'}</span>} />
    </RequireStaff>
  );
}

export function PartnerLayout() {
  return (
    <RequirePartner>
      <Shell nav={PARTNER_NAV} title="Partner portal" badge={<span className="inline-flex items-center gap-1.5 rounded-full bg-sand-50 px-2.5 py-1 text-xs font-bold text-sand-800"><Building2 className="h-3.5 w-3.5" /> Hotel partner</span>} />
    </RequirePartner>
  );
}

import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Bell, ChevronDown, Crown, LayoutDashboard, LogOut, Menu, Settings, ShieldCheck, Building2, X } from 'lucide-react';
import { toast } from 'sonner';
import { Logo } from '@/components/brand/Logo';
import { Avatar } from '@/components/ui/misc';
import { ButtonLink } from '@/components/ui/Button';
import { useAuth } from '@/providers/AuthProvider';
import { get } from '@/lib/api';
import { cn } from '@/lib/format';

const NAV = [
  { to: '/hotels', label: 'Hotels' },
  { to: '/tours', label: 'Tours' },
  { to: '/tour-builder', label: 'Tour Builder' },
  { to: '/membership', label: 'Membership' },
  { to: '/destinations', label: 'Destinations' },
  { to: '/offers', label: 'Offers' },
  { to: '/faq', label: 'Help' },
];

export function Header({ transparent = false }: { transparent?: boolean }) {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const [menu, setMenu] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const menuRef = useRef<HTMLDivElement>(null);

  const unread = useQuery({
    queryKey: ['notifications', 'unread-count'],
    queryFn: () => get<{ count: number }>('/notifications/unread-count'),
    enabled: !!user,
    refetchInterval: 60_000,
  });

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 12);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  useEffect(() => { setOpen(false); setMenu(false); }, [location.pathname]);
  useEffect(() => {
    const onClick = (e: MouseEvent) => { if (menuRef.current && !menuRef.current.contains(e.target as Node)) setMenu(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const solid = !transparent || scrolled || open;
  const onLogout = async () => {
    await logout();
    toast.success('You have been logged out.');
    navigate('/');
  };

  return (
    <header className={cn('sticky top-0 z-40 transition-all duration-300', solid ? 'border-b border-ink-200/70 bg-white/90 backdrop-blur-xl' : 'bg-transparent', transparent && !solid && '-mb-[72px]')}>
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow">Skip to content</a>
      <div className="container-page flex h-[72px] items-center gap-4">
        <Logo light={!solid} />
        <nav aria-label="Main" className="ml-6 hidden items-center gap-1 xl:flex">
          {NAV.map((n) => (
            <NavLink key={n.to} to={n.to}
              className={({ isActive }) => cn('rounded-lg px-3 py-2 text-sm font-semibold transition',
                solid ? (isActive ? 'bg-brand-50 text-brand-700' : 'text-ink-700 hover:bg-ink-100') : (isActive ? 'bg-white/20 text-white' : 'text-white/90 hover:bg-white/15'))}>
              {n.label}
            </NavLink>
          ))}
        </nav>
        <div className="ml-auto flex items-center gap-2">
          {user ? (
            <>
              <Link to="/account/notifications" className={cn('relative rounded-xl p-2.5 transition', solid ? 'text-ink-600 hover:bg-ink-100' : 'text-white hover:bg-white/15')} aria-label={`Notifications${unread.data?.count ? `, ${unread.data.count} unread` : ''}`}>
                <Bell className="h-5 w-5" />
                {!!unread.data?.count && <span className="absolute right-1.5 top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-coral-500 px-1 text-[10px] font-bold text-white">{unread.data.count > 9 ? '9+' : unread.data.count}</span>}
              </Link>
              <div className="relative" ref={menuRef}>
                <button onClick={() => setMenu((m) => !m)} aria-expanded={menu} aria-haspopup="menu"
                  className={cn('flex items-center gap-2 rounded-xl py-1.5 pl-1.5 pr-2.5 transition', solid ? 'hover:bg-ink-100' : 'hover:bg-white/15')}>
                  <Avatar name={user.name} src={user.avatar_url} size="h-8 w-8" />
                  <span className={cn('hidden max-w-[120px] truncate text-sm font-semibold sm:block', solid ? 'text-ink-800' : 'text-white')}>{user.name.split(' ')[0]}</span>
                  <ChevronDown className={cn('h-4 w-4', solid ? 'text-ink-500' : 'text-white')} />
                </button>
                {menu && (
                  <div role="menu" className="absolute right-0 mt-2 w-60 overflow-hidden rounded-2xl border border-ink-200 bg-white p-1.5 shadow-lift animate-scale-in">
                    <div className="border-b border-ink-100 px-3 py-2.5">
                      <p className="truncate text-sm font-semibold text-ink-900">{user.name}</p>
                      <p className="truncate text-xs text-ink-500">{user.email}</p>
                    </div>
                    <MenuItem to="/account" icon={<LayoutDashboard className="h-4 w-4" />}>My dashboard</MenuItem>
                    <MenuItem to="/account/membership" icon={<Crown className="h-4 w-4" />}>Membership wallet</MenuItem>
                    <MenuItem to="/account/profile" icon={<Settings className="h-4 w-4" />}>Profile & security</MenuItem>
                    {user.is_staff && <MenuItem to="/admin" icon={<ShieldCheck className="h-4 w-4" />}>Admin panel</MenuItem>}
                    {user.is_partner && <MenuItem to="/partner" icon={<Building2 className="h-4 w-4" />}>Partner portal</MenuItem>}
                    <button role="menuitem" onClick={onLogout} className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-medium text-danger-600 hover:bg-danger-50">
                      <LogOut className="h-4 w-4" /> Log out
                    </button>
                  </div>
                )}
              </div>
            </>
          ) : (
            <div className="hidden items-center gap-2 sm:flex">
              <ButtonLink to="/login" variant={solid ? 'ghost' : 'white'} size="sm" className={!solid ? 'bg-white/15 text-white hover:bg-white/25 shadow-none' : ''}>Log in</ButtonLink>
              <ButtonLink to="/register" variant="accent" size="sm">Register</ButtonLink>
            </div>
          )}
          <button onClick={() => setOpen((o) => !o)} className={cn('rounded-xl p-2.5 xl:hidden', solid ? 'text-ink-700 hover:bg-ink-100' : 'text-white hover:bg-white/15')} aria-label={open ? 'Close menu' : 'Open menu'} aria-expanded={open}>
            {open ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
          </button>
        </div>
      </div>

      {open && (
        <nav aria-label="Mobile" className="border-t border-ink-100 bg-white xl:hidden">
          <div className="container-page grid gap-1 py-4">
            {NAV.map((n) => (
              <NavLink key={n.to} to={n.to} className={({ isActive }) => cn('rounded-xl px-4 py-3 text-base font-semibold', isActive ? 'bg-brand-50 text-brand-700' : 'text-ink-800 hover:bg-ink-50')}>
                {n.label}
              </NavLink>
            ))}
            {!user && (
              <div className="mt-3 grid grid-cols-2 gap-3">
                <ButtonLink to="/login" variant="outline">Log in</ButtonLink>
                <ButtonLink to="/register" variant="accent">Register</ButtonLink>
              </div>
            )}
          </div>
        </nav>
      )}
    </header>
  );
}

function MenuItem({ to, icon, children }: { to: string; icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <Link role="menuitem" to={to} className="flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-medium text-ink-700 hover:bg-ink-50 hover:text-brand-700">
      {icon}{children}
    </Link>
  );
}

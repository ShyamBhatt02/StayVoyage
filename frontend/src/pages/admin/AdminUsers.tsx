import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { getPage } from '@/lib/api';
import { fmtDate } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Avatar, Badge, StatusBadge } from '@/components/ui/misc';
import { AdminHeader, DataTable, Toolbar } from '@/components/admin/kit';
import type { User } from '@/types';

export default function AdminUsers() {
  usePageMeta('Users — Admin');
  const navigate = useNavigate();
  const [f, setF] = useState({ q: '', role: '', status: '', member: '' });
  const [page, setPage] = useState(1);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['admin', 'users', f, page], queryFn: () => getPage<User>('/admin/users', { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page }), placeholderData: keepPreviousData });
  const set = (k: keyof typeof f, v: string) => { setF((s) => ({ ...s, [k]: v })); setPage(1); };
  return (
    <div>
      <AdminHeader title="Users" description="Customers, staff and hotel partners." />
      <Toolbar>
        <input className="input w-64" placeholder="Name, email or phone" value={f.q} onChange={(e) => set('q', e.target.value)} aria-label="Search users" />
        <select className="input w-auto" value={f.role} onChange={(e) => set('role', e.target.value)} aria-label="Role"><option value="">All roles</option><option value="customer">Customers</option><option value="admin">Staff</option><option value="super-admin">Super admins</option><option value="hotel-partner">Hotel partners</option></select>
        <select className="input w-auto" value={f.status} onChange={(e) => set('status', e.target.value)} aria-label="Status"><option value="">Any status</option><option value="active">Active</option><option value="suspended">Suspended</option></select>
        <select className="input w-auto" value={f.member} onChange={(e) => set('member', e.target.value)} aria-label="Membership"><option value="">Members & non-members</option><option value="1">Active members only</option></select>
      </Toolbar>
      <DataTable rows={data?.items} loading={isLoading} error={error} onRetry={refetch} pagination={data?.pagination} onPage={setPage} onRowClick={(u) => navigate(`/admin/users/${u.id}`)}
        columns={[
          { key: 'name', header: 'User', cell: (u) => <div className="flex items-center gap-3"><Avatar name={u.name} src={u.avatar_url} size="h-9 w-9" /><div><p className="font-semibold">{u.name}</p><p className="text-xs text-ink-500">{u.email}</p></div></div> },
          { key: 'phone', header: 'Phone', cell: (u) => u.phone ?? '—' },
          { key: 'roles', header: 'Roles', cell: (u) => <div className="flex flex-wrap gap-1">{u.roles?.map((r) => <Badge key={r} tone={r === 'customer' ? 'neutral' : r === 'hotel-partner' ? 'sand' : 'brand'}>{r}</Badge>)}</div> },
          { key: 'joined', header: 'Joined', cell: (u) => fmtDate(u.created_at) },
          { key: 'login', header: 'Last login', cell: (u) => fmtDate(u.last_login_at) },
          { key: 'status', header: 'Status', cell: (u) => <StatusBadge status={u.status} /> },
        ]} />
    </div>
  );
}

import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Plus, Trash2 } from 'lucide-react';
import { ApiError, del, get, getPage, post, put } from '@/lib/api';
import { fmtDate, money, titleCase } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Badge, ConfirmDialog, StatusBadge } from '@/components/ui/misc';
import { AdminHeader, DataTable, FormModal, Toolbar, type FieldDef } from '@/components/admin/kit';
import type { Coupon, MembershipPlan, TourPackage } from '@/types';

export default function AdminCoupons() {
  usePageMeta('Coupons — Admin');
  const qc = useQueryClient();
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Coupon | 'new' | null>(null);
  const [deleting, setDeleting] = useState<Coupon | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['admin', 'coupons', q, page], queryFn: () => getPage<Coupon>('/admin/coupons', { q: q || undefined, page }) });
  const hotels = useQuery({ queryKey: ['admin', 'hotel-options'], queryFn: () => get<{ id: number; name: string; city: string }[]>('/admin/hotel-options') });
  const packages = useQuery({ queryKey: ['admin', 'packages-opts'], queryFn: () => getPage<TourPackage>('/admin/tour-packages', {}) });
  const plans = useQuery({ queryKey: ['admin', 'plans'], queryFn: () => get<MembershipPlan[]>('/admin/membership-plans') });

  const fields = useMemo<FieldDef[]>(() => [
    { name: 'code', label: 'Code', type: 'text', required: true, hint: 'Letters, numbers, - and _' },
    { name: 'title', label: 'Title', type: 'text', required: true },
    { name: 'description', label: 'Description', type: 'textarea', rows: 2 },
    { name: 'discount_type', label: 'Discount type', type: 'select', options: [{ value: 'percentage', label: 'Percentage' }, { value: 'fixed', label: 'Fixed amount' }] },
    { name: 'discount_value', label: 'Discount value', type: 'number', min: 0 },
    { name: 'min_purchase', label: 'Minimum purchase (₹)', type: 'number', min: 0 },
    { name: 'max_discount', label: 'Maximum discount (₹)', type: 'number', min: 0 },
    { name: 'starts_at', label: 'Starts', type: 'datetime-local' },
    { name: 'ends_at', label: 'Ends', type: 'datetime-local' },
    { name: 'usage_limit', label: 'Total usage limit', type: 'number', min: 1, hint: 'Empty = unlimited' },
    { name: 'per_user_limit', label: 'Per-customer limit', type: 'number', min: 1 },
    { name: 'applies_to', label: 'Applies to', type: 'select', options: [{ value: 'all', label: 'Hotels, trips & memberships' }, { value: 'hotels', label: 'Hotels' }, { value: 'tours', label: 'Trips' }, { value: 'memberships', label: 'Memberships' }] },
    { name: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }] },
    { name: 'is_public', label: 'Show on Offers page', type: 'checkbox' },
    { name: 'hotel_ids', label: 'Limit to hotels (none = all)', type: 'multiselect', options: (hotels.data ?? []).map((h) => ({ value: h.id, label: h.name })) },
    { name: 'tour_package_ids', label: 'Limit to trips based on packages (none = all)', type: 'multiselect', options: (packages.data?.items ?? []).map((p) => ({ value: p.id, label: p.name })) },
    { name: 'membership_plan_ids', label: 'Limit to membership plans (none = all)', type: 'multiselect', options: (plans.data ?? []).map((p) => ({ value: p.id, label: p.name })) },
  ], [hotels.data, packages.data, plans.data]);

  const save = useMutation({
    mutationFn: (v: Record<string, unknown>) => (editing === 'new' ? post('/admin/coupons', v) : put(`/admin/coupons/${(editing as Coupon).id}`, v)),
    onSuccess: (r) => { toast.success(r.message); setEditing(null); setErrors({}); qc.invalidateQueries({ queryKey: ['admin', 'coupons'] }); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  const remove = useMutation({ mutationFn: (c: Coupon) => del(`/admin/coupons/${c.id}`), onSuccess: (r) => { toast.success(r.message); setDeleting(null); refetch(); } });
  const live = (c: Coupon) => c.status === 'active' && (!c.ends_at || new Date(c.ends_at) > new Date()) && (!c.starts_at || new Date(c.starts_at) <= new Date());
  const toLocal = (s: string | null) => (s ? s.slice(0, 16) : '');

  return (
    <div>
      <AdminHeader title="Coupons & discounts" description="All discounts are validated and calculated server-side at checkout." actions={<Button onClick={() => setEditing('new')} icon={<Plus className="h-4 w-4" />}>New coupon</Button>} />
      <Toolbar><input className="input w-60" placeholder="Search code or title" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} aria-label="Search coupons" /></Toolbar>
      <DataTable rows={data?.items} loading={isLoading} error={error} onRetry={refetch} pagination={data?.pagination} onPage={setPage} onRowClick={setEditing}
        columns={[
          { key: 'code', header: 'Code', cell: (c) => <div><p className="font-mono font-bold text-brand-700">{c.code}</p><p className="text-xs text-ink-500">{c.title}</p></div> },
          { key: 'disc', header: 'Discount', cell: (c) => (c.discount_type === 'percentage' ? `${c.discount_value}%${c.max_discount ? ` (max ${money(c.max_discount)})` : ''}` : money(c.discount_value)) },
          { key: 'scope', header: 'Applies to', cell: (c) => titleCase(c.applies_to) },
          { key: 'usage', header: 'Used', cell: (c) => `${c.used_count ?? 0}${c.usage_limit ? ` / ${c.usage_limit}` : ''}` },
          { key: 'valid', header: 'Valid', cell: (c) => `${fmtDate(c.starts_at, 'd MMM')} – ${fmtDate(c.ends_at, 'd MMM yy')}` },
          { key: 'status', header: 'Status', cell: (c) => <div className="flex gap-1">{live(c) ? <Badge tone="success">Live</Badge> : <StatusBadge status={c.status === 'active' ? 'expired' : 'inactive'} label={c.status === 'active' ? 'Not live' : 'Inactive'} />}{c.is_public && <Badge tone="coral">Public</Badge>}</div> },
          { key: 'x', header: '', className: 'text-right', cell: (c) => <button onClick={(e) => { e.stopPropagation(); setDeleting(c); }} className="rounded-lg p-1.5 text-ink-400 hover:bg-danger-50 hover:text-danger-600" aria-label={`Delete ${c.code}`}><Trash2 className="h-4 w-4" /></button> },
        ]} />
      <FormModal open={!!editing} onClose={() => { setEditing(null); setErrors({}); }} title={editing === 'new' ? 'New coupon' : `Edit ${(editing as Coupon | null)?.code ?? ''}`} fields={fields} errors={errors} submitting={save.isPending} size="xl"
        initial={editing && editing !== 'new' ? { ...editing, starts_at: toLocal(editing.starts_at), ends_at: toLocal(editing.ends_at) } as unknown as Record<string, unknown> : { discount_type: 'percentage', applies_to: 'all', per_user_limit: 1, min_purchase: 0, status: 'active' }}
        onSubmit={(v) => save.mutate(v)} />
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && remove.mutate(deleting)} loading={remove.isPending} tone="danger" confirmLabel="Deactivate" title={`Deactivate ${deleting?.code}?`} description="Past bookings keep their discount." />
    </div>
  );
}

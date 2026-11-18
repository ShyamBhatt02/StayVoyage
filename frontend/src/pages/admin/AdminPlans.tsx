import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { CalendarX2, Crown, Pencil, Plus, Trash2 } from 'lucide-react';
import { ApiError, del, get, post, put } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { Badge, ErrorState, Modal, Skeleton, StatusBadge } from '@/components/ui/misc';
import { AdminHeader, FormModal, type FieldDef } from '@/components/admin/kit';
import type { Hotel, MembershipPlan } from '@/types';

export default function AdminPlans() {
  usePageMeta('Membership plans — Admin');
  const { can } = useAuth();
  const qc = useQueryClient();
  const [editing, setEditing] = useState<MembershipPlan | 'new' | null>(null);
  const [blackouts, setBlackouts] = useState<MembershipPlan | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const plans = useQuery({ queryKey: ['admin', 'plans'], queryFn: () => get<MembershipPlan[]>('/admin/membership-plans') });
  const hotels = useQuery({ queryKey: ['admin', 'hotel-options'], queryFn: () => get<Pick<Hotel, 'id' | 'name' | 'city'>[]>('/admin/hotel-options') });

  const fields = useMemo<FieldDef[]>(() => [
    { name: 'name', label: 'Plan name', type: 'text', required: true },
    { name: 'tagline', label: 'Tagline', type: 'text' },
    { name: 'description', label: 'Description', type: 'textarea' },
    { name: 'price', label: 'Price', type: 'number', min: 0 },
    { name: 'currency', label: 'Currency', type: 'select', options: [{ value: 'INR', label: 'INR' }] },
    { name: 'duration_days', label: 'Duration (days)', type: 'number', min: 1 },
    { name: 'included_nights', label: 'Included nights', type: 'number', min: 1 },
    { name: 'max_nights_per_booking', label: 'Max nights per booking', type: 'number', min: 1 },
    { name: 'max_rooms_per_booking', label: 'Max rooms per booking', type: 'number', min: 1 },
    { name: 'max_guests_per_booking', label: 'Max guests per booking', type: 'number', min: 1 },
    { name: 'min_advance_booking_days', label: 'Min days in advance', type: 'number', min: 0 },
    { name: 'max_advance_booking_days', label: 'Max days in advance', type: 'number', min: 1 },
    { name: 'cancellation_deadline_hours', label: 'Free cancellation deadline (hours)', type: 'number', min: 0 },
    { name: 'late_cancellation_policy', label: 'Late cancellation', type: 'select', options: [{ value: 'forfeit_nights', label: 'Nights forfeited' }, { value: 'refund_nights', label: 'Nights returned' }] },
    { name: 'no_show_policy', label: 'No-show policy', type: 'select', options: [{ value: 'forfeit_nights', label: 'Nights forfeited' }, { value: 'refund_nights', label: 'Nights returned' }] },
    { name: 'eligible_room_types', label: 'Eligible room types (none = all)', type: 'multiselect', options: ['standard', 'deluxe', 'premium', 'suite', 'family', 'villa'].map((t) => ({ value: t, label: t })) },
    { name: 'all_hotels_eligible', label: 'All membership-eligible hotels', type: 'checkbox' },
    { name: 'hotel_ids', label: 'Specific eligible hotels (used when the box above is off)', type: 'multiselect', options: (hotels.data ?? []).map((h) => ({ value: h.id, label: `${h.name}, ${h.city}` })) },
    { name: 'unused_nights_expire', label: 'Unused nights expire', type: 'checkbox' },
    { name: 'nights_extend_past_expiry', label: 'Stays may extend past expiry', type: 'checkbox' },
    { name: 'auto_renew_available', label: 'Auto-renewal available', type: 'checkbox' },
    { name: 'charge_taxes_on_covered_amount', label: 'Charge GST on covered room amount', type: 'checkbox' },
    { name: 'features', label: 'Marketing features', type: 'list' },
    { name: 'is_featured', label: 'Featured plan', type: 'checkbox' },
    { name: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }] },
    { name: 'sort_order', label: 'Sort order', type: 'number', min: 0 },
  ], [hotels.data]);

  const save = useMutation({
    mutationFn: (v: Record<string, unknown>) => (editing === 'new' ? post('/admin/membership-plans', v) : put(`/admin/membership-plans/${(editing as MembershipPlan).id}`, v)),
    onSuccess: (r) => { toast.success(r.message); setEditing(null); setErrors({}); qc.invalidateQueries({ queryKey: ['admin', 'plans'] }); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });

  if (plans.isError) return <ErrorState onRetry={() => plans.refetch()} />;
  return (
    <div>
      <AdminHeader title="Membership plans" description="All membership rules are configured here. Changes apply to new purchases — existing members keep the rules frozen at purchase."
        actions={can('memberships.manage') && <Button onClick={() => setEditing('new')} icon={<Plus className="h-4 w-4" />}>New plan</Button>} />
      <div className="grid gap-6 lg:grid-cols-2">
        {plans.isLoading ? <Skeleton className="h-72 rounded-2xl" /> : plans.data?.map((p) => (
          <article key={p.id} className="card p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div><div className="flex items-center gap-2"><Crown className="h-5 w-5 text-sand-600" /><h2 className="font-display text-xl font-semibold">{p.name}</h2>{p.is_featured && <Badge tone="coral">Featured</Badge>}</div><p className="text-sm text-ink-500">{p.tagline}</p></div>
              <StatusBadge status={p.status} />
            </div>
            <p className="mt-4 text-3xl font-bold">{money(p.price)} <span className="text-sm font-normal text-ink-500">/ {p.duration_days} days · {p.included_nights} nights</span></p>
            <dl className="mt-4 grid grid-cols-2 gap-2 text-sm">
              {[['Max nights / booking', p.max_nights_per_booking], ['Max rooms / guests', `${p.max_rooms_per_booking} / ${p.max_guests_per_booking}`], ['Booking window', `${p.min_advance_booking_days}–${p.max_advance_booking_days} days`],
                ['Free cancellation', `${p.cancellation_deadline_hours}h before`], ['Late cancellation', p.late_cancellation_policy.replace('_', ' ')], ['Room types', p.eligible_room_types?.join(', ') || 'All'],
                ['Active members', p.active_members ?? 0], ['Hotels', p.all_hotels_eligible ? 'All eligible' : `${p.eligible_hotel_ids?.length ?? 0} selected`]].map(([k, val]) => (
                <div key={k as string} className="rounded-lg bg-ink-50 px-3 py-2"><dt className="text-xs text-ink-500">{k}</dt><dd className="font-semibold capitalize">{String(val)}</dd></div>
              ))}
            </dl>
            <div className="mt-5 flex flex-wrap gap-2">
              {can('memberships.manage') && <Button size="sm" variant="outline" onClick={() => setEditing(p)} icon={<Pencil className="h-3.5 w-3.5" />}>Edit rules</Button>}
              <Button size="sm" variant="outline" onClick={() => setBlackouts(p)} icon={<CalendarX2 className="h-3.5 w-3.5" />}>Blackout dates ({p.blackout_dates?.length ?? 0})</Button>
            </div>
          </article>
        ))}
      </div>
      <FormModal open={!!editing} onClose={() => { setEditing(null); setErrors({}); }} title={editing === 'new' ? 'New membership plan' : `Edit ${(editing as MembershipPlan | null)?.name ?? ''}`} fields={fields} errors={errors} submitting={save.isPending} size="xl"
        initial={editing && editing !== 'new' ? { ...editing, hotel_ids: editing.eligible_hotel_ids } as unknown as Record<string, unknown> : { currency: 'INR', duration_days: 30, included_nights: 30, max_nights_per_booking: 7, max_rooms_per_booking: 1, max_guests_per_booking: 2, cancellation_deadline_hours: 24, all_hotels_eligible: true, unused_nights_expire: true, auto_renew_available: true, charge_taxes_on_covered_amount: true, status: 'active', late_cancellation_policy: 'forfeit_nights', no_show_policy: 'forfeit_nights' }}
        onSubmit={(v) => save.mutate(v)} />
      {blackouts && <BlackoutModal plan={blackouts} onClose={() => setBlackouts(null)} onChange={() => qc.invalidateQueries({ queryKey: ['admin', 'plans'] }).then(() => setBlackouts(null))} canEdit={can('memberships.manage')} />}
    </div>
  );
}

function BlackoutModal({ plan, onClose, onChange, canEdit }: { plan: MembershipPlan; onClose: () => void; onChange: () => void; canEdit: boolean }) {
  const [v, setV] = useState({ start_date: '', end_date: '', reason: '' });
  const add = useMutation({ mutationFn: () => post(`/admin/membership-plans/${plan.id}/blackouts`, v), onSuccess: (r) => { toast.success(r.message); onChange(); }, onError: (e) => toast.error((e as Error).message) });
  const remove = useMutation({ mutationFn: (bid: number) => del(`/admin/membership-plans/${plan.id}/blackouts/${bid}`), onSuccess: (r) => { toast.success(r.message); onChange(); }, onError: (e) => toast.error((e as Error).message) });
  return (
    <Modal open onClose={onClose} title={`Blackout dates — ${plan.name}`} description="Members can't use night credits on these dates (regular bookings are unaffected)."
      footer={canEdit && <Button onClick={() => add.mutate()} loading={add.isPending} disabled={!v.start_date || !v.end_date}>Add blackout</Button>}>
      <ul className="mb-5 space-y-2">{plan.blackout_dates?.map((b) => <li key={b.id} className="flex items-center justify-between rounded-xl bg-ink-50 px-3 py-2 text-sm"><span>{fmtDate(b.start_date)} – {fmtDate(b.end_date)}{b.reason ? ` · ${b.reason}` : ''}</span>{canEdit && <button onClick={() => remove.mutate(b.id)} className="text-danger-600" aria-label="Remove blackout"><Trash2 className="h-4 w-4" /></button>}</li>)}
        {!plan.blackout_dates?.length && <li className="text-sm text-ink-500">No blackout dates.</li>}</ul>
      {canEdit && <div className="grid gap-3 sm:grid-cols-2">
        <Input label="From" type="date" value={v.start_date} onChange={(e) => setV({ ...v, start_date: e.target.value })} />
        <Input label="To" type="date" value={v.end_date} onChange={(e) => setV({ ...v, end_date: e.target.value })} />
        <Input wrapClassName="sm:col-span-2" label="Reason" value={v.reason} onChange={(e) => setV({ ...v, reason: e.target.value })} placeholder="e.g. Diwali peak" />
      </div>}
    </Modal>
  );
}

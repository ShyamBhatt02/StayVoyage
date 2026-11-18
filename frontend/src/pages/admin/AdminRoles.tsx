import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { UserPlus } from 'lucide-react';
import { ApiError, get, post, put } from '@/lib/api';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { ErrorState, Skeleton } from '@/components/ui/misc';
import { AdminHeader, FormModal, type FieldDef } from '@/components/admin/kit';

interface RolesData { roles: { slug: string; name: string; description: string; permissions: string[] }[]; permissions: { slug: string; name: string; group: string }[] }

export default function AdminRoles() {
  usePageMeta('Roles & staff — Admin');
  const qc = useQueryClient();
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['admin', 'roles'], queryFn: () => get<RolesData>('/admin/roles') });
  const hotels = useQuery({ queryKey: ['admin', 'hotel-options'], queryFn: () => get<{ id: number; name: string }[]>('/admin/hotel-options') });
  const [matrix, setMatrix] = useState<Record<string, string[]>>({});
  const [creating, setCreating] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  useEffect(() => { if (data) setMatrix(Object.fromEntries(data.roles.map((r) => [r.slug, r.permissions]))); }, [data]);

  const saveRole = useMutation({ mutationFn: (slug: string) => put(`/admin/roles/${slug}/permissions`, { permissions: matrix[slug] }), onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['admin', 'roles'] }); }, onError: (e) => toast.error((e as Error).message) });
  const create = useMutation({
    mutationFn: (v: Record<string, unknown>) => post('/admin/users', v),
    onSuccess: (r) => { toast.success(r.message); setCreating(false); setErrors({}); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  const fields: FieldDef[] = [
    { name: 'name', label: 'Full name', type: 'text' }, { name: 'email', label: 'Email', type: 'email' }, { name: 'phone', label: 'Phone', type: 'text' },
    { name: 'password', label: 'Temporary password', type: 'password', hint: '8+ chars with upper/lower case, number & symbol' },
    { name: 'role', label: 'Role', type: 'select', options: [{ value: 'admin', label: 'Admin / Staff' }, { value: 'hotel-partner', label: 'Hotel partner' }, { value: 'customer', label: 'Customer' }] },
    { name: 'hotel_ids', label: 'Hotels (for partners)', type: 'multiselect', options: (hotels.data ?? []).map((h) => ({ value: h.id, label: h.name })) },
  ];

  if (isError) return <ErrorState onRetry={() => refetch()} />;
  if (isLoading || !data) return <Skeleton className="h-96 rounded-2xl" />;
  const groups = Array.from(new Set(data.permissions.map((p) => p.group)));
  const editable = data.roles.filter((r) => r.slug === 'admin');

  return (
    <div className="space-y-6">
      <AdminHeader title="Roles & staff" description="Super admins have every permission. Hotel partners are limited to their own hotels by policy." actions={<Button onClick={() => setCreating(true)} icon={<UserPlus className="h-4 w-4" />}>Create account</Button>} />
      <div className="grid gap-4 md:grid-cols-4">{data.roles.map((r) => <div key={r.slug} className="card p-4"><p className="font-semibold">{r.name}</p><p className="mt-1 text-xs text-ink-500">{r.description}</p><p className="mt-2 text-xs font-semibold text-brand-700">{r.permissions.includes('*') ? 'All permissions' : `${r.permissions.length} permissions`}</p></div>)}</div>
      {editable.map((role) => (
        <section key={role.slug} className="card p-5">
          <div className="flex items-center justify-between"><h2 className="font-sans text-lg font-bold">{role.name} permissions</h2><Button size="sm" onClick={() => saveRole.mutate(role.slug)} loading={saveRole.isPending}>Save</Button></div>
          <div className="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {groups.map((g) => (
              <fieldset key={g}>
                <legend className="mb-2 text-xs font-bold uppercase tracking-wider text-ink-500">{g}</legend>
                {data.permissions.filter((p) => p.group === g).map((p) => (
                  <label key={p.slug} className="flex cursor-pointer items-center gap-2 py-1 text-sm">
                    <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={matrix[role.slug]?.includes(p.slug) ?? false}
                      onChange={(e) => setMatrix((m) => ({ ...m, [role.slug]: e.target.checked ? [...(m[role.slug] ?? []), p.slug] : (m[role.slug] ?? []).filter((x) => x !== p.slug) }))} />
                    {p.name}
                  </label>
                ))}
              </fieldset>
            ))}
          </div>
        </section>
      ))}
      <FormModal open={creating} onClose={() => { setCreating(false); setErrors({}); }} title="Create staff / partner account" fields={fields} errors={errors} submitting={create.isPending} initial={{ role: 'admin' }} onSubmit={(v) => create.mutate(v)} />
    </div>
  );
}

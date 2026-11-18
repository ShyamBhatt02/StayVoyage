import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Plus, Trash2 } from 'lucide-react';
import { ApiError, del, get, http, post, put } from '@/lib/api';
import { fmtDateTime } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { ErrorState, Skeleton, StatusBadge, Tabs } from '@/components/ui/misc';
import { AdminHeader, DataTable, FormModal, type FieldDef } from '@/components/admin/kit';

interface Faq { id: number; category: string; question: string; answer: string; show_on_home: boolean; is_active: boolean; sort_order: number }
interface Msg { id: number; name: string; email: string; phone: string | null; subject: string; message: string; status: string; created_at: string }

const FAQ_FIELDS: FieldDef[] = [
  { name: 'category', label: 'Category', type: 'select', options: ['Membership', 'Bookings', 'Cancellations', 'Payments', 'Tour Builder', 'Account'].map((c) => ({ value: c, label: c })) },
  { name: 'sort_order', label: 'Sort order', type: 'number', min: 0 },
  { name: 'question', label: 'Question', type: 'text', span: 2 },
  { name: 'answer', label: 'Answer', type: 'textarea', rows: 5 },
  { name: 'show_on_home', label: 'Show on home page', type: 'checkbox' },
  { name: 'is_active', label: 'Published', type: 'checkbox' },
];

export default function AdminContent() {
  usePageMeta('Content — Admin');
  const [tab, setTab] = useState<'messages' | 'faqs'>('messages');
  return (
    <div>
      <AdminHeader title="FAQs & messages" description="Help-centre content and contact form submissions." />
      <Tabs className="mb-5 w-fit" value={tab} onChange={setTab} tabs={[{ value: 'messages', label: 'Contact messages' }, { value: 'faqs', label: 'FAQs' }]} />
      {tab === 'messages' ? <Messages /> : <Faqs />}
    </div>
  );
}

function Messages() {
  const qc = useQueryClient();
  const [open, setOpen] = useState<number | null>(null);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['admin', 'messages'], queryFn: async () => (await http.get<{ data: Msg[] }>('/admin/messages')).data.data });
  const update = useMutation({ mutationFn: ({ id, status }: { id: number; status: string }) => put(`/admin/messages/${id}`, { status }), onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'messages'] }) });
  return (
    <DataTable rows={data} loading={isLoading} error={error} onRetry={refetch} onRowClick={(m) => { setOpen(open === m.id ? null : m.id); if (m.status === 'new') update.mutate({ id: m.id, status: 'read' }); }}
      columns={[
        { key: 'from', header: 'From', cell: (m) => <div><p className="font-semibold">{m.name}</p><p className="text-xs text-ink-500">{m.email}{m.phone ? ` · ${m.phone}` : ''}</p></div> },
        { key: 'subj', header: 'Message', cell: (m) => <div className="max-w-xl"><p className="font-medium">{m.subject}</p><p className={open === m.id ? 'mt-1 whitespace-pre-wrap text-sm text-ink-600' : 'line-clamp-1 text-sm text-ink-500'}>{m.message}</p></div> },
        { key: 'when', header: 'Received', cell: (m) => fmtDateTime(m.created_at) },
        { key: 'status', header: 'Status', cell: (m) => <div className="flex items-center gap-2"><StatusBadge status={m.status === 'resolved' ? 'completed' : m.status === 'new' ? 'pending' : 'info'} label={m.status} />
          {m.status !== 'resolved' && <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); update.mutate({ id: m.id, status: 'resolved' }); }}>Resolve</Button>}</div> },
      ]} />
  );
}

function Faqs() {
  const qc = useQueryClient();
  const [editing, setEditing] = useState<Faq | 'new' | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['admin', 'faqs'], queryFn: () => get<Faq[]>('/admin/faqs') });
  const save = useMutation({
    mutationFn: (v: Record<string, unknown>) => (editing === 'new' ? post('/admin/faqs', v) : put(`/admin/faqs/${(editing as Faq).id}`, v)),
    onSuccess: (r) => { toast.success(r.message); setEditing(null); qc.invalidateQueries({ queryKey: ['admin', 'faqs'] }); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  const remove = useMutation({ mutationFn: (f: Faq) => del(`/admin/faqs/${f.id}`), onSuccess: () => refetch() });
  if (isError) return <ErrorState onRetry={() => refetch()} />;
  return (
    <div>
      <div className="mb-4 flex justify-end"><Button onClick={() => setEditing('new')} icon={<Plus className="h-4 w-4" />}>Add FAQ</Button></div>
      {isLoading ? <Skeleton className="h-64" /> : (
        <ul className="card divide-y divide-ink-100">
          {data?.map((f) => (
            <li key={f.id} className="flex items-start gap-4 p-4">
              <button onClick={() => setEditing(f)} className="flex-1 text-left">
                <p className="text-xs font-semibold uppercase tracking-wider text-coral-600">{f.category}{f.show_on_home ? ' · home' : ''}{!f.is_active ? ' · draft' : ''}</p>
                <p className="font-semibold text-ink-900">{f.question}</p><p className="line-clamp-2 text-sm text-ink-600">{f.answer}</p>
              </button>
              <button onClick={() => remove.mutate(f)} className="rounded-lg p-1.5 text-ink-400 hover:bg-danger-50 hover:text-danger-600" aria-label="Delete FAQ"><Trash2 className="h-4 w-4" /></button>
            </li>
          ))}
        </ul>
      )}
      <FormModal open={!!editing} onClose={() => { setEditing(null); setErrors({}); }} title={editing === 'new' ? 'Add FAQ' : 'Edit FAQ'} fields={FAQ_FIELDS} errors={errors} submitting={save.isPending}
        initial={editing && editing !== 'new' ? (editing as unknown as Record<string, unknown>) : { category: 'Bookings', is_active: true, sort_order: 0 }} onSubmit={(v) => save.mutate(v)} />
    </div>
  );
}

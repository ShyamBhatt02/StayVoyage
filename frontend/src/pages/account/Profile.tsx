import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Camera, KeyRound, ShieldCheck, ShieldOff } from 'lucide-react';
import { ApiError, del, post, put, upload } from '@/lib/api';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { Avatar, Badge, Modal } from '@/components/ui/misc';
import { PageHeader } from '@/components/account';
import { PasswordChecklist, passwordRules } from '@/pages/auth/AuthShell';

const profileSchema = z.object({
  name: z.string().trim().min(2).max(80),
  email: z.string().trim().email('Enter a valid email'),
  phone: z.string().trim().regex(/^\+?[\d\s-]{10,18}$/, 'Enter a valid phone number'),
});
const passwordSchema = z.object({
  current_password: z.string().min(1, 'Enter your current password'),
  password: z.string().refine((v) => passwordRules.every((r) => r.test(v)), 'Password does not meet the requirements'),
  password_confirmation: z.string(),
}).refine((v) => v.password === v.password_confirmation, { message: 'Passwords do not match', path: ['password_confirmation'] });

export default function Profile() {
  usePageMeta('Profile & security');
  const { user, refresh } = useAuth();

  const pf = useForm<z.infer<typeof profileSchema>>({ resolver: zodResolver(profileSchema), values: { name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '' } });
  const pw = useForm<z.infer<typeof passwordSchema>>({ resolver: zodResolver(passwordSchema) });

  const saveProfile = async (v: z.infer<typeof profileSchema>) => {
    try {
      const r = await put('/profile', { ...v, phone: v.phone.replace(/[\s-]/g, '') });
      toast.success(r.message);
      refresh();
    } catch (e) {
      if (e instanceof ApiError) Object.entries(e.errors).forEach(([k, m]) => pf.setError(k as 'name', { message: m[0] }));
      toast.error((e as Error).message);
    }
  };
  const savePassword = async (v: z.infer<typeof passwordSchema>) => {
    try {
      const r = await put('/profile/password', v);
      toast.success(r.message);
      pw.reset();
    } catch (e) {
      if (e instanceof ApiError) Object.entries(e.errors).forEach(([k, m]) => pw.setError(k as 'password', { message: m[0] }));
      else toast.error((e as Error).message);
    }
  };
  const avatar = useMutation({
    mutationFn: (file: File) => upload('/profile/avatar', { avatar: file }),
    onSuccess: (r) => { toast.success(r.message); refresh(); },
    onError: (e) => toast.error((e as Error).message),
  });

  return (
    <div className="space-y-6">
      <PageHeader title="Profile & security" description="Manage your personal details, password and sign-in security." />

      <section className="card p-6">
        <div className="flex flex-wrap items-center gap-5">
          <div className="relative">
            <Avatar name={user?.name} src={user?.avatar_url} size="h-20 w-20 text-xl" />
            <label className="absolute -bottom-1 -right-1 flex h-8 w-8 cursor-pointer items-center justify-center rounded-full bg-brand-600 text-white shadow hover:bg-brand-700" aria-label="Upload profile photo">
              <Camera className="h-4 w-4" />
              <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => e.target.files?.[0] && avatar.mutate(e.target.files[0])} />
            </label>
          </div>
          <div>
            <p className="font-display text-xl font-semibold text-ink-900">{user?.name}</p>
            <p className="text-sm text-ink-500">{user?.email}</p>
            <div className="mt-1">{user?.email_verified ? <Badge tone="success">Email verified</Badge> : <Badge tone="warning">Email not verified</Badge>}</div>
          </div>
        </div>
        <form onSubmit={pf.handleSubmit(saveProfile)} className="mt-6 grid gap-4 sm:grid-cols-2" noValidate>
          <Input label="Full name" {...pf.register('name')} error={pf.formState.errors.name?.message} />
          <Input label="Email" type="email" {...pf.register('email')} error={pf.formState.errors.email?.message} hint="Changing email requires re-verification" />
          <Input label="Mobile number" type="tel" {...pf.register('phone')} error={pf.formState.errors.phone?.message} />
          <div className="flex items-end"><Button type="submit" loading={pf.formState.isSubmitting}>Save changes</Button></div>
        </form>
      </section>

      <section className="card p-6">
        <h2 className="flex items-center gap-2 font-sans text-lg font-bold text-ink-900"><KeyRound className="h-5 w-5 text-brand-600" /> Change password</h2>
        <form onSubmit={pw.handleSubmit(savePassword)} className="mt-4 grid gap-4 sm:grid-cols-2" noValidate>
          <Input label="Current password" type="password" autoComplete="current-password" {...pw.register('current_password')} error={pw.formState.errors.current_password?.message} wrapClassName="sm:col-span-2 sm:max-w-sm" />
          <div>
            <Input label="New password" type="password" autoComplete="new-password" {...pw.register('password')} error={pw.formState.errors.password?.message} />
            <PasswordChecklist value={pw.watch('password') ?? ''} />
          </div>
          <Input label="Confirm new password" type="password" autoComplete="new-password" {...pw.register('password_confirmation')} error={pw.formState.errors.password_confirmation?.message} />
          <div><Button type="submit" loading={pw.formState.isSubmitting}>Update password</Button></div>
        </form>
      </section>

      <TwoFactorSection enabled={!!user?.two_factor_enabled} onChange={() => refresh()} staff={!!user?.is_staff} />
    </div>
  );
}

function TwoFactorSection({ enabled, onChange, staff }: { enabled: boolean; onChange: () => void; staff: boolean }) {
  const [setup, setSetup] = useState<{ secret: string; qr_svg: string } | null>(null);
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [disableOpen, setDisableOpen] = useState(false);
  const [password, setPassword] = useState('');

  const begin = useMutation({ mutationFn: () => post<{ secret: string; qr_svg: string }>('/profile/two-factor'), onSuccess: (r) => setSetup(r.data), onError: (e) => toast.error((e as Error).message) });
  const confirm = useMutation({
    mutationFn: () => post<{ recovery_codes: string[] }>('/profile/two-factor/confirm', { code }),
    onSuccess: (r) => { setCodes(r.data.recovery_codes); setSetup(null); setCode(''); onChange(); toast.success(r.message); },
    onError: (e) => toast.error((e as Error).message),
  });
  const disable = useMutation({
    mutationFn: () => del('/profile/two-factor', { password }),
    onSuccess: (r) => { toast.success(r.message); setDisableOpen(false); setPassword(''); onChange(); },
    onError: (e) => toast.error((e as Error).message),
  });

  return (
    <section className="card p-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="flex items-center gap-2 font-sans text-lg font-bold text-ink-900"><ShieldCheck className="h-5 w-5 text-brand-600" /> Two-factor authentication</h2>
          <p className="mt-1 max-w-xl text-sm text-ink-600">Add a second step at login with an authenticator app (Google Authenticator, Authy, 1Password).{staff && ' Strongly recommended for admin accounts.'}</p>
        </div>
        {enabled ? <Badge tone="success">Enabled</Badge> : <Badge tone="neutral">Off</Badge>}
      </div>
      <div className="mt-4">
        {enabled ? <Button variant="outline" onClick={() => setDisableOpen(true)} icon={<ShieldOff className="h-4 w-4" />}>Turn off 2FA</Button>
          : !setup && <Button onClick={() => begin.mutate()} loading={begin.isPending}>Set up 2FA</Button>}
      </div>
      {setup && (
        <div className="mt-5 grid gap-6 rounded-2xl bg-ink-50 p-5 sm:grid-cols-[220px_1fr]">
          <div className="rounded-xl bg-white p-2" dangerouslySetInnerHTML={{ __html: setup.qr_svg }} aria-label="QR code for authenticator app" />
          <div>
            <p className="text-sm text-ink-700">1. Scan the QR code in your authenticator app, or enter this key manually:</p>
            <p className="mt-2 break-all rounded-lg bg-white px-3 py-2 font-mono text-sm">{setup.secret}</p>
            <p className="mt-4 text-sm text-ink-700">2. Enter the 6-digit code shown in the app:</p>
            <div className="mt-2 flex gap-2">
              <input className="input max-w-[160px] text-center font-mono tracking-widest" inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} aria-label="6-digit code" />
              <Button onClick={() => confirm.mutate()} loading={confirm.isPending} disabled={code.length !== 6}>Verify</Button>
            </div>
          </div>
        </div>
      )}
      <Modal open={!!codes} onClose={() => setCodes(null)} title="Save your recovery codes" description="Each code works once if you lose access to your authenticator. Store them somewhere safe — they won't be shown again."
        footer={<Button onClick={() => { navigator.clipboard?.writeText(codes?.join('\n') ?? ''); toast.success('Copied to clipboard'); }}>Copy codes</Button>}>
        <ul className="grid grid-cols-2 gap-2 font-mono text-sm">{codes?.map((c) => <li key={c} className="rounded-lg bg-ink-50 px-3 py-2 text-center">{c}</li>)}</ul>
      </Modal>
      <Modal open={disableOpen} onClose={() => setDisableOpen(false)} title="Turn off two-factor authentication?" size="sm"
        footer={<><Button variant="outline" onClick={() => setDisableOpen(false)}>Cancel</Button><Button variant="danger" onClick={() => disable.mutate()} loading={disable.isPending} disabled={!password}>Turn off</Button></>}>
        <Input label="Confirm with your password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
      </Modal>
    </section>
  );
}

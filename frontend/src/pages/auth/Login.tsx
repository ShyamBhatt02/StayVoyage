import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { KeyRound, Lock, Mail } from 'lucide-react';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input } from '@/components/ui/Field';
import { AuthShell } from './AuthShell';

const schema = z.object({
  email: z.string().trim().email('Enter a valid email address'),
  password: z.string().min(1, 'Enter your password'),
  remember: z.boolean().optional(),
});
type Form = z.infer<typeof schema>;

function safeRedirect(r: string | null): string | null {
  return r && r.startsWith('/') && !r.startsWith('//') ? r : null;
}

export default function Login() {
  usePageMeta('Log in');
  const { login, verifyTwoFactor } = useAuth();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [twoFactor, setTwoFactor] = useState(false);
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState<string>();
  const [verifying, setVerifying] = useState(false);
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<Form>({ resolver: zodResolver(schema) });

  const finish = (message: string) => {
    toast.success(message);
    navigate(safeRedirect(params.get('redirect')) ?? '/account', { replace: true });
  };

  const onSubmit = async (v: Form) => {
    try {
      const r = await login(v.email, v.password, v.remember);
      if (r.twoFactor) { setTwoFactor(true); return; }
      finish(r.message);
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.errors.email) setError('email', { message: e.errors.email[0] });
        else toast.error(e.message);
      } else toast.error('Login failed. Please try again.');
    }
  };

  const onVerify = async (e: React.FormEvent) => {
    e.preventDefault();
    setVerifying(true);
    setCodeError(undefined);
    try {
      finish(await verifyTwoFactor(code));
    } catch (err) {
      const apiErr = err as ApiError;
      if (apiErr.code === 'two_factor_expired') { setTwoFactor(false); toast.error(apiErr.message); }
      else setCodeError(apiErr.fieldError?.('code') ?? apiErr.message);
    } finally {
      setVerifying(false);
    }
  };

  if (twoFactor) {
    return (
      <AuthShell title="Two-step verification" subtitle="Enter the 6-digit code from your authenticator app, or one of your recovery codes.">
        <form onSubmit={onVerify} className="space-y-5" noValidate>
          <Input label="Authentication code" leading={<KeyRound className="h-4 w-4" />} inputMode="numeric" autoComplete="one-time-code" autoFocus
            value={code} onChange={(e) => setCode(e.target.value)} error={codeError} placeholder="123456" />
          <Button type="submit" className="w-full" size="lg" loading={verifying} disabled={!code}>Verify & continue</Button>
          <button type="button" onClick={() => setTwoFactor(false)} className="w-full text-sm font-semibold text-ink-500 hover:text-ink-800">Back to login</button>
        </form>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="Welcome back" subtitle={<>New to StayVoyage? <Link to={`/register${params.get('redirect') ? `?redirect=${encodeURIComponent(params.get('redirect')!)}` : ''}`} className="link">Create an account</Link></>}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-5" noValidate>
        <Input label="Email" type="email" autoComplete="email" leading={<Mail className="h-4 w-4" />} {...register('email')} error={errors.email?.message} />
        <div>
          <Input label="Password" type="password" autoComplete="current-password" leading={<Lock className="h-4 w-4" />} {...register('password')} error={errors.password?.message} />
          <div className="mt-2 text-right"><Link to="/forgot-password" className="text-sm font-semibold text-brand-700 hover:underline">Forgot password?</Link></div>
        </div>
        <Checkbox label="Keep me signed in on this device" {...register('remember')} />
        <Button type="submit" className="w-full" size="lg" loading={isSubmitting}>Log in</Button>
      </form>
      <div className="mt-8 rounded-2xl border border-dashed border-ink-300 bg-white p-4 text-xs text-ink-600">
        <p className="font-semibold text-ink-800">Local test accounts (password: Password@123)</p>
        <p className="mt-1">test.customer@stayvoyage.test · test.admin@stayvoyage.test · test.partner@stayvoyage.test</p>
      </div>
    </AuthShell>
  );
}

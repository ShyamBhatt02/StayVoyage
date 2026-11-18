import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { MailCheck, Mail } from 'lucide-react';
import { post } from '@/lib/api';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { AuthShell } from './AuthShell';

const schema = z.object({ email: z.string().trim().email('Enter a valid email address') });

export default function ForgotPassword() {
  usePageMeta('Reset your password');
  const [sent, setSent] = useState<string | null>(null);
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<{ email: string }>({ resolver: zodResolver(schema) });
  const onSubmit = async (v: { email: string }) => {
    try {
      const r = await post('/auth/forgot-password', v);
      setSent(r.message);
    } catch (e) {
      toast.error((e as Error).message);
    }
  };
  return (
    <AuthShell title="Forgot your password?" subtitle="Enter your email and we'll send you a secure reset link.">
      {sent ? (
        <div className="card p-6 text-center">
          <MailCheck className="mx-auto h-10 w-10 text-success-600" />
          <p className="mt-3 font-semibold text-ink-900">Check your inbox</p>
          <p className="mt-1 text-sm text-ink-600">{sent}</p>
          <Link to="/login" className="link mt-5 inline-block">Back to login</Link>
        </div>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-5" noValidate>
          <Input label="Email" type="email" autoComplete="email" leading={<Mail className="h-4 w-4" />} {...register('email')} error={errors.email?.message} />
          <Button type="submit" className="w-full" size="lg" loading={isSubmitting}>Send reset link</Button>
          <p className="text-center text-sm"><Link to="/login" className="link">Back to login</Link></p>
        </form>
      )}
    </AuthShell>
  );
}

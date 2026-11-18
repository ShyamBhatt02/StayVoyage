import { useNavigate, useSearchParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { Lock } from 'lucide-react';
import { post } from '@/lib/api';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { ErrorState } from '@/components/ui/misc';
import { AuthShell, PasswordChecklist, passwordRules } from './AuthShell';

const schema = z
  .object({
    password: z.string().refine((v) => passwordRules.every((r) => r.test(v)), 'Password does not meet the requirements'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { message: 'Passwords do not match', path: ['password_confirmation'] });

export default function ResetPassword() {
  usePageMeta('Choose a new password');
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const token = params.get('token');
  const email = params.get('email');
  const { register, handleSubmit, watch, formState: { errors, isSubmitting } } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema) });

  if (!token || !email) return <div className="container-page py-16"><ErrorState message="This reset link is incomplete. Please request a new one." /></div>;

  const onSubmit = async (v: z.infer<typeof schema>) => {
    try {
      const r = await post('/auth/reset-password', { ...v, token, email });
      toast.success(r.message);
      navigate('/login', { replace: true });
    } catch (e) {
      toast.error((e as Error).message);
    }
  };

  return (
    <AuthShell title="Choose a new password" subtitle={`For ${email}`}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-5" noValidate>
        <div>
          <Input label="New password" type="password" autoComplete="new-password" leading={<Lock className="h-4 w-4" />} {...register('password')} error={errors.password?.message} />
          <PasswordChecklist value={watch('password') ?? ''} />
        </div>
        <Input label="Confirm new password" type="password" autoComplete="new-password" leading={<Lock className="h-4 w-4" />} {...register('password_confirmation')} error={errors.password_confirmation?.message} />
        <Button type="submit" className="w-full" size="lg" loading={isSubmitting}>Reset password</Button>
      </form>
    </AuthShell>
  );
}

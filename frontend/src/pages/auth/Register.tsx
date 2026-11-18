import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { Lock, Mail, Phone, User } from 'lucide-react';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input } from '@/components/ui/Field';
import { AuthShell, PasswordChecklist, passwordRules } from './AuthShell';

const schema = z
  .object({
    name: z.string().trim().min(2, 'Enter your full name').max(80).regex(/^[\p{L}\s.'-]+$/u, 'Use letters only'),
    email: z.string().trim().email('Enter a valid email address'),
    phone: z.string().trim().regex(/^\+?[\d\s-]{10,18}$/, 'Enter a valid mobile number'),
    password: z.string().refine((v) => passwordRules.every((r) => r.test(v)), 'Password does not meet the requirements'),
    password_confirmation: z.string(),
    accept_terms: z.literal(true, { errorMap: () => ({ message: 'Please accept the terms to continue' }) }),
  })
  .refine((v) => v.password === v.password_confirmation, { message: 'Passwords do not match', path: ['password_confirmation'] });
type Form = z.infer<typeof schema>;

export default function Register() {
  usePageMeta('Create your account');
  const { register: signUp } = useAuth();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const { register, handleSubmit, watch, setError, formState: { errors, isSubmitting } } = useForm<Form>({ resolver: zodResolver(schema), defaultValues: { accept_terms: false as unknown as true } });

  const onSubmit = async (v: Form) => {
    try {
      const message = await signUp({ ...v, phone: v.phone.replace(/[\s-]/g, '') });
      toast.success(message);
      const r = params.get('redirect');
      navigate(r && r.startsWith('/') && !r.startsWith('//') ? r : '/account', { replace: true });
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.errors).length) {
        Object.entries(e.errors).forEach(([k, msgs]) => setError(k as keyof Form, { message: msgs[0] }));
      } else toast.error((e as Error).message);
    }
  };

  return (
    <AuthShell title="Create your account" subtitle={<>Already a member? <Link to="/login" className="link">Log in</Link></>} image="/storage/seed/destinations/bali-rice.jpg">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        <Input label="Full name" required autoComplete="name" leading={<User className="h-4 w-4" />} {...register('name')} error={errors.name?.message} />
        <Input label="Email" type="email" required autoComplete="email" leading={<Mail className="h-4 w-4" />} {...register('email')} error={errors.email?.message} />
        <Input label="Mobile number" type="tel" required autoComplete="tel" leading={<Phone className="h-4 w-4" />} placeholder="+91 98250 12345" {...register('phone')} error={errors.phone?.message} />
        <div>
          <Input label="Password" type="password" required autoComplete="new-password" leading={<Lock className="h-4 w-4" />} {...register('password')} error={errors.password?.message} />
          <PasswordChecklist value={watch('password') ?? ''} />
        </div>
        <Input label="Confirm password" type="password" required autoComplete="new-password" leading={<Lock className="h-4 w-4" />} {...register('password_confirmation')} error={errors.password_confirmation?.message} />
        <Checkbox {...register('accept_terms')} error={errors.accept_terms?.message} label={<>I agree to the StayVoyage terms of use and privacy policy.</>} />
        <Button type="submit" className="w-full" size="lg" loading={isSubmitting}>Create account</Button>
      </form>
    </AuthShell>
  );
}

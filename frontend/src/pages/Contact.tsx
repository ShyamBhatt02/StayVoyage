import { useSearchParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { Clock, Mail, MapPin, Phone } from 'lucide-react';
import { ApiError, post } from '@/lib/api';
import { useAuth } from '@/providers/AuthProvider';
import { useSetting } from '@/hooks/useConfig';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Input, Textarea } from '@/components/ui/Field';

const schema = z.object({
  name: z.string().trim().min(2, 'Enter your name').max(80),
  email: z.string().trim().email('Enter a valid email'),
  phone: z.string().trim().max(18).optional(),
  subject: z.string().trim().min(3, 'Add a subject').max(150),
  message: z.string().trim().min(10, 'Tell us a little more (10+ characters)').max(3000),
  website: z.string().max(0).optional(),
});
type Form = z.infer<typeof schema>;

export default function Contact() {
  usePageMeta('Contact us', 'Talk to the StayVoyage travel team — we reply within one business day.');
  const { user } = useAuth();
  const [params] = useSearchParams();
  const email = useSetting('site.support_email', 'support@stayvoyage.test');
  const phone = useSetting('site.support_phone', '+91 79 4000 1234');
  const address = useSetting('site.address', 'Ahmedabad');
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '', subject: params.get('topic') === 'partner' ? 'Partnering with StayVoyage' : '' },
  });
  const onSubmit = async (v: Form) => {
    try {
      const r = await post('/contact', v);
      toast.success(r.message);
      reset({ name: v.name, email: v.email, phone: v.phone, subject: '', message: '' });
    } catch (e) {
      if (e instanceof ApiError) Object.entries(e.errors).forEach(([k, m]) => setError(k as keyof Form, { message: m[0] }));
      toast.error((e as Error).message);
    }
  };
  return (
    <div className="container-page grid gap-10 py-12 lg:grid-cols-[1fr_1.3fr]">
      <div>
        <p className="eyebrow">Contact</p>
        <h1 className="section-title mt-2">We're here to help</h1>
        <p className="mt-3 text-ink-600">Questions about a booking, membership or a trip you're planning? Send us a note and a travel expert will reply within one business day.</p>
        <ul className="mt-8 space-y-5">
          <li className="flex gap-4"><span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Phone className="h-5 w-5" /></span><div><p className="font-semibold">Call us</p><a href={`tel:${String(phone).replace(/\s/g, '')}`} className="text-ink-600 hover:text-brand-700">{String(phone)}</a></div></li>
          <li className="flex gap-4"><span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Mail className="h-5 w-5" /></span><div><p className="font-semibold">Email</p><a href={`mailto:${email}`} className="text-ink-600 hover:text-brand-700">{String(email)}</a></div></li>
          <li className="flex gap-4"><span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><MapPin className="h-5 w-5" /></span><div><p className="font-semibold">Office</p><p className="text-ink-600">{String(address)}</p></div></li>
          <li className="flex gap-4"><span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Clock className="h-5 w-5" /></span><div><p className="font-semibold">Hours</p><p className="text-ink-600">Every day, 9 am – 9 pm IST</p></div></li>
        </ul>
      </div>
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="card grid gap-4 p-6 sm:grid-cols-2 sm:p-8">
        <Input label="Your name" required {...register('name')} error={errors.name?.message} />
        <Input label="Email" type="email" required {...register('email')} error={errors.email?.message} />
        <Input label="Phone (optional)" type="tel" {...register('phone')} error={errors.phone?.message} />
        <Input label="Subject" required {...register('subject')} error={errors.subject?.message} />
        <Textarea wrapClassName="sm:col-span-2" label="Message" required rows={6} {...register('message')} error={errors.message?.message} />
        <input type="text" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden {...register('website')} />
        <div className="sm:col-span-2"><Button type="submit" size="lg" loading={isSubmitting}>Send message</Button></div>
      </form>
    </div>
  );
}

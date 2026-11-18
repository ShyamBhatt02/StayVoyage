import type { ReactNode } from 'react';

export function AuthShell({ title, subtitle, children, image = '/storage/seed/destinations/udaipur-palace.jpg' }: { title: string; subtitle?: ReactNode; children: ReactNode; image?: string }) {
  return (
    <div className="grid min-h-[calc(100vh-72px)] lg:grid-cols-2">
      <div className="flex items-center justify-center px-4 py-12 sm:px-8">
        <div className="w-full max-w-md animate-fade-up">
          <h1 className="font-display text-3xl font-semibold text-ink-900">{title}</h1>
          {subtitle && <p className="mt-2 text-ink-600">{subtitle}</p>}
          <div className="mt-8">{children}</div>
        </div>
      </div>
      <div className="relative hidden overflow-hidden lg:block">
        <img src={image} alt="" className="absolute inset-0 h-full w-full object-cover" />
        <div className="absolute inset-0 bg-gradient-to-t from-brand-950/80 via-brand-950/20 to-transparent" />
        <div className="absolute bottom-0 p-12 text-white">
          <p className="font-display text-3xl font-semibold leading-snug">“Thirty nights a month changed how we travel. We just go.”</p>
          <p className="mt-3 text-brand-100">— StayVoyage member since 2025</p>
        </div>
      </div>
    </div>
  );
}

export const passwordRules = [
  { test: (v: string) => v.length >= 8, label: 'At least 8 characters' },
  { test: (v: string) => /[a-z]/.test(v) && /[A-Z]/.test(v), label: 'Upper & lower case letters' },
  { test: (v: string) => /\d/.test(v), label: 'A number' },
  { test: (v: string) => /[^A-Za-z0-9]/.test(v), label: 'A symbol (e.g. @ # !)' },
];

export function PasswordChecklist({ value }: { value: string }) {
  return (
    <ul className="mt-2 grid grid-cols-2 gap-1 text-xs" aria-label="Password requirements">
      {passwordRules.map((r) => (
        <li key={r.label} className={r.test(value) ? 'font-semibold text-success-700' : 'text-ink-500'}>{r.test(value) ? '✓' : '○'} {r.label}</li>
      ))}
    </ul>
  );
}

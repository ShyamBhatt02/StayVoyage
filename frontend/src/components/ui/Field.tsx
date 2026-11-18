import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/format';

interface FieldWrapProps {
  label?: ReactNode;
  error?: string;
  hint?: ReactNode;
  required?: boolean;
  className?: string;
  children: (id: string, describedBy?: string) => ReactNode;
}

export function FieldWrap({ label, error, hint, required, className, children }: FieldWrapProps) {
  const id = useId();
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;
  return (
    <div className={className}>
      {label && (
        <label htmlFor={id} className="label">
          {label}
          {required && <span className="ml-0.5 text-coral-600" aria-hidden>*</span>}
        </label>
      )}
      {children(id, describedBy)}
      {error ? (
        <p id={`${id}-error`} role="alert" className="mt-1.5 text-xs font-medium text-danger-600">{error}</p>
      ) : hint ? (
        <p id={`${id}-hint`} className="mt-1.5 text-xs text-ink-500">{hint}</p>
      ) : null}
    </div>
  );
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & { label?: ReactNode; error?: string; hint?: ReactNode; wrapClassName?: string; leading?: ReactNode };

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input({ label, error, hint, wrapClassName, className, required, leading, ...rest }, ref) {
  return (
    <FieldWrap label={label} error={error} hint={hint} required={required} className={wrapClassName}>
      {(id, describedBy) => (
        <div className="relative">
          {leading && <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">{leading}</span>}
          <input
            ref={ref}
            id={id}
            aria-invalid={!!error || undefined}
            aria-describedby={describedBy}
            aria-required={required || undefined}
            className={cn('input', leading && 'pl-10', error && 'input-error', className)}
            {...rest}
          />
        </div>
      )}
    </FieldWrap>
  );
});

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & { label?: ReactNode; error?: string; hint?: ReactNode; wrapClassName?: string };

export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select({ label, error, hint, wrapClassName, className, required, children, ...rest }, ref) {
  return (
    <FieldWrap label={label} error={error} hint={hint} required={required} className={wrapClassName}>
      {(id, describedBy) => (
        <select ref={ref} id={id} aria-invalid={!!error || undefined} aria-describedby={describedBy} className={cn('input pr-9', error && 'input-error', className)} {...rest}>
          {children}
        </select>
      )}
    </FieldWrap>
  );
});

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { label?: ReactNode; error?: string; hint?: ReactNode; wrapClassName?: string };

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea({ label, error, hint, wrapClassName, className, required, ...rest }, ref) {
  return (
    <FieldWrap label={label} error={error} hint={hint} required={required} className={wrapClassName}>
      {(id, describedBy) => (
        <textarea ref={ref} id={id} rows={4} aria-invalid={!!error || undefined} aria-describedby={describedBy} className={cn('input resize-y', error && 'input-error', className)} {...rest} />
      )}
    </FieldWrap>
  );
});

export const Checkbox = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { label: ReactNode; error?: string }>(function Checkbox({ label, error, className, ...rest }, ref) {
  const id = useId();
  return (
    <div className={className}>
      <label htmlFor={id} className="flex cursor-pointer items-start gap-2.5 text-sm text-ink-700">
        <input ref={ref} id={id} type="checkbox" className="mt-0.5 h-4.5 w-4.5 h-[18px] w-[18px] shrink-0 cursor-pointer rounded border-ink-300 accent-brand-600" aria-invalid={!!error || undefined} {...rest} />
        <span>{label}</span>
      </label>
      {error && <p role="alert" className="mt-1 text-xs font-medium text-danger-600">{error}</p>}
    </div>
  );
});

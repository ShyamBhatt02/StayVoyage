/** @type {import('tailwindcss').Config} */
// StayVoyage design system — every colour in the app comes from these tokens (CSS variables in src/index.css).
const scale = (name) =>
  Object.fromEntries(
    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((s) => [s, `rgb(var(--${name}-${s}) / <alpha-value>)`]),
  );

export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: scale('brand'), // Lagoon teal — primary
        coral: scale('coral'), // Sunset coral — secondary / CTAs
        sand: scale('sand'), // Sand gold — accent (ratings, membership)
        ink: scale('ink'), // Neutrals
        success: { 50: 'rgb(var(--success-50) / <alpha-value>)', 600: 'rgb(var(--success-600) / <alpha-value>)', 700: 'rgb(var(--success-700) / <alpha-value>)' },
        warning: { 50: 'rgb(var(--warning-50) / <alpha-value>)', 600: 'rgb(var(--warning-600) / <alpha-value>)', 700: 'rgb(var(--warning-700) / <alpha-value>)' },
        danger: { 50: 'rgb(var(--danger-50) / <alpha-value>)', 600: 'rgb(var(--danger-600) / <alpha-value>)', 700: 'rgb(var(--danger-700) / <alpha-value>)' },
        info: { 50: 'rgb(var(--info-50) / <alpha-value>)', 600: 'rgb(var(--info-600) / <alpha-value>)', 700: 'rgb(var(--info-700) / <alpha-value>)' },
      },
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['Fraunces', 'Georgia', 'serif'],
      },
      boxShadow: {
        soft: '0 1px 2px rgb(15 19 24 / 0.04), 0 6px 24px -6px rgb(15 19 24 / 0.10)',
        lift: '0 2px 4px rgb(15 19 24 / 0.05), 0 18px 40px -12px rgb(14 85 99 / 0.28)',
        glow: '0 0 0 4px rgb(var(--brand-500) / 0.18)',
      },
      borderRadius: { xl: '0.875rem', '2xl': '1.25rem', '3xl': '1.75rem' },
      keyframes: {
        'fade-up': { '0%': { opacity: 0, transform: 'translateY(10px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
        shimmer: { '100%': { transform: 'translateX(100%)' } },
        'scale-in': { '0%': { opacity: 0, transform: 'scale(.96)' }, '100%': { opacity: 1, transform: 'scale(1)' } },
      },
      animation: {
        'fade-up': 'fade-up .5s cubic-bezier(.2,.8,.2,1) both',
        'scale-in': 'scale-in .18s ease-out both',
      },
    },
  },
  plugins: [],
};

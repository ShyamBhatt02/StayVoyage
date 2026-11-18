import { BedDouble, Bus, Car, CarTaxiFront, Compass, Landmark, Plane, PlaneLanding, StickyNote, Train, UserRound, Utensils, type LucideIcon } from 'lucide-react';
import { money } from '@/lib/format';
import type { Trip } from '@/types';

export const ITEM_META: Record<string, { label: string; icon: LucideIcon; tone: string; kind: string; transportType?: string }> = {
  accommodation: { label: 'Hotel', icon: BedDouble, tone: 'bg-brand-50 text-brand-700', kind: 'hotels' },
  flight: { label: 'Flight', icon: Plane, tone: 'bg-info-50 text-info-700', kind: 'transportation', transportType: 'flight' },
  train: { label: 'Train', icon: Train, tone: 'bg-info-50 text-info-700', kind: 'transportation', transportType: 'train' },
  bus: { label: 'Bus', icon: Bus, tone: 'bg-info-50 text-info-700', kind: 'transportation', transportType: 'bus' },
  taxi: { label: 'Taxi', icon: CarTaxiFront, tone: 'bg-info-50 text-info-700', kind: 'transportation', transportType: 'taxi' },
  private_car: { label: 'Private car', icon: Car, tone: 'bg-info-50 text-info-700', kind: 'transportation', transportType: 'private_car' },
  rental_car: { label: 'Rental car', icon: Car, tone: 'bg-info-50 text-info-700', kind: 'transportation', transportType: 'rental_car' },
  airport_transfer: { label: 'Airport transfer', icon: PlaneLanding, tone: 'bg-sand-50 text-sand-800', kind: 'transportation', transportType: 'airport_transfer' },
  hotel_transfer: { label: 'Hotel transfer', icon: Car, tone: 'bg-sand-50 text-sand-800', kind: 'transportation', transportType: 'hotel_transfer' },
  activity: { label: 'Activity', icon: Compass, tone: 'bg-coral-50 text-coral-700', kind: 'activities' },
  attraction: { label: 'Attraction', icon: Landmark, tone: 'bg-coral-50 text-coral-700', kind: 'activities' },
  guide: { label: 'Guide', icon: UserRound, tone: 'bg-success-50 text-success-700', kind: 'guides' },
  meal: { label: 'Meal', icon: Utensils, tone: 'bg-warning-50 text-warning-700', kind: 'meals' },
  other: { label: 'Note / free time', icon: StickyNote, tone: 'bg-ink-100 text-ink-700', kind: 'none' },
};

export const GROUP_LABELS: Record<string, string> = {
  transportation: 'Transportation', accommodation: 'Accommodation', activities: 'Activities & attractions', guides: 'Guides',
  meals: 'Meals', transfers: 'Transfers', other: 'Other services',
};

export function PriceSummary({ trip, compact }: { trip: Trip; compact?: boolean }) {
  const groups = trip.price_breakdown?.groups ?? {};
  return (
    <div>
      {!compact && (
        <dl className="space-y-2 text-sm">
          {Object.entries(GROUP_LABELS).map(([k, label]) => (groups[k] ?? 0) > 0 && (
            <div key={k} className="flex justify-between"><dt className="text-ink-600">{label}</dt><dd className="font-semibold tabular-nums">{money(groups[k] ?? 0)}</dd></div>
          ))}
          <div className="flex justify-between border-t border-ink-100 pt-2"><dt className="text-ink-600">Subtotal</dt><dd className="font-semibold tabular-nums">{money(trip.subtotal)}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-600">Taxes ({trip.price_breakdown?.tax_percent ?? 0}% GST)</dt><dd className="tabular-nums">{money(trip.tax_amount)}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-600">Service fee ({trip.price_breakdown?.service_fee_percent ?? 0}%)</dt><dd className="tabular-nums">{money(trip.service_fee)}</dd></div>
        </dl>
      )}
      <div className={compact ? '' : 'mt-3 border-t border-ink-200 pt-3'}>
        <div className="flex items-end justify-between">
          <span className="text-sm font-semibold text-ink-700">Trip total</span>
          <span className="text-2xl font-bold text-ink-900 tabular-nums">{money(trip.total_amount)}</span>
        </div>
        <p className="text-right text-xs text-ink-500">{money(trip.price_breakdown?.per_person ?? 0)} per person · {trip.travelers} traveller{trip.travelers === 1 ? '' : 's'}</p>
      </div>
    </div>
  );
}

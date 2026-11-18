export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  avatar_url: string | null;
  status: 'active' | 'suspended';
  email_verified: boolean;
  roles?: string[];
  is_staff?: boolean;
  is_partner?: boolean;
  permissions?: string[];
  two_factor_enabled?: boolean;
  last_login_at?: string | null;
  created_at?: string;
}

export interface Amenity { id: number; name: string; slug: string; icon: string | null; scope?: string }

export interface Destination {
  id: number;
  name: string;
  slug: string;
  country: string;
  state: string | null;
  tagline: string | null;
  description: string | null;
  image_url: string | null;
  gallery: string[];
  highlights: string[];
  best_time_to_visit: string | null;
  latitude: number | null;
  longitude: number | null;
  is_featured: boolean;
  hotels_count?: number;
  packages_count?: number;
  from_price?: number;
  status?: string;
  sort_order?: number;
  meta_title?: string | null;
  meta_description?: string | null;
}

export interface HotelCard {
  id: number;
  slug: string;
  name: string;
  property_type: string;
  city: string;
  state: string | null;
  country: string;
  destination?: { name: string; slug: string };
  star_rating: number;
  rating_avg: number;
  review_count: number;
  cover_url: string | null;
  short_description: string | null;
  amenities?: Amenity[];
  distance_from_center_km: number | null;
  from_price: number;
  is_membership_eligible: boolean;
  is_featured: boolean;
  breakfast_available?: boolean | null;
  free_cancellation_available?: boolean | null;
  membership_rooms?: number | null;
  rooms_left?: number | null;
  latitude: number | null;
  longitude: number | null;
}

export interface Room {
  id: number;
  hotel_id: number;
  name: string;
  room_type: string;
  description: string | null;
  max_guests: number;
  max_adults: number;
  max_children: number;
  bed_type: string;
  size_sqft: number | null;
  base_price: number;
  weekend_price: number | null;
  breakfast_included: boolean;
  free_cancellation: boolean;
  free_cancellation_hours: number;
  cancellation_policy: string | null;
  is_membership_eligible: boolean;
  images?: { id: number; url: string; caption: string | null }[];
  amenities?: Amenity[];
  available_count?: number;
  stay_price?: number;
  nightly_rates?: Record<string, number>;
  total_rooms?: number;
  status?: string;
  sort_order?: number;
  seasonal_prices?: { id: number; name: string; start_date: string; end_date: string; price: number; weekend_price: number | null }[];
}

export interface Hotel extends Omit<HotelCard, 'destination' | 'amenities'> {
  description: string | null;
  address: string;
  postal_code: string | null;
  check_in_time: string;
  check_out_time: string;
  phone: string | null;
  email: string | null;
  destination?: { id: number; name: string; slug: string } | null;
  images?: { id: number; url: string; caption: string | null; is_cover: boolean }[];
  amenities?: Amenity[];
  policy?: {
    cancellation_policy: string | null;
    children_policy: string | null;
    pet_policy: string | null;
    smoking_policy: string | null;
    extra_policies: string | null;
    pets_allowed: boolean;
    smoking_allowed: boolean;
  } | null;
  rooms?: Room[];
  rating_breakdown?: { cleanliness: number; location: number; service: number; value: number } | null;
  status?: string;
  meta_title?: string | null;
  meta_description?: string | null;
  partners?: { id: number; name: string; email: string }[];
  rooms_count?: number;
}

export interface Review {
  id: number;
  overall: number;
  cleanliness: number;
  location: number;
  service: number;
  value: number;
  title: string;
  comment: string;
  images: string[];
  status?: string;
  moderation_note?: string | null;
  author?: { name: string; avatar_url: string | null; email?: string };
  hotel?: { id: number; name: string; slug: string; city: string };
  stay_month?: string;
  created_at: string;
}

export interface MembershipPlan {
  id: number;
  name: string;
  slug: string;
  tagline: string | null;
  description: string | null;
  price: number;
  currency: string;
  duration_days: number;
  included_nights: number;
  price_per_night: number | null;
  max_nights_per_booking: number;
  max_rooms_per_booking: number;
  max_guests_per_booking: number;
  min_advance_booking_days: number;
  max_advance_booking_days: number;
  all_hotels_eligible: boolean;
  eligible_room_types: string[] | null;
  cancellation_deadline_hours: number;
  late_cancellation_policy: string;
  no_show_policy: string;
  unused_nights_expire: boolean;
  nights_extend_past_expiry: boolean;
  auto_renew_available: boolean;
  charge_taxes_on_covered_amount: boolean;
  features: string[];
  is_featured: boolean;
  status: string;
  sort_order?: number;
  eligible_hotel_ids?: number[];
  blackout_dates?: { id: number; start_date: string; end_date: string; reason: string | null }[];
  active_members?: number;
}

export interface Membership {
  id: number;
  membership_number: string;
  plan_id: number;
  plan_name: string | null;
  status: 'pending' | 'active' | 'expired' | 'cancelled';
  status_label: string;
  is_usable: boolean;
  starts_at: string | null;
  expires_at: string | null;
  days_remaining: number;
  total_nights: number;
  used_nights: number;
  remaining_nights: number;
  price_paid: number;
  currency: string;
  auto_renew: boolean;
  rules: Record<string, unknown>;
  activated_at: string | null;
  created_at: string;
  customer?: { id: number; name: string; email: string };
}

export interface MembershipTransaction {
  id: number;
  membership_id: number;
  type: string;
  type_label: string;
  nights: number;
  balance_before: number;
  balance_after: number;
  booking_number?: string | null;
  description: string;
  created_by?: string | null;
  created_at: string;
}

export interface Payment {
  id: number;
  reference: string;
  purpose: string;
  gateway: string;
  gateway_payment_id: string | null;
  method: string | null;
  amount: number;
  amount_refunded: number;
  currency: string;
  status: string;
  status_label: string;
  failure_reason: string | null;
  paid_at: string | null;
  created_at: string;
  payable_type: string;
  payable_reference?: string | null;
  customer?: { id: number; name: string; email: string };
}

export interface Booking {
  id: number;
  booking_number: string;
  type: 'hotel' | 'tour';
  booking_type: 'membership' | 'regular';
  status: 'pending' | 'payment_pending' | 'confirmed' | 'cancelled' | 'completed' | 'failed';
  status_label: string;
  payment_status: string;
  payment_status_label: string;
  check_in: string | null;
  check_out: string | null;
  nights: number;
  rooms_count: number;
  adults: number;
  children: number;
  currency: string;
  room_amount: number;
  membership_nights_used: number;
  membership_covered_amount: number;
  subtotal: number;
  coupon_code: string | null;
  discount_amount: number;
  tax_amount: number;
  service_fee: number;
  total_amount: number;
  amount_paid: number;
  amount_refunded: number;
  amount_due: number;
  hold_expires_at: string | null;
  confirmed_at: string | null;
  cancelled_at: string | null;
  created_at: string;
  hotel?: {
    id: number; name: string; slug: string; city: string; address: string; star_rating: number; cover_url: string | null;
    check_in_time: string; check_out_time: string; phone: string | null; latitude: number | null; longitude: number | null;
  } | null;
  trip?: { id: number; title: string | null; destination_summary: string | null; cover_url: string | null };
  rooms?: {
    id: number; hotel_room_id: number; room_name: string; hotel_name: string; check_in: string; check_out: string; quantity: number;
    nights: number; nightly_rates: Record<string, number>; amount: number; image_url: string | null; bed_type?: string; breakfast_included?: boolean;
  }[];
  guests?: { id: number; full_name: string; guest_type: string; age: number | null; email: string | null; phone: string | null; is_primary: boolean }[];
  contact?: { name: string; email: string; phone: string };
  special_requests?: string | null;
  price_breakdown?: Record<string, unknown> | null;
  itinerary?: TripSnapshot | null;
  payments?: Payment[];
  cancellation?: {
    reason: string | null; within_free_window: boolean; penalty_amount: number; refund_amount: number;
    nights_returned: number; nights_forfeited: number; cancelled_by_role: string; created_at: string;
  } | null;
  refunds?: { reference: string; amount: number; status: string; reason: string | null; processed_at: string | null; created_at: string }[];
  review?: { id: number; status: string; overall: number } | null;
  can_cancel?: boolean;
  can_pay: boolean;
  can_review: boolean;
  has_invoice: boolean;
  customer?: { id: number; name: string; email: string } | null;
}

export interface TripSnapshot {
  title: string;
  destination_summary: string | null;
  start_date: string | null;
  adults: number;
  children: number;
  days: {
    day_number: number;
    date: string | null;
    title: string | null;
    items: {
      type: string; title: string; provider: string | null; from: string | null; to: string | null; start_time: string | null;
      quantity: number; nights: number | null; hours: number | null; meal_plan: string | null; notes: string | null; total_price: number;
    }[];
  }[];
}

export interface TripItem {
  id: number | null;
  item_type: string;
  group: string;
  itemable_id: number | null;
  title: string;
  provider: string | null;
  from_location: string | null;
  to_location: string | null;
  start_time: string | null;
  duration_minutes: number | null;
  quantity: number;
  nights: number | null;
  hours: number | null;
  meal_plan: string | null;
  notes: string | null;
  unit_price: number;
  total_price: number;
  is_available: boolean;
  availability_note: string | null;
  sort_order: number;
  image_url: string | null;
  pricing_unit: string | null;
  hotel_slug: string | null;
}

export interface TripDay {
  id: number;
  day_number: number;
  date: string | null;
  title: string | null;
  notes: string | null;
  destination_id: number | null;
  items: TripItem[];
}

export interface Trip {
  id: number | null;
  title: string;
  destination_summary: string | null;
  start_date: string | null;
  end_date: string | null;
  duration_days: number;
  adults: number;
  children: number;
  travelers: number;
  status: 'draft' | 'booked' | 'cancelled';
  visibility: 'private' | 'public';
  cover_url: string | null;
  currency: string;
  subtotal: number;
  tax_amount: number;
  service_fee: number;
  total_amount: number;
  price_breakdown: {
    groups?: Record<string, number>;
    tax_percent?: number;
    service_fee_percent?: number;
    travelers?: number;
    per_person?: number;
    unavailable_items?: number;
  } | null;
  priced_at: string | null;
  package?: { id: number; name: string; slug: string } | null;
  days: TripDay[];
  items_count: number;
  notes?: string | null;
  share_token?: string | null;
  share_url?: string | null;
  travelers_list?: { id: number; full_name: string; traveler_type: string; age: number | null }[];
  booking?: { booking_number: string; status: string } | null;
  owner_first_name?: string;
  created_at?: string;
  updated_at?: string;
}

export interface TourPackage {
  id: number;
  name: string;
  slug: string;
  summary: string;
  description: string | null;
  duration_days: number;
  duration_nights: number;
  cities: string | null;
  default_adults: number;
  max_travelers: number;
  price_from: number;
  image_url: string | null;
  gallery: string[];
  highlights: string[];
  inclusions: string[];
  exclusions: string[];
  difficulty: string;
  rating_avg: number;
  review_count: number;
  is_featured: boolean;
  status?: string;
  destination?: { id: number; name: string; slug: string; country: string } | null;
  days?: {
    day_number: number;
    title: string;
    items: {
      id: number; item_type: string; itemable_type: string | null; itemable_id: number | null; title: string; description: string | null;
      start_time: string | null; nights: number | null; hours: number | null; from_location: string | null; to_location: string | null;
    }[];
  }[];
  meta_title?: string | null;
  meta_description?: string | null;
}

export interface CatalogueItem {
  id: number;
  kind: 'activity' | 'guide' | 'transportation' | 'meal' | 'hotel_room';
  name: string;
  image_url: string | null;
  destination_id: number | null;
  destination?: string | null;
  status?: string;
  price: number;
  price_label: string;
  [k: string]: unknown;
}

export interface Coupon {
  id: number;
  code: string;
  title: string;
  description: string | null;
  discount_type: 'percentage' | 'fixed';
  discount_value: number;
  min_purchase: number;
  max_discount: number | null;
  starts_at: string | null;
  ends_at: string | null;
  applies_to: string;
  image_url: string | null;
  is_public: boolean;
  usage_limit?: number | null;
  per_user_limit: number;
  used_count?: number;
  status?: string;
  hotel_ids?: number[];
  tour_package_ids?: number[];
  membership_plan_ids?: number[];
}

export interface AppNotification {
  id: string;
  title: string;
  message: string;
  category: string;
  action_text?: string | null;
  action_path: string | null;
  read_at: string | null;
  created_at: string;
}

export interface Checkout {
  type: 'sandbox' | 'razorpay' | 'stripe';
  reference: string;
  gateway: string;
  order_id?: string;
  amount?: number;
  currency?: string;
  path?: string;
  checkout_url?: string;
  key?: string;
  name?: string;
  description?: string;
  prefill?: { name?: string; email?: string; contact?: string };
}

export interface HotelQuote {
  hotel_id: number;
  hotel_room_id: number;
  booking_type: 'membership' | 'regular';
  check_in: string;
  check_out: string;
  nights: number;
  rooms: number;
  guests: number;
  currency: string;
  nightly_rates: Record<string, number>;
  room_amount: number;
  membership_nights_used: number;
  membership_covered_amount: number;
  subtotal: number;
  discount_amount: number;
  coupon_code: string | null;
  tax_percent: number;
  tax_amount: number;
  service_fee: number;
  total_amount: number;
  membership: { id: number; balance_before: number; balance_after: number; expires_at: string } | null;
  line_items: { label: string; amount: number }[];
  available_count: number;
  is_available: boolean;
  display?: {
    hotel_name: string; hotel_slug: string; city: string; cover_url: string | null; room_name: string; bed_type: string;
    breakfast_included: boolean; image_url: string | null; cancellation_policy: string | null; check_in_time: string;
  };
}

export interface SiteConfig {
  settings: Record<string, string | number | boolean>;
  amenities: Amenity[];
  property_types: string[];
  room_types: string[];
  price_range: { min: number; max: number };
  payment_gateways: { name: string; label: string; default: boolean }[];
  google_maps_key: string | null;
}

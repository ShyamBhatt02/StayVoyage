<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Destination;
use App\Models\Hotel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class HotelSeeder extends Seeder
{
    /** Room image pools by room type (files in seed/rooms). */
    private const ROOM_IMAGES = [
        'standard' => ['twin-room', 'desk-room', 'minimal-bedroom', 'bed-closeup', 'grey-bedroom'],
        'deluxe' => ['warm-room', 'modern-room', 'view-room', 'tufted-bed', 'colorful-bedroom', 'dark-bedroom', 'city-view'],
        'premium' => ['view-room', 'city-view', 'modern-room', 'tufted-bed', 'dark-bedroom'],
        'suite' => ['luxury-suite', 'modern-suite', 'living-suite', 'white-luxe', 'classic-sofa'],
        'family' => ['twin-room', 'living-suite', 'colorful-bedroom'],
        'villa' => ['mountain-deck-room', 'wood-suite', 'luxury-suite', 'white-luxe'],
    ];

    public function run(): void
    {
        $amenityIds = Amenity::query()->pluck('id', 'slug');
        $dest = Destination::query()->pluck('id', 'name');

        foreach ($this->hotels() as $i => $h) {
            $slug = Str::slug($h['name'].' '.$h['city']);
            $hotel = Hotel::query()->updateOrCreate(['slug' => $slug], [
                'destination_id' => $dest[$h['destination']],
                'name' => $h['name'],
                'property_type' => $h['type'],
                'star_rating' => $h['stars'],
                'short_description' => $h['short'],
                'description' => $h['description'],
                'address' => $h['address'],
                'city' => $h['city'],
                'state' => $h['state'] ?? null,
                'country' => $h['country'],
                'postal_code' => $h['postal'] ?? null,
                'latitude' => $h['lat'],
                'longitude' => $h['lng'],
                'distance_from_center_km' => $h['distance'],
                'check_in_time' => $h['check_in'] ?? '14:00:00',
                'check_out_time' => $h['check_out'] ?? '11:00:00',
                'phone' => $h['phone'],
                'email' => 'reservations@'.Str::slug($h['name']).'.example',
                'is_membership_eligible' => $h['member'],
                'is_featured' => $h['featured'] ?? false,
                'status' => 'active',
                'meta_title' => "{$h['name']} — {$h['stars']}-star {$h['type']} in {$h['city']}",
                'meta_description' => $h['short'],
            ]);

            $hotel->images()->delete();
            foreach ($h['images'] as $j => $img) {
                $hotel->images()->create(['path' => "seed/{$img}.jpg", 'is_cover' => $j === 0, 'sort_order' => $j, 'caption' => null]);
            }

            $hotel->amenities()->sync(collect($h['amenities'])->map(fn ($a) => $amenityIds[$a] ?? null)->filter()->values());

            $hotel->policy()->updateOrCreate(['hotel_id' => $hotel->id], [
                'cancellation_policy' => 'Cancellation terms depend on the room rate you choose and are shown with each room. Free-cancellation rates can be cancelled online until the deadline for a full refund; later cancellations incur a charge.',
                'children_policy' => $h['children_policy'] ?? 'Children of all ages are welcome. Children under 6 stay free when using existing bedding. Extra beds are available on request for selected rooms.',
                'pet_policy' => $h['pets'] ? 'Small pets (under 10 kg) are welcome in selected ground-floor rooms for a cleaning fee of ₹1,500 per stay. Please inform the hotel in advance.' : 'Pets are not allowed, with the exception of assistance animals.',
                'smoking_policy' => 'This is a non-smoking property. Smoking is permitted only in designated outdoor areas; a deep-cleaning fee applies to smoking in rooms.',
                'extra_policies' => 'A valid government photo ID is required for every adult at check-in. International guests must present a passport and valid visa. Early check-in and late check-out are subject to availability.',
                'pets_allowed' => $h['pets'],
                'smoking_allowed' => false,
            ]);

            $hotel->rooms()->withTrashed()->forceDelete();
            foreach ($h['rooms'] as $k => $r) {
                [$name, $roomType, $price, $weekend, $total, $member, $guests, $bed, $size, $breakfast, $freeCancel] = $r;
                $pool = self::ROOM_IMAGES[$roomType];
                $room = $hotel->rooms()->create([
                    'name' => $name,
                    'room_type' => $roomType,
                    'description' => $this->roomDescription($name, $roomType, $h, $bed, $size, $breakfast),
                    'max_guests' => $guests,
                    'max_adults' => min($guests, $roomType === 'family' ? 4 : ($guests >= 4 ? 4 : 2)),
                    'max_children' => $guests > 2 ? $guests - 2 : 1,
                    'bed_type' => $bed,
                    'size_sqft' => $size,
                    'total_rooms' => $total,
                    'base_price' => $price,
                    'weekend_price' => $weekend,
                    'breakfast_included' => $breakfast,
                    'free_cancellation' => $freeCancel,
                    'free_cancellation_hours' => $freeCancel ? ($h['stars'] >= 5 ? 72 : 48) : 0,
                    'cancellation_policy' => $freeCancel
                        ? 'Free cancellation until '.($h['stars'] >= 5 ? 72 : 48).' hours before check-in. After that, 50% of the booking amount is charged.'
                        : 'Non-refundable rate: cancellations are charged 50% of the booking amount.',
                    'is_membership_eligible' => $member && $h['member'],
                    'status' => 'active',
                    'sort_order' => $k,
                ]);
                $first = $pool[($i + $k) % count($pool)];
                $second = $roomType === 'suite' || $roomType === 'villa' ? 'bathroom' : $pool[($i + $k + 2) % count($pool)];
                $room->images()->create(['path' => "seed/rooms/{$first}.jpg", 'sort_order' => 0]);
                $room->images()->create(['path' => "seed/rooms/{$second}.jpg", 'sort_order' => 1]);

                $roomAmenities = ['free-wi-fi', 'air-conditioning', 'smart-tv', 'in-room-safe', 'coffee-tea-maker', 'room-service'];
                if (in_array($roomType, ['deluxe', 'premium', 'suite', 'villa'], true)) {
                    $roomAmenities = array_merge($roomAmenities, ['minibar', 'rain-shower', 'work-desk']);
                }
                if (in_array($roomType, ['suite', 'villa'], true)) {
                    $roomAmenities = array_merge($roomAmenities, ['bathtub', 'private-balcony']);
                }
                if ($roomType === 'villa') {
                    $roomAmenities[] = 'private-pool';
                }
                if (str_contains(strtolower($name), 'sea') || str_contains(strtolower($name), 'ocean') || str_contains(strtolower($name), 'beach')) {
                    $roomAmenities[] = 'sea-view';
                }
                $room->amenities()->sync(collect($roomAmenities)->map(fn ($a) => $amenityIds[$a] ?? null)->filter()->values());

                foreach ($h['seasons'] ?? [] as [$sName, $from, $to, $pct]) {
                    $room->seasonalPrices()->create([
                        'name' => $sName, 'start_date' => $from, 'end_date' => $to,
                        'price' => round($price * (1 + $pct / 100), -2),
                        'weekend_price' => $weekend ? round($weekend * (1 + $pct / 100), -2) : null,
                    ]);
                }
            }

            $hotel->refreshAggregates();
        }
    }

    private function roomDescription(string $name, string $type, array $h, string $bed, int $size, bool $breakfast): string
    {
        $intro = match ($type) {
            'standard' => "A smart, comfortable {$size} sq ft room with a {$bed} bed — ideal for travellers who want a great base for exploring {$h['city']}.",
            'deluxe' => "Our {$name} offers {$size} sq ft of calm, contemporary space with a plush {$bed} bed, blackout curtains and a marble bathroom.",
            'premium' => "Upgraded {$size} sq ft room on a higher floor with a {$bed} bed, a lounge chair by the window and an espresso machine.",
            'suite' => "A {$size} sq ft suite with a separate living area, {$bed} bed, walk-in wardrobe and a deep soaking tub — made for longer, slower stays.",
            'family' => "Spacious {$size} sq ft room for families, with a {$bed} configuration, a sofa bed and plenty of storage for the whole crew.",
            'villa' => "A private {$size} sq ft villa with its own pool deck, {$bed} bed, indoor-outdoor bathroom and complete privacy.",
        };

        return $intro.($breakfast ? ' Daily breakfast for all guests is included in the rate.' : ' Breakfast can be added at the hotel.');
    }

    /** 24 original properties across 13 destinations. */
    private function hotels(): array
    {
        $std = ['free-wi-fi', 'air-conditioning', '24-hour-front-desk', 'room-service', 'laundry-service'];

        return [
            [
                'name' => 'Al Noor Marina Hotel', 'destination' => 'Dubai', 'city' => 'Dubai', 'country' => 'United Arab Emirates', 'type' => 'hotel', 'stars' => 5,
                'address' => 'Al Marsa Street, Dubai Marina', 'postal' => '00000', 'lat' => 25.0805, 'lng' => 55.1403, 'distance' => 2.1, 'phone' => '+971 4 555 0101',
                'short' => 'Sleek five-star tower on Dubai Marina with an infinity pool, sky bar and five minutes\' walk to JBR beach.',
                'description' => "Rising over the yachts of Dubai Marina, Al Noor Marina Hotel pairs floor-to-ceiling views with warm Arabian hospitality. Spend the morning at JBR beach, a short stroll away, then return to the 34th-floor infinity pool as the skyline lights up.\n\nThree restaurants serve everything from Levantine mezze to modern Indian, and the Noor Spa offers hammam rituals after a long day in the city. The Marina tram and metro are at the doorstep for easy trips to Downtown and the Burj Khalifa.",
                'member' => true, 'featured' => true, 'pets' => false,
                'images' => ['hotels/night-pool', 'destinations/dubai-marina', 'rooms/modern-suite', 'hotels/infinity-deck'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'bar-lounge', 'airport-shuttle', 'breakfast-available', 'business-centre']),
                'seasons' => [['Festive peak', '2026-12-18', '2027-01-04', 25]],
                'rooms' => [
                    ['Deluxe King Room', 'deluxe', 11500, 13200, 20, true, 2, 'King', 420, true, true],
                    ['Marina View Premier', 'premium', 14500, 16800, 12, true, 3, 'King', 480, true, true],
                    ['Executive Suite', 'suite', 26000, 29500, 4, false, 3, 'King', 850, true, true],
                ],
            ],
            [
                'name' => 'Desert Pearl Resort & Spa', 'destination' => 'Dubai', 'city' => 'Dubai', 'country' => 'United Arab Emirates', 'type' => 'resort', 'stars' => 5,
                'address' => 'Al Qudra Road, Dubai Desert Conservation Reserve', 'lat' => 24.9857, 'lng' => 55.4093, 'distance' => 38.5, 'phone' => '+971 4 555 0177',
                'short' => 'Palm-shaded desert hideaway with a lagoon pool, sunset camel treks and a celebrated spa.',
                'description' => "Forty minutes from the city, Desert Pearl Resort & Spa is a whitewashed oasis framed by palms and rolling dunes. Mornings start with falconry displays and dune walks; afternoons are for the lagoon pool and the hammam-inspired spa.\n\nAs the sun drops, join a camel trek to the ridge for sunset, then dine under lanterns at Al Majlis. Free shuttles run to Downtown Dubai twice daily.",
                'member' => true, 'featured' => true, 'pets' => false,
                'images' => ['hotels/palm-dusk', 'destinations/desert', 'rooms/luxury-suite', 'hotels/cabanas'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'bar-lounge', 'free-parking', 'kids-club', 'breakfast-available', 'garden']),
                'seasons' => [['Festive peak', '2026-12-18', '2027-01-04', 25]],
                'rooms' => [
                    ['Deluxe Garden Room', 'deluxe', 13800, 15500, 18, true, 2, 'King', 460, true, true],
                    ['Premier Dune View', 'premium', 17500, 19900, 10, true, 3, 'King', 520, true, true],
                    ['Private Pool Villa', 'villa', 32000, 36000, 6, false, 4, 'King', 1400, true, true],
                ],
            ],
            [
                'name' => 'Creekside Boutique Hotel', 'destination' => 'Dubai', 'city' => 'Dubai', 'country' => 'United Arab Emirates', 'type' => 'boutique', 'stars' => 4,
                'address' => 'Baniyas Road, Deira', 'lat' => 25.2667, 'lng' => 55.3082, 'distance' => 1.2, 'phone' => '+971 4 555 0233',
                'short' => 'Characterful 4-star on Dubai Creek, steps from the gold and spice souks and the abra water taxis.',
                'description' => "Creekside Boutique Hotel puts old Dubai on your doorstep. Hop on a one-dirham abra across the creek, haggle in the gold and spice souks, then retreat to bright rooms decorated with local textiles.\n\nThe rooftop café serves Emirati breakfasts with views of passing dhows, and the metro is a five-minute walk for quick trips to the beach and Downtown.",
                'member' => true, 'pets' => false,
                'images' => ['hotels/hotel-sign', 'destinations/dubai-skyline', 'rooms/desk-room', 'rooms/grey-bedroom'],
                'amenities' => array_merge($std, ['restaurant', 'fitness-centre', 'breakfast-available', 'business-centre']),
                'rooms' => [
                    ['Standard Twin Room', 'standard', 6200, 7100, 14, true, 2, 'Twin', 260, true, true],
                    ['Deluxe Creek View', 'deluxe', 7900, 8900, 10, true, 2, 'Queen', 310, true, true],
                ],
            ],
            [
                'name' => 'Corniche Bay Hotel', 'destination' => 'Abu Dhabi', 'city' => 'Abu Dhabi', 'country' => 'United Arab Emirates', 'type' => 'hotel', 'stars' => 5,
                'address' => 'Corniche Road West', 'lat' => 24.4764, 'lng' => 54.3349, 'distance' => 3.0, 'phone' => '+971 2 555 0410',
                'short' => 'Elegant seafront five-star on the Abu Dhabi Corniche with a private beach and a 25-metre pool.',
                'description' => "Overlooking the turquoise Gulf, Corniche Bay Hotel is a calm base for exploring the capital. Walk or cycle the Corniche promenade, visit the Sheikh Zayed Grand Mosque fifteen minutes away, and end the day on the hotel's private beach.\n\nRooms are dressed in sand and pearl tones, and the signature restaurant serves fresh Gulf seafood with a view of the sunset.",
                'member' => false, 'pets' => false,
                'images' => ['hotels/white-pool', 'destinations/abu-dhabi-mosque', 'rooms/white-luxe', 'rooms/living-suite'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'bar-lounge', 'beach-access', 'free-parking', 'breakfast-available']),
                'rooms' => [
                    ['Deluxe Sea View', 'deluxe', 12400, 13900, 16, false, 3, 'King', 450, true, true],
                    ['Corniche Suite', 'suite', 24000, 26500, 4, false, 4, 'King', 900, true, true],
                ],
            ],
            [
                'name' => 'Saadiyat Sands Resort', 'destination' => 'Abu Dhabi', 'city' => 'Abu Dhabi', 'country' => 'United Arab Emirates', 'type' => 'resort', 'stars' => 5,
                'address' => 'Saadiyat Island Cultural District', 'lat' => 24.5405, 'lng' => 54.4339, 'distance' => 9.4, 'phone' => '+971 2 555 0488',
                'short' => 'Beachfront resort on Saadiyat Island with a nature-protected beach, kids club and three pools.',
                'description' => "Nine kilometres of protected white sand make Saadiyat Sands Resort a favourite with families and couples alike. Spot hawksbill turtles nesting in season, paddle-board at sunrise and visit the island's world-class museums.\n\nThree pools, a supervised kids club and a beach bar keep every age happy.",
                'member' => false, 'pets' => false,
                'images' => ['hotels/sea-loungers', 'hotels/infinity-deck', 'rooms/view-room', 'rooms/bathroom'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'bar-lounge', 'beach-access', 'kids-club', 'free-parking', 'breakfast-available']),
                'rooms' => [
                    ['Beachfront Deluxe', 'deluxe', 16500, 18900, 14, false, 3, 'King', 500, true, true],
                    ['Family Suite', 'family', 27000, 29900, 6, false, 5, 'King + 2 Singles', 980, true, true],
                ],
            ],
            [
                'name' => 'Palm Grove Beach Resort', 'destination' => 'Goa', 'city' => 'Candolim', 'state' => 'Goa', 'country' => 'India', 'postal' => '403515', 'type' => 'resort', 'stars' => 4,
                'lat' => 15.5180, 'lng' => 73.7626, 'distance' => 0.4, 'address' => 'Fort Aguada Road, Candolim', 'phone' => '+91 832 248 0101',
                'short' => 'Laid-back 4-star resort set in coconut groves, 200 metres from Candolim beach, with a lagoon pool.',
                'description' => "Palm Grove Beach Resort is classic North Goa: tall coconut palms, a winding lagoon pool and Candolim beach just a short barefoot walk away. Spend lazy afternoons in a hammock, then head out to the shacks for grilled kingfish and sunset.\n\nThe resort's Goan kitchen serves xacuti, vindaloo and fresh bebinca, and the team can arrange river cruises, spice-farm visits and scooter hire.",
                'member' => true, 'featured' => true, 'pets' => true,
                'images' => ['hotels/palm-resort', 'destinations/goa-beach', 'rooms/warm-room', 'hotels/tropical-pool'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'restaurant', 'bar-lounge', 'beach-access', 'free-parking', 'kids-club', 'breakfast-available', 'pet-friendly', 'garden']),
                'seasons' => [['Christmas & New Year', '2026-12-20', '2027-01-04', 35]],
                'rooms' => [
                    ['Deluxe Garden Room', 'deluxe', 6800, 8200, 24, true, 3, 'King', 340, true, true],
                    ['Premium Pool View', 'premium', 8900, 10500, 12, true, 3, 'King', 380, true, true],
                    ['Family Suite', 'family', 13500, 15500, 5, false, 5, 'King + 2 Singles', 640, true, true],
                ],
            ],
            [
                'name' => 'Casa Azul Boutique Villas', 'destination' => 'Goa', 'city' => 'Assagao', 'state' => 'Goa', 'country' => 'India', 'postal' => '403507', 'type' => 'villa', 'stars' => 4,
                'lat' => 15.5960, 'lng' => 73.7680, 'distance' => 3.5, 'address' => 'Mapusa–Anjuna Road, Assagao', 'phone' => '+91 832 226 0450',
                'short' => 'Restored Portuguese villas with private plunge pools in leafy Assagao, minutes from Anjuna and Vagator.',
                'description' => "Casa Azul is a cluster of restored Indo-Portuguese villas with cobalt shutters, terracotta floors and gardens full of bougainvillea. Each villa has its own sit-out, and most have a private plunge pool.\n\nAssagao's acclaimed cafés and the beaches of Anjuna and Vagator are a ten-minute drive away. Breakfast is served on your verandah.",
                'member' => true, 'pets' => true,
                'images' => ['hotels/modern-villa', 'rooms/colorful-bedroom', 'destinations/goa-huts', 'rooms/minimal-bedroom'],
                'amenities' => array_merge($std, ['swimming-pool', 'restaurant', 'free-parking', 'breakfast-available', 'pet-friendly', 'garden']),
                'seasons' => [['Christmas & New Year', '2026-12-20', '2027-01-04', 40]],
                'rooms' => [
                    ['Studio Villa', 'deluxe', 7400, 8600, 8, true, 2, 'Queen', 450, true, true],
                    ['Two-bedroom Pool Villa', 'villa', 15800, 17900, 4, false, 4, 'King + Queen', 1300, true, false],
                ],
            ],
            [
                'name' => 'Sunset Shack Hotel', 'destination' => 'Goa', 'city' => 'Palolem', 'state' => 'Goa', 'country' => 'India', 'postal' => '403702', 'type' => 'hotel', 'stars' => 3,
                'lat' => 15.0100, 'lng' => 74.0232, 'distance' => 0.1, 'address' => 'Palolem Beach Road, Canacona', 'phone' => '+91 832 264 3311',
                'short' => 'Breezy beachfront cottages right on Palolem\'s crescent bay — wake up to the sound of waves.',
                'description' => "On South Goa's prettiest crescent bay, Sunset Shack Hotel offers simple, spotless cottages a few steps from the sand. Kayak to Butterfly Beach, join a silent-disco night or just watch the fishing boats come in.\n\nThe beach café serves fresh juices, Goan fish thali and wood-fired pizzas until late.",
                'member' => true, 'pets' => false,
                'images' => ['destinations/goa-huts', 'destinations/goa-beach', 'rooms/twin-room', 'rooms/bed-closeup'],
                'amenities' => array_merge($std, ['restaurant', 'beach-access', 'breakfast-available']),
                'seasons' => [['Christmas & New Year', '2026-12-20', '2027-01-04', 35]],
                'rooms' => [
                    ['Beach Hut Standard', 'standard', 3600, 4400, 16, true, 2, 'Double', 220, false, true],
                    ['Sea Facing Cottage', 'deluxe', 4900, 5800, 8, true, 3, 'Queen', 280, true, true],
                ],
            ],
            [
                'name' => 'Haveli Rajputana', 'destination' => 'Jaipur', 'city' => 'Jaipur', 'state' => 'Rajasthan', 'country' => 'India', 'postal' => '302002', 'type' => 'boutique', 'stars' => 5,
                'lat' => 26.9239, 'lng' => 75.8267, 'distance' => 1.0, 'address' => 'Near Hawa Mahal, Badi Chaupar', 'phone' => '+91 141 260 1122', 'check_in' => '12:00:00',
                'short' => 'A 19th-century haveli turned heritage hotel, with painted courtyards and rooftop views of the Pink City.',
                'description' => "Built by a merchant family in 1868, Haveli Rajputana has been lovingly restored with hand-painted frescoes, jharokha windows and a marble courtyard where folk musicians play every evening.\n\nThe Hawa Mahal and the bazaars of the old city are a five-minute walk away. Dine on laal maas and dal baati churma on the rooftop as the forts light up across the hills.",
                'member' => true, 'featured' => true, 'pets' => false,
                'images' => ['destinations/jaipur-hawa-mahal', 'rooms/classic-sofa', 'rooms/tufted-bed', 'hotels/courtyard'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'restaurant', 'free-parking', 'breakfast-available', 'garden']),
                'rooms' => [
                    ['Heritage Deluxe Room', 'deluxe', 9500, 10800, 14, true, 3, 'King', 360, true, true],
                    ['Maharaja Suite', 'suite', 21000, 23500, 3, false, 3, 'King', 760, true, true],
                ],
            ],
            [
                'name' => 'Pink City Residency', 'destination' => 'Jaipur', 'city' => 'Jaipur', 'state' => 'Rajasthan', 'country' => 'India', 'postal' => '302001', 'type' => 'hotel', 'stars' => 3,
                'lat' => 26.9124, 'lng' => 75.7873, 'distance' => 2.6, 'address' => 'MI Road, Near Panch Batti', 'phone' => '+91 141 237 4455',
                'short' => 'Great-value 3-star on MI Road with a rooftop restaurant, close to shopping and the old city gates.',
                'description' => "Pink City Residency is a friendly, central hotel for travellers who want to explore Jaipur without overspending. Rooms are bright and practical, the rooftop restaurant does an excellent Rajasthani thali, and the front desk can book auto-rickshaw tours of the forts.\n\nMI Road's jewellers and the old-city gates are within walking distance.",
                'member' => true, 'pets' => false,
                'images' => ['hotels/atrium', 'destinations/jaipur-fort', 'rooms/twin-room', 'rooms/desk-room'],
                'amenities' => array_merge($std, ['restaurant', 'free-parking', 'breakfast-available', 'business-centre']),
                'rooms' => [
                    ['Standard Double', 'standard', 3200, 3600, 20, true, 2, 'Double', 210, true, true],
                    ['Superior Twin', 'standard', 3900, 4300, 14, true, 3, 'Twin', 240, true, true],
                ],
            ],
            [
                'name' => 'Grand Palace Hotel', 'destination' => 'Udaipur', 'city' => 'Udaipur', 'state' => 'Rajasthan', 'country' => 'India', 'postal' => '313001', 'type' => 'hotel', 'stars' => 5,
                'lat' => 24.5764, 'lng' => 73.6835, 'distance' => 0.8, 'address' => 'Lake Pichola Road, Hanuman Ghat', 'phone' => '+91 294 242 8800',
                'short' => 'Lakeside five-star with palace views, a rooftop pool and daily boat transfers across Lake Pichola.',
                'description' => "Facing the City Palace across the still waters of Lake Pichola, Grand Palace Hotel captures Udaipur's royal romance. Rooms open onto carved balconies, and the rooftop pool looks straight at Jag Mandir.\n\nTake the complimentary sunset boat ride, dine on Mewari cuisine by candlelight, and let the concierge arrange vintage-car tours of the old city.",
                'member' => true, 'featured' => true, 'pets' => false,
                'images' => ['destinations/udaipur-palace', 'hotels/cabanas', 'rooms/luxury-suite', 'rooms/wood-suite'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'bar-lounge', 'free-parking', 'breakfast-available', 'airport-shuttle']),
                'rooms' => [
                    ['Deluxe Lake View', 'deluxe', 6500, 7400, 20, true, 2, 'King', 380, true, true],
                    ['Premier Palace Room', 'premium', 9200, 10400, 10, true, 3, 'King', 450, true, true],
                    ['Royal Suite', 'suite', 18500, 20500, 3, false, 4, 'King', 900, true, true],
                ],
            ],
            [
                'name' => 'Lakeview Pichola Retreat', 'destination' => 'Udaipur', 'city' => 'Udaipur', 'state' => 'Rajasthan', 'country' => 'India', 'postal' => '313001', 'type' => 'boutique', 'stars' => 4,
                'lat' => 24.5790, 'lng' => 73.6800, 'distance' => 1.1, 'address' => 'Gangaur Ghat Marg', 'phone' => '+91 294 242 3366',
                'short' => 'Intimate boutique retreat on Gangaur Ghat with a famous rooftop café overlooking the lake.',
                'description' => "This 18-room retreat sits right on Gangaur Ghat, so you can watch evening aarti from your window. Interiors mix hand-block-printed fabrics with Rajasthani miniatures.\n\nThe rooftop café is one of the best spots in town to watch the sun set over the Aravalli hills.",
                'member' => true, 'pets' => false,
                'images' => ['hotels/cliff-sunset', 'destinations/udaipur-palace', 'rooms/view-room', 'rooms/dark-bedroom'],
                'amenities' => array_merge($std, ['restaurant', 'breakfast-available', 'garden']),
                'rooms' => [
                    ['Deluxe Room', 'deluxe', 5400, 6100, 12, true, 2, 'Queen', 300, true, true],
                    ['Lake Facing Premium', 'premium', 7100, 7900, 8, true, 2, 'King', 340, true, true],
                ],
            ],
            [
                'name' => 'Backwater Bliss Resort', 'destination' => 'Kerala', 'city' => 'Alleppey', 'state' => 'Kerala', 'country' => 'India', 'postal' => '688001', 'type' => 'resort', 'stars' => 4,
                'lat' => 9.4981, 'lng' => 76.3388, 'distance' => 4.2, 'address' => 'Punnamada Lake, Alleppey', 'phone' => '+91 477 223 9900',
                'short' => 'Lakeside cottages on Punnamada backwaters with an Ayurveda centre and private houseboat cruises.',
                'description' => "Backwater Bliss Resort is reached by a short boat ride, setting the pace for unhurried days among coconut groves and canals. Cottages open onto the water; mornings begin with yoga, evenings with kayaking as kingfishers dart past.\n\nThe Ayurveda centre offers traditional therapies, and the kitchen serves Kerala sadya on banana leaves.",
                'member' => true, 'featured' => true, 'pets' => false,
                'images' => ['destinations/kerala-houseboat', 'hotels/tropical-pool', 'rooms/wood-suite', 'destinations/kerala-backwaters'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'restaurant', 'free-parking', 'breakfast-available', 'garden']),
                'rooms' => [
                    ['Lake View Cottage', 'deluxe', 7200, 8100, 14, true, 2, 'King', 400, true, true],
                    ['Pool Villa', 'villa', 14500, 16200, 4, false, 3, 'King', 900, true, true],
                ],
            ],
            [
                'name' => 'Munnar Mist Hills Resort', 'destination' => 'Kerala', 'city' => 'Munnar', 'state' => 'Kerala', 'country' => 'India', 'postal' => '685612', 'type' => 'resort', 'stars' => 4,
                'lat' => 10.0889, 'lng' => 77.0595, 'distance' => 5.5, 'address' => 'Chithirapuram, Munnar', 'phone' => '+91 486 523 1100',
                'short' => 'Hillside resort among tea estates with valley-view balconies, a heated pool and guided plantation walks.',
                'description' => "Perched at 1,600 metres among rolling tea gardens, Munnar Mist Hills Resort wakes up above the clouds. Every room has a balcony facing the valley.\n\nJoin a guided walk through the plantation, taste fresh-picked teas, then warm up by the heated pool or the evening bonfire.",
                'member' => true, 'pets' => true,
                'images' => ['rooms/mountain-deck-room', 'hotels/rooftop-mountain', 'rooms/warm-room', 'rooms/minimal-bedroom'],
                'amenities' => array_merge($std, ['swimming-pool', 'restaurant', 'free-parking', 'breakfast-available', 'mountain-view', 'pet-friendly', 'garden']),
                'rooms' => [
                    ['Valley View Deluxe', 'deluxe', 6100, 7000, 16, true, 3, 'King', 360, true, true],
                    ['Tea Garden Suite', 'suite', 10800, 12100, 5, false, 4, 'King', 680, true, true],
                ],
            ],
            [
                'name' => 'Marine Drive Grand', 'destination' => 'Mumbai', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'country' => 'India', 'postal' => '400020', 'type' => 'hotel', 'stars' => 5,
                'lat' => 18.9432, 'lng' => 72.8236, 'distance' => 1.5, 'address' => 'Netaji Subhash Chandra Bose Road, Marine Drive', 'phone' => '+91 22 6655 0101',
                'short' => 'Art-deco grande dame on Marine Drive with sweeping Queen\'s Necklace views and a rooftop bar.',
                'description' => "Marine Drive Grand blends 1930s art-deco glamour with modern comfort. Sea-view rooms frame the famous Queen's Necklace, and the rooftop bar is one of the city's best places to watch the sun sink into the Arabian Sea.\n\nNariman Point, the Gateway of India and Colaba's galleries are minutes away.",
                'member' => true, 'pets' => false,
                'images' => ['rooms/city-view', 'destinations/mumbai-gateway', 'rooms/modern-suite', 'rooms/bathroom'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'bar-lounge', 'business-centre', 'airport-shuttle', 'breakfast-available', 'ev-charging']),
                'rooms' => [
                    ['Deluxe City View', 'deluxe', 10500, 11800, 20, true, 2, 'King', 380, true, true],
                    ['Sea View Premier', 'premium', 13900, 15500, 12, true, 3, 'King', 430, true, true],
                    ['Presidential Suite', 'suite', 38000, 42000, 2, false, 4, 'King', 1500, true, true],
                ],
            ],
            [
                'name' => 'Bandra Bay Business Hotel', 'destination' => 'Mumbai', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'country' => 'India', 'postal' => '400050', 'type' => 'hotel', 'stars' => 4,
                'lat' => 19.0596, 'lng' => 72.8295, 'distance' => 12.0, 'address' => 'Linking Road, Bandra West', 'phone' => '+91 22 6699 2200',
                'short' => 'Modern 4-star in Bandra close to BKC, with a co-working lounge and the city\'s best cafés nearby.',
                'description' => "Designed for work and play, Bandra Bay Business Hotel sits between the Bandra Kurla Complex and the cafés of Pali Hill. Rooms have ergonomic desks and fast Wi-Fi, and the co-working lounge is open 24 hours.\n\nIn the evening, stroll to Bandstand promenade or explore the street art of Chapel Road.",
                'member' => true, 'pets' => false,
                'images' => ['rooms/modern-room', 'destinations/mumbai-gateway-2', 'rooms/desk-room', 'rooms/grey-bedroom'],
                'amenities' => array_merge($std, ['fitness-centre', 'restaurant', 'business-centre', 'breakfast-available', 'ev-charging']),
                'rooms' => [
                    ['Executive Room', 'deluxe', 6900, 7400, 24, true, 2, 'King', 300, true, true],
                    ['Studio Apartment', 'premium', 8800, 9400, 8, true, 3, 'Queen', 420, false, true],
                ],
            ],
            [
                'name' => 'Mughal Garden Retreat', 'destination' => 'Agra', 'city' => 'Agra', 'state' => 'Uttar Pradesh', 'country' => 'India', 'postal' => '282001', 'type' => 'hotel', 'stars' => 4,
                'lat' => 27.1650, 'lng' => 78.0420, 'distance' => 1.4, 'address' => 'Fatehabad Road, Taj Ganj', 'phone' => '+91 562 223 0707',
                'short' => 'Charbagh-style gardens and a rooftop with Taj Mahal views, 1.5 km from the East Gate.',
                'description' => "Inspired by Mughal pleasure gardens, this retreat wraps around a fountain courtyard planted with roses and cypress. The rooftop terrace offers a direct view of the Taj Mahal's dome — magical at sunrise.\n\nThe hotel runs complimentary electric shuttles to the East Gate so you can be first in line.",
                'member' => true, 'pets' => false,
                'images' => ['hotels/courtyard', 'destinations/agra-taj-2', 'rooms/white-luxe', 'rooms/twin-room'],
                'amenities' => array_merge($std, ['swimming-pool', 'restaurant', 'free-parking', 'breakfast-available', 'garden']),
                'rooms' => [
                    ['Deluxe Garden Room', 'deluxe', 5600, 6200, 18, true, 3, 'King', 330, true, true],
                    ['Taj View Premium', 'premium', 8400, 9200, 8, true, 2, 'King', 370, true, true],
                ],
            ],
            [
                'name' => 'Snow Peak Lodge', 'destination' => 'Manali', 'city' => 'Manali', 'state' => 'Himachal Pradesh', 'country' => 'India', 'postal' => '175131', 'type' => 'homestay', 'stars' => 3,
                'lat' => 32.2484, 'lng' => 77.1793, 'distance' => 2.2, 'address' => 'Old Manali Road', 'phone' => '+91 1902 252 444',
                'short' => 'Cosy family-run lodge in Old Manali with apple-orchard views and home-cooked Himachali meals.',
                'description' => "Snow Peak Lodge is run by the Thakur family, who have welcomed trekkers for three generations. Wood-panelled rooms look out over apple orchards to the snowline, and dinners are hearty bowls of siddu and rajma.\n\nThe lodge arranges paragliding in Solang, guided treks and Royal Enfield rentals.",
                'member' => true, 'pets' => true,
                'images' => ['destinations/manali-snow', 'rooms/warm-room', 'rooms/twin-room', 'rooms/bed-closeup'],
                'amenities' => array_merge($std, ['restaurant', 'free-parking', 'breakfast-available', 'mountain-view', 'pet-friendly']),
                'rooms' => [
                    ['Standard Mountain Room', 'standard', 2900, 3400, 12, true, 2, 'Double', 200, true, true],
                    ['Family Room', 'family', 4600, 5300, 6, true, 4, 'Double + 2 Singles', 320, true, true],
                ],
            ],
            [
                'name' => 'Pine Valley Resort', 'destination' => 'Manali', 'city' => 'Manali', 'state' => 'Himachal Pradesh', 'country' => 'India', 'postal' => '175131', 'type' => 'resort', 'stars' => 4,
                'lat' => 32.2690, 'lng' => 77.1880, 'distance' => 4.5, 'address' => 'Hadimba Road, Dhungri', 'phone' => '+91 1902 254 600',
                'short' => 'Deodar-forest resort with log-cabin suites, a heated indoor pool and Rohtang-facing balconies.',
                'description' => "Set among towering deodar trees near Hadimba Temple, Pine Valley Resort is made for crisp mountain mornings and fireside evenings. Choose a valley-view room or a log-cabin suite with its own fireplace.\n\nThe adventure desk organises river rafting on the Beas, snow trips via the Atal Tunnel and stargazing nights.",
                'member' => true, 'pets' => false,
                'images' => ['hotels/rooftop-mountain', 'destinations/manali-mountains', 'rooms/wood-suite', 'rooms/mountain-deck-room'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'restaurant', 'bar-lounge', 'free-parking', 'breakfast-available', 'mountain-view', 'kids-club']),
                'seasons' => [['Summer peak', '2027-05-01', '2027-06-30', 30]],
                'rooms' => [
                    ['Deluxe Valley View', 'deluxe', 5900, 6800, 14, true, 3, 'King', 340, true, true],
                    ['Log Cabin Suite', 'suite', 9800, 11200, 5, false, 4, 'King', 620, true, true],
                ],
            ],
            [
                'name' => 'Ubud Rainforest Villas', 'destination' => 'Bali', 'city' => 'Ubud', 'country' => 'Indonesia', 'type' => 'villa', 'stars' => 5,
                'lat' => -8.5069, 'lng' => 115.2625, 'distance' => 3.0, 'address' => 'Jalan Raya Sayan, Ubud, Gianyar', 'phone' => '+62 361 555 0909',
                'short' => 'Jungle-clad villas above the Ayung River gorge with private pools and daily yoga.',
                'description' => "Hidden in the rainforest above the Ayung River, these villas feel worlds away yet sit ten minutes from Ubud's galleries and markets. Each villa has a private pool hanging over the jungle.\n\nDays begin with yoga in the open-air shala and end with Balinese massages and candle-lit dinners by the river.",
                'member' => false, 'featured' => true, 'pets' => false,
                'images' => ['hotels/beach-resort-deck', 'destinations/bali-rice', 'rooms/luxury-suite', 'hotels/tropical-pool'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'airport-shuttle', 'breakfast-available', 'garden']),
                'rooms' => [
                    ['Jungle Deluxe', 'deluxe', 11200, 12500, 10, false, 2, 'King', 480, true, true],
                    ['Private Pool Villa', 'villa', 22500, 24900, 6, false, 3, 'King', 1100, true, true],
                ],
            ],
            [
                'name' => 'Seminyak Sunset Resort', 'destination' => 'Bali', 'city' => 'Seminyak', 'country' => 'Indonesia', 'type' => 'resort', 'stars' => 4,
                'lat' => -8.6913, 'lng' => 115.1585, 'distance' => 0.6, 'address' => 'Jalan Kayu Aya, Seminyak', 'phone' => '+62 361 555 0313',
                'short' => 'Beachside resort on Seminyak\'s sunset strip with an infinity deck and surf lessons on the doorstep.',
                'description' => "Seminyak Sunset Resort sits on Bali's liveliest sunset strip. Lounge on the infinity deck, take a surf lesson on the beach out front, then wander to the boutiques and beach clubs along Jalan Kayu Aya.\n\nRooms are airy and tropical, with rain showers and garden or ocean views.",
                'member' => true, 'pets' => false,
                'images' => ['hotels/infinity-deck', 'destinations/bali-temple', 'rooms/view-room', 'hotels/cliff-sunset'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'restaurant', 'bar-lounge', 'beach-access', 'breakfast-available']),
                'rooms' => [
                    ['Deluxe Garden Room', 'deluxe', 7600, 8500, 16, true, 2, 'King', 360, true, true],
                    ['Ocean View Suite', 'suite', 14200, 15800, 5, false, 3, 'King', 720, true, true],
                ],
            ],
            [
                'name' => 'Marina Skyline Hotel', 'destination' => 'Singapore', 'city' => 'Singapore', 'country' => 'Singapore', 'type' => 'hotel', 'stars' => 5,
                'lat' => 1.2834, 'lng' => 103.8607, 'distance' => 0.9, 'address' => 'Raffles Avenue, Marina Bay', 'phone' => '+65 6555 0202',
                'short' => 'Five-star Marina Bay hotel with a rooftop pool, skyline suites and a walk to Gardens by the Bay.',
                'description' => "Marina Skyline Hotel looks across the bay to the city's glittering skyline. The rooftop pool is the place to be at sunset, and Gardens by the Bay and the Esplanade are an easy walk.\n\nCelebrated chefs run the hotel's Cantonese and modern Asian restaurants, and the concierge can book hawker-centre food trails.",
                'member' => false, 'pets' => false,
                'images' => ['destinations/singapore-mbs', 'rooms/modern-suite', 'rooms/city-view', 'rooms/white-luxe'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'bar-lounge', 'business-centre', 'breakfast-available']),
                'rooms' => [
                    ['Deluxe King', 'deluxe', 17800, 19500, 20, false, 2, 'King', 380, true, true],
                    ['Club Suite', 'suite', 31000, 33500, 4, false, 3, 'King', 780, true, true],
                ],
            ],
            [
                'name' => 'Coral Lagoon Overwater Resort', 'destination' => 'Maldives', 'city' => 'South Malé Atoll', 'country' => 'Maldives', 'type' => 'resort', 'stars' => 5,
                'lat' => 3.9700, 'lng' => 73.4300, 'distance' => 28.0, 'address' => 'South Malé Atoll (25 minutes by speedboat from Velana Airport)', 'phone' => '+960 664 0001',
                'children_policy' => 'Children are welcome in Beach Villas. Overwater Villas are suitable for guests aged 12 and over for safety reasons.',
                'short' => 'Barefoot-luxury island with overwater villas, a house reef for snorkelling and sandbank dinners.',
                'description' => "Coral Lagoon is a private island ringed by a vibrant house reef. Overwater villas have glass floor panels and ladders straight into the lagoon, while beach villas open onto powdery sand.\n\nSnorkel with turtles, take a sunset dolphin cruise or book a private dinner on the sandbank. Speedboat transfers from Malé take just 25 minutes.",
                'member' => false, 'featured' => true, 'pets' => false,
                'images' => ['destinations/maldives-villas', 'destinations/maldives-resort', 'hotels/cliffside-pool', 'rooms/white-luxe'],
                'amenities' => array_merge($std, ['swimming-pool', 'spa-wellness', 'fitness-centre', 'restaurant', 'bar-lounge', 'beach-access', 'airport-shuttle', 'breakfast-available']),
                'rooms' => [
                    ['Beach Villa', 'villa', 38000, 41000, 10, false, 3, 'King', 1000, true, true],
                    ['Overwater Villa', 'villa', 52000, 56000, 8, false, 2, 'King', 1150, true, false],
                ],
            ],
            [
                'name' => 'Hôtel Rive Gauche', 'destination' => 'Paris', 'city' => 'Paris', 'country' => 'France', 'postal' => '75007', 'type' => 'boutique', 'stars' => 4,
                'lat' => 48.8584, 'lng' => 2.3050, 'distance' => 2.4, 'address' => 'Rue Saint-Dominique, 7th arrondissement', 'phone' => '+33 1 55 00 07 07', 'check_in' => '15:00:00',
                'short' => 'Classic Parisian boutique hotel on the Left Bank, a 10-minute walk from the Eiffel Tower.',
                'description' => "Behind a Haussmann façade on Rue Saint-Dominique, Hôtel Rive Gauche is all parquet floors, wrought-iron balconies and quiet courtyards. Wake up to croissants from the bakery next door, then walk to the Eiffel Tower and the Seine.\n\nJunior suites on the top floor have views of the tower sparkling each evening.",
                'member' => false, 'pets' => true,
                'images' => ['destinations/paris-bridge', 'destinations/paris-eiffel', 'rooms/classic-sofa', 'rooms/dark-bedroom'],
                'amenities' => array_merge($std, ['bar-lounge', 'breakfast-available', 'pet-friendly']),
                'rooms' => [
                    ['Classic Double', 'deluxe', 16500, 18200, 12, false, 2, 'Queen', 190, false, true],
                    ['Eiffel View Junior Suite', 'suite', 28000, 30500, 4, false, 3, 'King', 340, true, true],
                ],
            ],
        ];
    }
}

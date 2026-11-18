<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Destination;
use App\Models\Faq;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Amenities, destinations and FAQs. */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $amenities = [
            ['Free Wi-Fi', 'wifi', 'both'], ['Swimming Pool', 'waves', 'hotel'], ['Spa & Wellness', 'sparkles', 'hotel'], ['Fitness Centre', 'dumbbell', 'hotel'],
            ['Restaurant', 'utensils', 'hotel'], ['Bar & Lounge', 'wine', 'hotel'], ['Free Parking', 'car', 'hotel'], ['Airport Shuttle', 'plane', 'hotel'],
            ['Room Service', 'bell-ring', 'both'], ['Air Conditioning', 'snowflake', 'both'], ['Beach Access', 'umbrella', 'hotel'], ['Kids Club', 'baby', 'hotel'],
            ['Business Centre', 'briefcase', 'hotel'], ['Pet Friendly', 'paw-print', 'hotel'], ['24-hour Front Desk', 'clock', 'hotel'], ['Laundry Service', 'shirt', 'hotel'],
            ['Breakfast Available', 'coffee', 'hotel'], ['Mountain View', 'mountain', 'both'], ['Garden', 'trees', 'hotel'], ['EV Charging', 'plug-zap', 'hotel'],
            ['Smart TV', 'tv', 'room'], ['Minibar', 'glass-water', 'room'], ['In-room Safe', 'lock', 'room'], ['Bathtub', 'bath', 'room'], ['Private Balcony', 'door-open', 'room'],
            ['Work Desk', 'laptop', 'room'], ['Coffee & Tea Maker', 'cup-soda', 'room'], ['Rain Shower', 'shower-head', 'room'], ['Sea View', 'sunset', 'room'], ['Private Pool', 'waves', 'room'],
        ];
        foreach ($amenities as [$name, $icon, $scope]) {
            Amenity::query()->updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'icon' => $icon, 'scope' => $scope]);
        }

        $destinations = [
            ['Dubai', 'United Arab Emirates', null, 'Skyscrapers, souks and golden dunes', 'dubai-skyline', ['dubai-burj-khalifa', 'dubai-marina', 'dubai-burj-al-arab'], 25.2048, 55.2708,
                'Dubai pairs record-breaking architecture with old-world souks, warm beaches and the vast Arabian desert. Ride to the top of the Burj Khalifa, cruise the Marina by dhow, then trade the city lights for a desert camp under the stars.',
                ['Burj Khalifa observation decks', 'Red-dune desert safaris', 'Dubai Marina & JBR beach', 'Gold & spice souks of Deira'], 'November – March', true],
            ['Abu Dhabi', 'United Arab Emirates', null, 'Grand mosques and calm Gulf beaches', 'abu-dhabi-mosque', ['abu-dhabi-mosque', 'desert'], 24.4539, 54.3773,
                'The UAE\'s capital is calmer and greener than its neighbour, home to the luminous Sheikh Zayed Grand Mosque, the cultural district on Saadiyat Island and long stretches of quiet beach.',
                ['Sheikh Zayed Grand Mosque', 'Saadiyat Island beaches', 'Corniche promenade', 'Desert drives to Liwa'], 'November – March', false],
            ['Goa', 'India', 'Goa', 'Beaches, Portuguese lanes and sunsets', 'goa-beach', ['goa-huts', 'goa-beach'], 15.2993, 74.1240,
                'Goa is India\'s favourite coastal escape — palm-fringed beaches, Portuguese-era churches, spice farms and a laid-back café culture. North Goa brings the buzz; the south keeps things slow and blissful.',
                ['Palolem & Candolim beaches', 'Old Goa churches', 'Fontainhas Latin quarter', 'Seafood shacks & sunset cruises'], 'November – February', true],
            ['Jaipur', 'India', 'Rajasthan', 'The Pink City of palaces and bazaars', 'jaipur-hawa-mahal', ['jaipur-fort', 'jaipur-hawa-mahal'], 26.9124, 75.7873,
                'Rajasthan\'s capital glows in terracotta pink. Explore hilltop Amber Fort, the honeycomb façade of Hawa Mahal, astronomical Jantar Mantar and bazaars overflowing with block prints and gemstones.',
                ['Amber Fort', 'Hawa Mahal', 'City Palace & Jantar Mantar', 'Johari & Bapu bazaars'], 'October – March', true],
            ['Udaipur', 'India', 'Rajasthan', 'Romantic lakes and royal palaces', 'udaipur-palace', ['udaipur-palace'], 24.5854, 73.7125,
                'Often called the Venice of the East, Udaipur rises from the shores of Lake Pichola with marble palaces, rooftop restaurants and sunset boat rides that make it one of India\'s most romantic cities.',
                ['City Palace complex', 'Lake Pichola boat rides', 'Jag Mandir', 'Sajjangarh Monsoon Palace'], 'September – March', true],
            ['Kerala', 'India', 'Kerala', 'Backwaters, tea hills and Ayurveda', 'kerala-backwaters', ['kerala-houseboat', 'kerala-backwaters'], 9.4981, 76.3388,
                'God\'s Own Country is a ribbon of emerald backwaters, misty tea estates and quiet beaches. Drift through Alleppey on a houseboat, wake up to Munnar\'s hills and unwind with traditional Ayurvedic therapies.',
                ['Alleppey houseboat cruises', 'Munnar tea gardens', 'Kathakali performances', 'Ayurveda retreats'], 'September – March', true],
            ['Mumbai', 'India', 'Maharashtra', 'The city that never sleeps', 'mumbai-gateway', ['mumbai-gateway-2'], 19.0760, 72.8777,
                'India\'s financial capital is a whirlwind of colonial landmarks, sea-facing promenades, legendary street food and Bollywood glamour — best enjoyed from Marine Drive at sunset.',
                ['Gateway of India', 'Marine Drive', 'Elephanta Caves', 'Street food in Mohammed Ali Road'], 'October – March', false],
            ['Agra', 'India', 'Uttar Pradesh', 'Home of the Taj Mahal', 'agra-taj', ['agra-taj-2'], 27.1767, 78.0081,
                'Agra is the gateway to the Taj Mahal, the marble monument to love that changes colour from dawn to dusk, along with the mighty Agra Fort and the abandoned Mughal city of Fatehpur Sikri.',
                ['Taj Mahal at sunrise', 'Agra Fort', 'Mehtab Bagh', 'Fatehpur Sikri'], 'October – March', false],
            ['Manali', 'India', 'Himachal Pradesh', 'Snow peaks and alpine adventures', 'manali-mountains', ['manali-snow'], 32.2396, 77.1887,
                'Set in the Kullu valley beneath snow-capped Himalayan peaks, Manali is the base for paragliding, river rafting, high-altitude treks and cosy cafés in Old Manali.',
                ['Solang Valley adventures', 'Rohtang & Atal Tunnel drives', 'Hadimba Temple', 'Old Manali cafés'], 'March – June, December – January', false],
            ['Bali', 'Indonesia', null, 'Temples, terraces and surf', 'bali-rice', ['bali-temple', 'bali-rice'], -8.3405, 115.0920,
                'Bali blends jungle-clad Ubud, cliffside temples, emerald rice terraces and world-class surf beaches with warm Balinese hospitality and wellness at every turn.',
                ['Ubud rice terraces', 'Uluwatu & lake temples', 'Mount Batur sunrise', 'Seminyak sunsets'], 'April – October', true],
            ['Singapore', 'Singapore', null, 'Garden city of the future', 'singapore-mbs', ['singapore-merlion'], 1.3521, 103.8198,
                'Compact, spotless and endlessly inventive, Singapore packs futuristic gardens, hawker-centre feasts, island theme parks and a glittering Marina Bay skyline into a single unforgettable stop.',
                ['Gardens by the Bay', 'Marina Bay skyline', 'Sentosa Island', 'Hawker-centre food trails'], 'February – April', false],
            ['Maldives', 'Maldives', null, 'Overwater villas and turquoise lagoons', 'maldives-villas', ['maldives-resort'], 3.2028, 73.2207,
                'Coral atolls, powder-white sandbanks and overwater villas make the Maldives the ultimate barefoot-luxury escape — ideal for honeymoons, snorkelling and doing absolutely nothing.',
                ['Overwater villas', 'Snorkelling with manta rays', 'Sandbank picnics', 'Sunset dolphin cruises'], 'November – April', true],
            ['Paris', 'France', null, 'Art, cafés and the City of Light', 'paris-eiffel', ['paris-bridge'], 48.8566, 2.3522,
                'Paris rewards slow wandering — from the Louvre and Montmartre to Seine-side bookstalls, patisseries and evening views of the sparkling Eiffel Tower.',
                ['Eiffel Tower', 'The Louvre', 'Seine river cruises', 'Montmartre & Le Marais'], 'April – June, September – October', false],
        ];
        foreach ($destinations as $i => [$name, $country, $state, $tagline, $image, $gallery, $lat, $lng, $desc, $highlights, $best, $featured]) {
            Destination::query()->updateOrCreate(['slug' => Str::slug($name)], [
                'name' => $name, 'country' => $country, 'state' => $state, 'tagline' => $tagline, 'description' => $desc,
                'image_path' => "seed/destinations/{$image}.jpg",
                'gallery' => array_map(fn ($g) => "seed/destinations/{$g}.jpg", $gallery),
                'highlights' => $highlights, 'best_time_to_visit' => $best, 'latitude' => $lat, 'longitude' => $lng,
                'is_featured' => $featured, 'status' => 'active', 'sort_order' => $i,
                'meta_title' => "Hotels, tours & things to do in {$name}",
                'meta_description' => Str::limit($desc, 150),
            ]);
        }

        $faqs = [
            ['Membership', 'How does the StayVoyage membership work?', 'Buy a monthly membership and receive up to 30 hotel-night credits valid for 30 days. Use them at any hotel marked "Membership Eligible": each night in each room uses one credit. You only pay applicable taxes and a small booking fee per stay.', true],
            ['Membership', 'What happens to unused membership nights?', 'Unused nights lapse when your membership period ends (unless your plan says otherwise). Renew before expiry and your new membership starts the moment the current one ends, so you never lose days.', true],
            ['Membership', 'Can I use membership nights at any hotel?', 'Membership nights work at participating hotels and room types marked "Membership Eligible". Some suites, international resorts and peak dates (blackout dates) are excluded — the hotel page always tells you before you book.', true],
            ['Membership', 'Are there limits on how many nights I can book at once?', 'Yes. The StayVoyage Monthly plan allows up to 7 consecutive nights, 1 room and 2 guests per membership booking, booked up to 90 days ahead and within your membership period.', false],
            ['Bookings', 'Is my room confirmed as soon as I click Book?', 'Your room is held for 20 minutes while you complete payment. The booking is confirmed only after the payment provider confirms the payment — you will see the status change and receive an email.', true],
            ['Bookings', 'Can I book a hotel without a membership?', 'Absolutely. Every hotel can be booked as a regular paid stay. Members can also choose a regular booking whenever they prefer to keep their nights.', false],
            ['Cancellations', 'How do cancellations and refunds work?', 'Each room shows its cancellation policy. Cancel within the free window for a full refund to your original payment method (5–7 working days). Membership nights are returned instantly if you cancel before the deadline.', true],
            ['Cancellations', 'What if I cancel a membership booking late?', 'Late cancellations follow your plan\'s late-cancellation policy — on StayVoyage Monthly the nights and booking fee are forfeited after the 24-hour deadline. The cancellation screen always shows exactly what you will get back before you confirm.', false],
            ['Payments', 'Which payment methods do you accept?', 'UPI, credit and debit cards, net banking and popular wallets through Razorpay, plus international cards through Stripe. We never store your card details.', false],
            ['Payments', 'My payment failed — was I charged?', 'If a payment fails, no booking is confirmed. Any amount blocked by your bank is released automatically. You can retry from My Bookings while your room is still held.', false],
            ['Tour Builder', 'What is the Tour Builder?', 'A day-by-day planner where you add hotels, flights, transfers, activities, guides and meals. Prices update live as you build, and you can save, share and book the whole trip in one checkout.', true],
            ['Tour Builder', 'Can I customise a ready-made tour package?', 'Yes — open any tour and tap "Customize This Tour". It is copied into your Tour Builder where you can change dates, swap hotels, add activities or remove anything you don\'t need.', false],
            ['Account', 'How do I share my trip with friends?', 'Open your trip, choose Share, and switch it to Public. Copy the link or send it on WhatsApp. Shared trips never show your personal details.', false],
            ['Account', 'How do I download my invoice?', 'Go to My Bookings (or Membership), open the booking and tap "Download invoice". Invoices are available once payment is complete.', false],
        ];
        foreach ($faqs as $i => [$cat, $q, $a, $home]) {
            Faq::query()->updateOrCreate(['question' => $q], ['category' => $cat, 'answer' => $a, 'show_on_home' => $home, 'is_active' => true, 'sort_order' => $i]);
        }
    }
}

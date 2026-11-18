<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Hotel;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourPackage;
use App\Models\TourTransportation;
use App\Services\Tour\TourPackageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TourCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $d = Destination::query()->pluck('id', 'name');

        // ------------------------------------------------------------------ activities
        // [destination, name, category, location, minutes, start_times, capacity, price, child_price, image, short, inclusions, days]
        $activities = [
            ['Dubai', 'Burj Khalifa At the Top (Levels 124 & 125)', 'attraction', 'Downtown Dubai', 90, ['10:00', '14:00', '17:30', '20:00'], 40, 3900, 2900, 'destinations/dubai-burj-khalifa',
                'Ride the world\'s fastest elevators to the observation decks of the tallest building on earth.', ['Entry tickets', 'Skip-the-line access', 'Telescope viewing']],
            ['Dubai', 'Premium Red Dune Desert Safari with BBQ Dinner', 'safari', 'Lahbab Desert', 360, ['15:00'], 30, 5200, 3900, 'destinations/desert',
                'Dune bashing in a 4x4, sandboarding, camel ride and a BBQ dinner with live shows at a desert camp.', ['Hotel pickup & drop', 'Dune bashing', 'Camel ride', 'BBQ dinner', 'Tanoura & fire shows']],
            ['Dubai', 'Dubai Marina Dhow Cruise Dinner', 'cruise', 'Dubai Marina', 120, ['20:00'], 60, 3400, 2400, 'activities/cruise',
                'Two-hour cruise past the Marina skyline on a traditional wooden dhow with an international buffet.', ['Welcome drinks', 'Buffet dinner', 'Live entertainment']],
            ['Dubai', 'Dubai City Highlights Tour', 'sightseeing', 'Dubai (hotel pickup)', 300, ['09:00'], 25, 2900, 1900, 'destinations/dubai-burj-al-arab',
                'Old Dubai souks and abra ride, Jumeirah Mosque, Burj Al Arab photo stop, Atlantis and the Palm.', ['Air-conditioned coach', 'Abra ride', 'Professional guide']],
            ['Abu Dhabi', 'Sheikh Zayed Grand Mosque Guided Visit', 'cultural', 'Sheikh Zayed Grand Mosque', 120, ['10:00', '16:00'], 30, 1800, 1200, 'destinations/abu-dhabi-mosque',
                'Discover the architecture, marble inlay and the world\'s largest hand-knotted carpet with an expert guide.', ['Guided visit', 'Modest dress provided']],
            ['Abu Dhabi', 'Abu Dhabi Full-day City Tour from Dubai', 'sightseeing', 'Abu Dhabi', 600, ['08:30'], 25, 4600, 3200, 'destinations/abu-dhabi-mosque',
                'Grand Mosque, Corniche, Heritage Village and a photo stop at Qasr Al Watan, with hotel pickup from Dubai.', ['Return transfers from Dubai', 'Guide', 'Lunch']],
            ['Goa', 'Scuba Diving at Grande Island', 'water_sports', 'Grande Island (boat from Sinquerim)', 360, ['08:00'], 16, 4500, null, 'activities/scuba',
                'Beginner-friendly PADI-led dive at Grande Island\'s reefs with a boat trip, snorkelling and lunch.', ['PADI instructor', 'All equipment', 'Boat ride', 'Lunch']],
            ['Goa', 'Old Goa Heritage & Fontainhas Walk', 'cultural', 'Old Goa & Panaji', 240, ['09:00'], 20, 1600, 900, 'destinations/goa-huts',
                'UNESCO churches of Old Goa and the colourful Latin quarter of Fontainhas with a local historian.', ['Expert guide', 'Church entries', 'Feni tasting (adults)']],
            ['Goa', 'Mandovi Sunset River Cruise', 'cruise', 'Panaji Jetty', 90, ['18:00'], 80, 900, 600, 'activities/cruise',
                'Cruise the Mandovi at sunset with Goan folk dance and live music on board.', ['Cruise ticket', 'Live music & dance']],
            ['Jaipur', 'Amber Fort & Hawa Mahal Heritage Tour', 'sightseeing', 'Amber Fort, Jaipur', 300, ['08:30', '13:30'], 20, 2600, 1600, 'destinations/jaipur-fort',
                'Climb to Amber Fort, admire the Palace of Winds and explore City Palace and Jantar Mantar.', ['Private car', 'Licensed guide', 'Monument entries']],
            ['Jaipur', 'Hot Air Balloon Ride over Jaipur', 'adventure', 'Amber countryside', 180, ['06:00'], 12, 14500, null, 'activities/hot-air-balloon',
                'Float over forts, villages and the Aravalli hills at sunrise — one of India\'s most memorable experiences.', ['Hotel transfers', '1-hour flight', 'Flight certificate', 'Refreshments']],
            ['Udaipur', 'Lake Pichola Sunset Boat Ride', 'cruise', 'Rameshwar Ghat', 60, ['17:00', '17:45'], 40, 1100, 700, 'destinations/udaipur-palace',
                'Glide past the City Palace, Jag Niwas and Jag Mandir as the sun sets over the Aravallis.', ['Boat ticket', 'Stop at Jag Mandir']],
            ['Udaipur', 'City Palace Museum Guided Tour', 'museum', 'City Palace, Udaipur', 150, ['10:00', '14:00'], 25, 1500, 900, 'destinations/udaipur-palace',
                'Courtyards, mirror halls and royal collections of the largest palace complex in Rajasthan.', ['Guide', 'Museum entry', 'Audio guide']],
            ['Kerala', 'Alleppey Houseboat Day Cruise', 'cruise', 'Alleppey Finishing Point', 420, ['11:00'], 10, 6500, 4000, 'destinations/kerala-houseboat',
                'Private kettuvallam cruise through palm-lined canals with a freshly cooked Kerala lunch on board.', ['Private houseboat', 'Kerala lunch', 'Tea & snacks']],
            ['Kerala', 'Kathakali Performance & Make-up Session', 'cultural', 'Kochi Cultural Centre', 120, ['17:00'], 60, 900, 600, 'destinations/kerala-backwaters',
                'Watch artists apply elaborate Kathakali make-up, then enjoy a classical dance-drama performance.', ['Reserved seating', 'Make-up viewing']],
            ['Mumbai', 'Mumbai Street Food Walk', 'cultural', 'Colaba & Mohammed Ali Road', 210, ['18:00'], 12, 2400, 1500, 'activities/food-tour',
                'Vada pav, pav bhaji, kebabs and kulfi — taste 10 iconic dishes with a local foodie.', ['10 tastings', 'Local guide', 'Bottled water']],
            ['Mumbai', 'Elephanta Caves Ferry & Guided Tour', 'cultural', 'Gateway of India', 300, ['09:30'], 30, 1900, 1200, 'destinations/mumbai-gateway',
                'Ferry from the Gateway of India to the UNESCO rock-cut caves dedicated to Lord Shiva.', ['Return ferry', 'Guide', 'Entry ticket']],
            ['Agra', 'Taj Mahal Sunrise Tour', 'attraction', 'Taj Mahal East Gate', 180, ['05:45'], 20, 3200, 1800, 'destinations/agra-taj',
                'See the Taj Mahal glow at sunrise before the crowds, with a guide and skip-the-line entry.', ['Skip-the-line entry', 'Guide', 'Shoe covers & water']],
            ['Manali', 'Solang Valley Paragliding', 'adventure', 'Solang Valley', 120, ['10:00', '12:00', '14:00'], 12, 3500, null, 'destinations/manali-mountains',
                'Tandem paragliding flight over Solang Valley with a certified pilot and GoPro footage.', ['Tandem flight', 'Safety gear', 'GoPro video']],
            ['Manali', 'Hampta Valley Day Trek', 'trekking', 'Jobra trailhead', 480, ['07:30'], 15, 2800, null, 'activities/trekking',
                'A scenic day hike through meadows, forests and glacial streams with packed lunch.', ['Trek leader', 'Packed lunch', 'Transfers']],
            ['Bali', 'Ubud Rice Terrace & Temple Tour', 'sightseeing', 'Tegallalang, Ubud', 480, ['08:00'], 14, 3800, 2600, 'destinations/bali-rice',
                'Tegallalang rice terraces, Tirta Empul water temple, a coffee plantation and Ubud market.', ['Private driver', 'Entry fees', 'Coffee tasting']],
            ['Bali', 'Mount Batur Sunrise Trek', 'trekking', 'Kintamani', 540, ['02:00'], 20, 4200, null, 'activities/trekking',
                'Hike an active volcano in the dark to watch sunrise over Lake Batur, with breakfast at the summit.', ['Hotel pickup', 'Guide', 'Summit breakfast']],
            ['Singapore', 'Gardens by the Bay & SkyPark Observation Deck', 'attraction', 'Marina Bay', 240, ['10:00', '15:00', '18:30'], 40, 4400, 3200, 'destinations/singapore-mbs',
                'Flower Dome, Cloud Forest and the SkyPark view over Marina Bay — finishing with the Supertree light show.', ['Conservatory tickets', 'SkyPark entry']],
            ['Singapore', 'Sentosa Island Adventure Day', 'theme_park', 'Sentosa Island', 480, ['10:00'], 50, 6900, 5600, 'activities/theme-park',
                'Cable car to Sentosa, theme-park rides, the aquarium and beach time at Siloso.', ['Cable car', 'Theme park pass', 'Aquarium entry']],
            ['Maldives', 'Reef Snorkelling Safari', 'water_sports', 'House reef & Banana Reef', 180, ['09:00', '14:00'], 12, 5800, 3900, 'activities/scuba',
                'Snorkel with turtles, reef sharks and rays at two of the atoll\'s best reefs.', ['Boat trip', 'Snorkel gear', 'Marine guide']],
            ['Maldives', 'Sunset Dolphin Cruise', 'cruise', 'Resort jetty', 120, ['17:00'], 20, 4800, 3200, 'destinations/maldives-resort',
                'Spot spinner dolphins as the sun sets over the Indian Ocean, with canapés on board.', ['Cruise', 'Canapés & drinks']],
            ['Paris', 'Louvre Museum Skip-the-Line Tour', 'museum', 'Musée du Louvre', 150, ['09:30', '14:00'], 20, 6200, 3800, 'activities/museum',
                'See the Mona Lisa, Venus de Milo and Winged Victory with an art historian and priority entry.', ['Priority entry', 'Art historian guide', 'Headsets']],
            ['Paris', 'Seine River Dinner Cruise', 'cruise', 'Port de la Bourdonnais', 150, ['20:30'], 60, 9800, 6500, 'destinations/paris-eiffel',
                'Three-course French dinner gliding past Notre-Dame, the Louvre and the sparkling Eiffel Tower.', ['3-course dinner', 'Live music', 'Window table']],
        ];
        foreach ($activities as [$dest, $name, $cat, $loc, $min, $times, $cap, $price, $child, $img, $short, $inc]) {
            TourActivity::query()->updateOrCreate(['slug' => Str::slug($name)], [
                'destination_id' => $d[$dest], 'name' => $name, 'category' => $cat, 'location' => $loc, 'duration_minutes' => $min,
                'start_times' => $times, 'available_days' => null, 'capacity_per_slot' => $cap, 'price' => $price, 'child_price' => $child,
                'image_path' => "seed/{$img}.jpg", 'short_description' => $short,
                'description' => $short.' Duration about '.round($min / 60, 1).' hours. Free cancellation up to 72 hours before start through your StayVoyage trip.',
                'inclusions' => $inc, 'rating_avg' => round(4.3 + (crc32($name) % 7) / 10, 1), 'review_count' => 40 + crc32($name) % 400, 'status' => 'active',
            ]);
        }

        // ------------------------------------------------------------------ guides
        $guides = [
            ['Dubai', 'Omar Haddad', ['English', 'Arabic', 'Hindi'], 'Desert & heritage specialist', 11, 'p03', 1400, 9500, [0, 1, 2, 3, 4, 5, 6],
                'Born in Deira, Omar has guided travellers through Dubai\'s souks and dunes for over a decade and knows the best spots for sunset photos.'],
            ['Dubai', 'Layla Hassan', ['English', 'Arabic', 'French'], 'Architecture & modern Dubai', 7, 'p06', 1600, 10500, [0, 1, 2, 3, 4, 6],
                'An architect by training, Layla brings Dubai\'s skyline to life — from the Burj Khalifa\'s engineering to the restored Al Fahidi quarter.'],
            ['Jaipur', 'Arjun Mehta', ['English', 'Hindi', 'Gujarati'], 'Rajput history & forts', 14, 'p02', 900, 5500, [0, 1, 2, 3, 4, 5, 6],
                'A history graduate from Rajasthan University, Arjun shares the stories behind every fort, stepwell and royal feud in Jaipur.'],
            ['Udaipur', 'Vikram Rathore', ['English', 'Hindi', 'Rajasthani'], 'Palaces, lakes & Mewar culture', 20, 'p05', 950, 5800, [1, 2, 3, 4, 5, 6],
                'Vikram\'s family has lived beside Lake Pichola for generations; his walks reveal hidden havelis and artisan workshops.'],
            ['Kerala', 'Meera Nair', ['English', 'Malayalam', 'Hindi', 'Tamil'], 'Backwaters, spice & Ayurveda', 9, 'p01', 850, 5000, [0, 1, 2, 3, 4, 5, 6],
                'Meera grew up in Kumarakom and loves introducing visitors to village life, spice gardens and Kerala cuisine.'],
            ['Goa', 'Rohan D\'Souza', ['English', 'Konkani', 'Hindi', 'Portuguese'], 'Goan heritage & food', 6, 'p10', 800, 4800, [0, 2, 3, 4, 5, 6],
                'Rohan runs heritage walks through Fontainhas and knows every bakery that still makes poi the old way.'],
            ['Manali', 'Karan Thakur', ['English', 'Hindi', 'Pahari'], 'Mountain treks & adventure', 12, 'p07', 1000, 6000, [0, 1, 2, 3, 4, 5, 6],
                'A certified mountaineer, Karan leads treks across the Kullu valley and is passionate about responsible Himalayan travel.'],
            ['Paris', 'Claire Dubois', ['French', 'English', 'Spanish'], 'Art history & hidden Paris', 10, 'p04', 4200, 26000, [1, 2, 3, 4, 5, 6],
                'An art historian and Louvre-certified guide, Claire shows visitors the Paris that locals love — courtyards, ateliers and cafés.'],
        ];
        foreach ($guides as [$dest, $name, $langs, $spec, $years, $photo, $rate, $day, $days, $bio]) {
            TourGuide::query()->updateOrCreate(['slug' => Str::slug($name)], [
                'destination_id' => $d[$dest], 'name' => $name, 'languages' => $langs, 'specialty' => $spec, 'experience_years' => $years,
                'profile_image' => "seed/people/{$photo}.jpg", 'hourly_rate' => $rate, 'full_day_rate' => $day, 'available_days' => $days,
                'available_from' => '08:00', 'available_to' => '20:00', 'min_hours' => 3, 'max_hours' => 10, 'bio' => $bio,
                'rating_avg' => round(4.6 + (crc32($name) % 4) / 10, 1), 'review_count' => 30 + crc32($name) % 200, 'status' => 'active',
            ]);
        }

        // ------------------------------------------------------------------ transportation
        // [destination|null, type, name, provider, from, to, unit, price, capacity, minutes, times, image]
        $transport = [
            [null, 'flight', 'Ahmedabad (AMD) → Dubai (DXB), Economy', 'SkyWings Airlines', 'Ahmedabad (AMD)', 'Dubai (DXB)', 'per_person', 14800, 180, 215, ['06:15', '19:40'], 'misc/flight'],
            [null, 'flight', 'Dubai (DXB) → Ahmedabad (AMD), Economy', 'SkyWings Airlines', 'Dubai (DXB)', 'Ahmedabad (AMD)', 'per_person', 15600, 180, 200, ['09:30', '22:10'], 'misc/flight'],
            [null, 'flight', 'Mumbai (BOM) → Dubai (DXB), Economy', 'Arabian Gulf Air', 'Mumbai (BOM)', 'Dubai (DXB)', 'per_person', 13900, 180, 195, ['04:50', '16:20'], 'misc/flight'],
            [null, 'flight', 'Mumbai (BOM) → Goa (GOX), Economy', 'SkyWings Airlines', 'Mumbai (BOM)', 'Goa Mopa (GOX)', 'per_person', 4200, 180, 75, ['07:00', '13:35', '18:50'], 'misc/flight'],
            [null, 'flight', 'Goa (GOX) → Mumbai (BOM), Economy', 'SkyWings Airlines', 'Goa Mopa (GOX)', 'Mumbai (BOM)', 'per_person', 4400, 180, 75, ['09:10', '15:45', '21:00'], 'misc/flight'],
            [null, 'flight', 'Delhi (DEL) → Udaipur (UDR), Economy', 'IndiAir Connect', 'Delhi (DEL)', 'Udaipur (UDR)', 'per_person', 5600, 180, 85, ['06:40', '14:15'], 'misc/flight'],
            [null, 'flight', 'Mumbai (BOM) → Kochi (COK), Economy', 'IndiAir Connect', 'Mumbai (BOM)', 'Kochi (COK)', 'per_person', 5200, 180, 115, ['08:20', '17:30'], 'misc/flight'],
            [null, 'flight', 'Delhi (DEL) → Kullu-Manali (KUU), Economy', 'Himalayan Air', 'Delhi (DEL)', 'Kullu-Manali (KUU)', 'per_person', 7900, 70, 80, ['07:10'], 'misc/flight'],
            [null, 'flight', 'Mumbai (BOM) → Bali (DPS), Economy', 'Island Hopper Airways', 'Mumbai (BOM)', 'Denpasar (DPS)', 'per_person', 28500, 180, 540, ['23:55'], 'misc/flight'],
            [null, 'flight', 'Mumbai (BOM) → Singapore (SIN), Economy', 'Island Hopper Airways', 'Mumbai (BOM)', 'Singapore (SIN)', 'per_person', 21500, 180, 330, ['00:45', '13:20'], 'misc/flight'],
            [null, 'flight', 'Mumbai (BOM) → Malé (MLE), Economy', 'Island Hopper Airways', 'Mumbai (BOM)', 'Malé (MLE)', 'per_person', 18900, 180, 170, ['11:05'], 'misc/flight'],
            [null, 'flight', 'Mumbai (BOM) → Paris (CDG), Economy', 'EuroIndia Airlines', 'Mumbai (BOM)', 'Paris (CDG)', 'per_person', 52000, 280, 600, ['02:10'], 'misc/flight'],
            ['Agra', 'train', 'Delhi → Agra Express Train (AC Chair Car)', 'Indian Railways', 'New Delhi', 'Agra Cantt', 'per_person', 1250, 70, 105, ['06:00', '08:10'], 'misc/train'],
            ['Jaipur', 'train', 'Jaipur → Udaipur Intercity (AC 2-tier)', 'Indian Railways', 'Jaipur Junction', 'Udaipur City', 'per_person', 1450, 50, 420, ['06:30', '22:15'], 'misc/train'],
            ['Manali', 'bus', 'Delhi → Manali Volvo AC Sleeper', 'HimRoad Travels', 'Delhi (Majnu Ka Tilla)', 'Manali Bus Stand', 'per_person', 1900, 36, 780, ['18:30', '20:00'], 'misc/bus'],
            ['Kerala', 'bus', 'Kochi → Munnar Volvo Coach', 'Kerala Hill Coaches', 'Kochi', 'Munnar', 'per_person', 650, 40, 240, ['07:00', '13:00'], 'misc/bus'],
            ['Dubai', 'taxi', 'Dubai City Taxi (half-day, 5 hours)', 'Dubai City Cabs', 'Your hotel', 'Anywhere in Dubai', 'per_vehicle', 3600, 4, 300, null, 'misc/car'],
            ['Dubai', 'private_car', 'Private SUV with Chauffeur — Full Day', 'Emirates Limo Partners', 'Your hotel', 'Your itinerary', 'per_day', 9800, 6, 600, null, 'misc/car'],
            ['Dubai', 'airport_transfer', 'Dubai Airport (DXB) ↔ Hotel Private Transfer', 'Emirates Limo Partners', 'Dubai International Airport', 'Your Dubai hotel', 'per_vehicle', 2600, 4, 35, null, 'misc/car'],
            ['Dubai', 'hotel_transfer', 'Dubai → Abu Dhabi Private Transfer', 'Emirates Limo Partners', 'Dubai hotel', 'Abu Dhabi hotel', 'per_vehicle', 7400, 4, 90, null, 'misc/car'],
            ['Abu Dhabi', 'hotel_transfer', 'Abu Dhabi → Dubai Private Transfer', 'Emirates Limo Partners', 'Abu Dhabi hotel', 'Dubai hotel', 'per_vehicle', 7400, 4, 90, null, 'misc/car'],
            ['Goa', 'airport_transfer', 'Goa Airport (GOX) ↔ North Goa Hotel Transfer', 'Goa Coast Cabs', 'Mopa Airport', 'North Goa hotel', 'per_vehicle', 2200, 4, 60, null, 'misc/car'],
            ['Goa', 'rental_car', 'Self-drive Hatchback Rental (per day)', 'Coastal Wheels', 'Hotel delivery', 'Return at hotel', 'per_day', 1600, 4, null, null, 'misc/car'],
            ['Jaipur', 'private_car', 'Private Sedan with Driver — Full Day Jaipur', 'Royal Rajasthan Cabs', 'Your hotel', 'Jaipur sightseeing', 'per_day', 3200, 4, 540, null, 'misc/car'],
            ['Udaipur', 'airport_transfer', 'Udaipur Airport (UDR) ↔ Hotel Transfer', 'Lake City Taxis', 'Maharana Pratap Airport', 'Udaipur hotel', 'per_vehicle', 1200, 4, 40, null, 'misc/car'],
            ['Kerala', 'airport_transfer', 'Kochi Airport (COK) → Alleppey Hotel Transfer', 'Kerala Hill Coaches', 'Kochi Airport', 'Alleppey hotel', 'per_vehicle', 3400, 4, 120, null, 'misc/car'],
            ['Kerala', 'hotel_transfer', 'Alleppey → Munnar Private Transfer', 'Kerala Hill Coaches', 'Alleppey hotel', 'Munnar hotel', 'per_vehicle', 5200, 4, 270, null, 'misc/car'],
            ['Manali', 'airport_transfer', 'Kullu Airport (KUU) ↔ Manali Hotel Transfer', 'Valley Riders', 'Bhuntar Airport', 'Manali hotel', 'per_vehicle', 1900, 4, 75, null, 'misc/car'],
            ['Bali', 'airport_transfer', 'Bali Airport (DPS) ↔ Ubud/Seminyak Transfer', 'Bali Island Drivers', 'Ngurah Rai Airport', 'Your Bali hotel', 'per_vehicle', 2100, 4, 60, null, 'misc/car'],
            ['Bali', 'private_car', 'Private Car with Driver — Full Day Bali', 'Bali Island Drivers', 'Your hotel', 'Your itinerary', 'per_day', 3900, 5, 600, null, 'misc/car'],
            ['Singapore', 'airport_transfer', 'Changi Airport ↔ Marina Bay Transfer', 'Lion City Limousines', 'Changi Airport', 'Marina Bay hotel', 'per_vehicle', 3200, 4, 30, null, 'misc/car'],
            ['Maldives', 'airport_transfer', 'Malé ↔ Coral Lagoon Speedboat Transfer', 'Coral Lagoon Marine', 'Velana Airport, Malé', 'Coral Lagoon resort', 'per_person', 9500, 20, 25, null, 'destinations/maldives-resort'],
            ['Paris', 'airport_transfer', 'Paris CDG ↔ Hotel Private Transfer', 'Paris Prestige Cars', 'Charles de Gaulle Airport', 'Your Paris hotel', 'per_vehicle', 8900, 4, 55, null, 'misc/car'],
        ];
        foreach ($transport as [$dest, $type, $name, $provider, $from, $to, $unit, $price, $cap, $min, $times, $img]) {
            TourTransportation::query()->updateOrCreate(['name' => $name], [
                'destination_id' => $dest ? $d[$dest] : null, 'type' => $type, 'provider' => $provider, 'from_location' => $from, 'to_location' => $to,
                'pricing_unit' => $unit, 'price' => $price, 'capacity' => $cap, 'duration_minutes' => $min, 'departure_times' => $times,
                'image_path' => "seed/{$img}.jpg", 'status' => 'active',
                'description' => match ($type) {
                    'flight' => "Scheduled economy flight operated by {$provider}. Includes 20 kg checked baggage and 7 kg cabin baggage. Fares are indicative and confirmed at booking.",
                    'train', 'bus' => "Reserved seats on the {$provider} service. E-tickets are issued 48 hours before departure.",
                    default => "Private, air-conditioned vehicle with a professional driver. Meet & greet included; tolls and parking covered.",
                },
            ]);
        }

        // ------------------------------------------------------------------ meals
        $meals = [
            ['Dubai', 'Arabian BBQ Dinner under the Stars', 'dinner', 'Al Majlis Desert Camp', 'Emirati & Levantine', 3200, 'destinations/desert'],
            ['Dubai', 'Brunch with a View at Downtown', 'lunch', 'Skyline Terrace, Downtown Dubai', 'International', 4500, 'misc/dining'],
            ['Abu Dhabi', 'Gulf Seafood Dinner on the Corniche', 'dinner', 'Pearl Harbour Restaurant', 'Seafood', 3600, 'misc/restaurant'],
            ['Goa', 'Goan Seafood Feast', 'dinner', 'Fisherman\'s Wharf Shack, Candolim', 'Goan', 1800, 'activities/food-tour'],
            ['Goa', 'Portuguese-Goan Brunch', 'lunch', 'Casa Fontainhas Café', 'Indo-Portuguese', 1400, 'misc/dining'],
            ['Jaipur', 'Royal Rajasthani Thali Dinner', 'dinner', 'Rooftop at the Old City', 'Rajasthani', 1500, 'misc/restaurant'],
            ['Udaipur', 'Candle-lit Rooftop Dinner by Lake Pichola', 'dinner', 'Lakeside Rooftop, Gangaur Ghat', 'Mewari & continental', 2800, 'misc/dining'],
            ['Kerala', 'Traditional Kerala Sadya Lunch', 'lunch', 'Backwater village home', 'Keralan', 1100, 'activities/food-tour'],
            ['Mumbai', 'Parsi Café Breakfast Experience', 'breakfast', 'Fort district café', 'Parsi', 900, 'misc/dining'],
            ['Manali', 'Himachali Home-style Dinner', 'dinner', 'Old Manali family kitchen', 'Himachali', 900, 'misc/restaurant'],
            ['Bali', 'Balinese Cooking Class & Lunch', 'experience', 'Ubud organic farm', 'Balinese', 3400, 'activities/food-tour'],
            ['Singapore', 'Hawker-centre Food Trail', 'dinner', 'Lau Pa Sat & Satay Street', 'Singaporean', 2600, 'activities/food-tour'],
            ['Maldives', 'Private Sandbank Dinner', 'dinner', 'Coral Lagoon sandbank', 'Seafood & grill', 14500, 'destinations/maldives-resort'],
            ['Paris', 'Parisian Bistro Dinner', 'dinner', 'Le Petit Bistrot, Saint-Germain', 'French', 6800, 'misc/restaurant'],
        ];
        foreach ($meals as [$dest, $name, $type, $venue, $cuisine, $price, $img]) {
            TourMeal::query()->updateOrCreate(['name' => $name], [
                'destination_id' => $d[$dest], 'meal_type' => $type, 'venue' => $venue, 'cuisine' => $cuisine, 'price' => $price,
                'image_path' => "seed/{$img}.jpg", 'status' => 'active',
                'description' => "{$cuisine} {$type} at {$venue}. Vegetarian and Jain options are available on request.",
            ]);
        }

        $this->packages($d);
    }

    private function packages($d): void
    {
        $room = fn (string $hotel, int $k = 0) => Hotel::query()->where('name', $hotel)->firstOrFail()->rooms()->orderBy('sort_order')->skip($k)->value('id');
        $act = fn (string $n) => TourActivity::query()->where('name', $n)->value('id');
        $guide = fn (string $n) => TourGuide::query()->where('name', $n)->value('id');
        $tr = fn (string $n) => TourTransportation::query()->where('name', $n)->value('id');
        $meal = fn (string $n) => TourMeal::query()->where('name', $n)->value('id');

        $packages = [
            [
                'name' => 'Dubai Premium 5-Day Tour', 'destination' => 'Dubai', 'days' => 5, 'nights' => 4, 'cities' => 'Dubai · Abu Dhabi', 'featured' => true, 'image' => 'destinations/dubai-burj-khalifa',
                'gallery' => ['destinations/dubai-marina', 'destinations/desert', 'destinations/abu-dhabi-mosque', 'destinations/dubai-burj-al-arab'],
                'summary' => 'Four nights at a five-star Marina hotel with airport transfers, the Burj Khalifa, a guided city tour, a desert safari and a day trip to Abu Dhabi.',
                'highlights' => ['4 nights at Al Noor Marina Hotel with breakfast', 'Burj Khalifa At the Top', 'Premium red-dune desert safari with BBQ dinner', 'Abu Dhabi & Sheikh Zayed Grand Mosque', 'Private English/Hindi-speaking guide'],
                'inclusions' => ['Return economy flights from Ahmedabad', 'Private airport transfers', '4 nights accommodation with breakfast', 'All activities listed', 'Private guide for 2 days', 'GST & service fees'],
                'exclusions' => ['UAE visa fees', 'Lunches unless mentioned', 'Tourism Dirham fee paid at hotel', 'Personal expenses'],
                'items' => [
                    [1, 'Arrive in Dubai', 'flight', $tr('Ahmedabad (AMD) → Dubai (DXB), Economy'), 'Ahmedabad → Dubai', '06:15', null, null],
                    [1, 'Arrive in Dubai', 'airport_transfer', $tr('Dubai Airport (DXB) ↔ Hotel Private Transfer'), 'Airport pickup to your hotel', '11:00', null, null],
                    [1, 'Arrive in Dubai', 'accommodation', $room('Al Noor Marina Hotel'), 'Al Noor Marina Hotel — Deluxe King Room', '14:00', 4, null],
                    [1, 'Arrive in Dubai', 'activity', $act('Dubai Marina Dhow Cruise Dinner'), 'Dubai Marina Dhow Cruise Dinner', '20:00', null, null],
                    [2, 'Downtown & Old Dubai', 'activity', $act('Dubai City Highlights Tour'), 'Dubai City Highlights Tour', '09:00', null, null],
                    [2, 'Downtown & Old Dubai', 'guide', $guide('Omar Haddad'), 'Guide: Omar Haddad', '09:00', null, 6],
                    [2, 'Downtown & Old Dubai', 'attraction', $act('Burj Khalifa At the Top (Levels 124 & 125)'), 'Burj Khalifa At the Top', '17:30', null, null],
                    [3, 'Abu Dhabi day trip', 'hotel_transfer', $tr('Dubai → Abu Dhabi Private Transfer'), 'Private transfer to Abu Dhabi', '08:30', null, null],
                    [3, 'Abu Dhabi day trip', 'activity', $act('Sheikh Zayed Grand Mosque Guided Visit'), 'Sheikh Zayed Grand Mosque Guided Visit', '10:00', null, null],
                    [3, 'Abu Dhabi day trip', 'hotel_transfer', $tr('Abu Dhabi → Dubai Private Transfer'), 'Return transfer to Dubai', '16:00', null, null],
                    [4, 'Desert adventure', 'guide', $guide('Omar Haddad'), 'Guide: Omar Haddad', '10:00', null, 4],
                    [4, 'Desert adventure', 'activity', $act('Premium Red Dune Desert Safari with BBQ Dinner'), 'Premium Red Dune Desert Safari with BBQ Dinner', '15:00', null, null],
                    [5, 'Fly home', 'airport_transfer', $tr('Dubai Airport (DXB) ↔ Hotel Private Transfer'), 'Hotel to airport transfer', '07:00', null, null],
                    [5, 'Fly home', 'flight', $tr('Dubai (DXB) → Ahmedabad (AMD), Economy'), 'Dubai → Ahmedabad', '09:30', null, null],
                ],
            ],
            [
                'name' => 'Royal Rajasthan: Jaipur & Udaipur', 'destination' => 'Jaipur', 'days' => 6, 'nights' => 5, 'cities' => 'Jaipur · Udaipur', 'featured' => true, 'image' => 'destinations/jaipur-hawa-mahal',
                'gallery' => ['destinations/jaipur-fort', 'destinations/udaipur-palace'],
                'summary' => 'Heritage havelis, hilltop forts and a lakeside palace hotel — Rajasthan\'s two most romantic cities in one relaxed journey.',
                'highlights' => ['Heritage stay at Haveli Rajputana', 'Amber Fort & Hawa Mahal with a historian', 'Sunrise hot-air balloon ride (optional)', 'Lake Pichola sunset boat ride', 'Lakeside nights at Grand Palace Hotel'],
                'inclusions' => ['5 nights accommodation with breakfast', 'Train Jaipur → Udaipur (AC)', 'Guided sightseeing', 'Airport transfers in Udaipur', 'GST & service fees'],
                'exclusions' => ['Flights to Jaipur / from Udaipur', 'Camera fees at monuments', 'Personal expenses'],
                'items' => [
                    [1, 'Welcome to the Pink City', 'accommodation', $room('Haveli Rajputana'), 'Haveli Rajputana — Heritage Deluxe Room', '12:00', 3, null],
                    [1, 'Welcome to the Pink City', 'meal', $meal('Royal Rajasthani Thali Dinner'), 'Royal Rajasthani Thali Dinner', '20:00', null, null],
                    [2, 'Forts & palaces', 'activity', $act('Amber Fort & Hawa Mahal Heritage Tour'), 'Amber Fort & Hawa Mahal Heritage Tour', '08:30', null, null],
                    [2, 'Forts & palaces', 'guide', $guide('Arjun Mehta'), 'Guide: Arjun Mehta', '08:30', null, 6],
                    [3, 'Bazaars & free time', 'private_car', $tr('Private Sedan with Driver — Full Day Jaipur'), 'Private car for shopping & sightseeing', '10:00', null, null],
                    [4, 'Journey to Udaipur', 'train', $tr('Jaipur → Udaipur Intercity (AC 2-tier)'), 'Jaipur → Udaipur by train', '06:30', null, null],
                    [4, 'Journey to Udaipur', 'accommodation', $room('Grand Palace Hotel'), 'Grand Palace Hotel — Deluxe Lake View', '14:00', 2, null],
                    [4, 'Journey to Udaipur', 'activity', $act('Lake Pichola Sunset Boat Ride'), 'Lake Pichola Sunset Boat Ride', '17:00', null, null],
                    [5, 'City of Lakes', 'activity', $act('City Palace Museum Guided Tour'), 'City Palace Museum Guided Tour', '10:00', null, null],
                    [5, 'City of Lakes', 'guide', $guide('Vikram Rathore'), 'Guide: Vikram Rathore', '10:00', null, 4],
                    [5, 'City of Lakes', 'meal', $meal('Candle-lit Rooftop Dinner by Lake Pichola'), 'Candle-lit Rooftop Dinner by Lake Pichola', '20:00', null, null],
                    [6, 'Departure', 'airport_transfer', $tr('Udaipur Airport (UDR) ↔ Hotel Transfer'), 'Transfer to Udaipur airport', '10:00', null, null],
                ],
            ],
            [
                'name' => 'Goa Beach Escape', 'destination' => 'Goa', 'days' => 4, 'nights' => 3, 'cities' => 'North Goa', 'featured' => true, 'image' => 'destinations/goa-beach',
                'gallery' => ['destinations/goa-huts', 'activities/scuba'],
                'summary' => 'Three easy-going nights by Candolim beach with a heritage walk, a scuba dive at Grande Island and a seafood feast.',
                'highlights' => ['3 nights at Palm Grove Beach Resort', 'Scuba diving with PADI instructors', 'Old Goa & Fontainhas heritage walk', 'Mandovi sunset cruise', 'Goan seafood feast'],
                'inclusions' => ['Return flights from Mumbai', 'Airport transfers', '3 nights with breakfast', 'Activities listed', 'GST & service fees'],
                'exclusions' => ['Lunches', 'Water-sport upgrades', 'Personal expenses'],
                'items' => [
                    [1, 'Susegad arrival', 'flight', $tr('Mumbai (BOM) → Goa (GOX), Economy'), 'Mumbai → Goa', '07:00', null, null],
                    [1, 'Susegad arrival', 'airport_transfer', $tr('Goa Airport (GOX) ↔ North Goa Hotel Transfer'), 'Airport to resort', '09:00', null, null],
                    [1, 'Susegad arrival', 'accommodation', $room('Palm Grove Beach Resort'), 'Palm Grove Beach Resort — Deluxe Garden Room', '14:00', 3, null],
                    [1, 'Susegad arrival', 'activity', $act('Mandovi Sunset River Cruise'), 'Mandovi Sunset River Cruise', '18:00', null, null],
                    [2, 'Under the sea', 'activity', $act('Scuba Diving at Grande Island'), 'Scuba Diving at Grande Island', '08:00', null, null],
                    [2, 'Under the sea', 'meal', $meal('Goan Seafood Feast'), 'Goan Seafood Feast', '20:00', null, null],
                    [3, 'Heritage Goa', 'activity', $act('Old Goa Heritage & Fontainhas Walk'), 'Old Goa Heritage & Fontainhas Walk', '09:00', null, null],
                    [3, 'Heritage Goa', 'guide', $guide('Rohan D\'Souza'), 'Guide: Rohan D\'Souza', '09:00', null, 4],
                    [4, 'Fly home', 'airport_transfer', $tr('Goa Airport (GOX) ↔ North Goa Hotel Transfer'), 'Resort to airport', '12:00', null, null],
                    [4, 'Fly home', 'flight', $tr('Goa (GOX) → Mumbai (BOM), Economy'), 'Goa → Mumbai', '15:45', null, null],
                ],
            ],
            [
                'name' => 'Kerala Backwaters & Tea Hills', 'destination' => 'Kerala', 'days' => 5, 'nights' => 4, 'cities' => 'Alleppey · Munnar', 'featured' => true, 'image' => 'destinations/kerala-houseboat',
                'gallery' => ['destinations/kerala-backwaters'],
                'summary' => 'Drift through Alleppey\'s backwaters on a private houseboat, then climb into Munnar\'s misty tea country.',
                'highlights' => ['Private houseboat day cruise', 'Lakeside cottage stay', 'Kathakali performance', 'Tea-estate stay in Munnar', 'Kerala sadya lunch'],
                'inclusions' => ['4 nights with breakfast', 'Private transfers', 'Houseboat cruise with lunch', 'GST & service fees'],
                'exclusions' => ['Flights', 'Ayurveda treatments', 'Personal expenses'],
                'items' => [
                    [1, 'Into the backwaters', 'airport_transfer', $tr('Kochi Airport (COK) → Alleppey Hotel Transfer'), 'Kochi airport to Alleppey', '10:00', null, null],
                    [1, 'Into the backwaters', 'accommodation', $room('Backwater Bliss Resort'), 'Backwater Bliss Resort — Lake View Cottage', '14:00', 2, null],
                    [1, 'Into the backwaters', 'activity', $act('Kathakali Performance & Make-up Session'), 'Kathakali Performance & Make-up Session', '17:00', null, null],
                    [2, 'Houseboat day', 'activity', $act('Alleppey Houseboat Day Cruise'), 'Alleppey Houseboat Day Cruise', '11:00', null, null],
                    [2, 'Houseboat day', 'guide', $guide('Meera Nair'), 'Guide: Meera Nair', '10:30', null, 6],
                    [3, 'Up to Munnar', 'hotel_transfer', $tr('Alleppey → Munnar Private Transfer'), 'Scenic drive to Munnar', '09:00', null, null],
                    [3, 'Up to Munnar', 'accommodation', $room('Munnar Mist Hills Resort'), 'Munnar Mist Hills Resort — Valley View Deluxe', '14:00', 2, null],
                    [4, 'Tea country', 'meal', $meal('Traditional Kerala Sadya Lunch'), 'Traditional Kerala Sadya Lunch', '13:00', null, null],
                    [5, 'Departure', 'other', null, 'Leisurely breakfast & checkout', '10:00', null, null],
                ],
            ],
            [
                'name' => 'Bali Island Explorer', 'destination' => 'Bali', 'days' => 6, 'nights' => 5, 'cities' => 'Ubud · Seminyak', 'featured' => true, 'image' => 'destinations/bali-rice',
                'gallery' => ['destinations/bali-temple'],
                'summary' => 'Jungle villas in Ubud, a volcano sunrise and sunset beach days in Seminyak — the best of Bali in under a week.',
                'highlights' => ['3 nights in Ubud rainforest villas', 'Mount Batur sunrise trek', 'Rice terraces & water temple', 'Balinese cooking class', '2 nights on Seminyak\'s sunset strip'],
                'inclusions' => ['5 nights with breakfast', 'Airport transfers', 'Activities listed', 'GST & service fees'],
                'exclusions' => ['International flights', 'Indonesia visa on arrival', 'Personal expenses'],
                'items' => [
                    [1, 'Selamat datang', 'airport_transfer', $tr('Bali Airport (DPS) ↔ Ubud/Seminyak Transfer'), 'Airport to Ubud', '10:00', null, null],
                    [1, 'Selamat datang', 'accommodation', $room('Ubud Rainforest Villas'), 'Ubud Rainforest Villas — Jungle Deluxe', '14:00', 3, null],
                    [2, 'Terraces & temples', 'activity', $act('Ubud Rice Terrace & Temple Tour'), 'Ubud Rice Terrace & Temple Tour', '08:00', null, null],
                    [3, 'Volcano sunrise', 'activity', $act('Mount Batur Sunrise Trek'), 'Mount Batur Sunrise Trek', '02:00', null, null],
                    [3, 'Volcano sunrise', 'meal', $meal('Balinese Cooking Class & Lunch'), 'Balinese Cooking Class & Lunch', '13:00', null, null],
                    [4, 'Beach time', 'private_car', $tr('Private Car with Driver — Full Day Bali'), 'Transfer to Seminyak via Tanah Lot', '10:00', null, null],
                    [4, 'Beach time', 'accommodation', $room('Seminyak Sunset Resort'), 'Seminyak Sunset Resort — Deluxe Garden Room', '15:00', 2, null],
                    [6, 'Sampai jumpa', 'airport_transfer', $tr('Bali Airport (DPS) ↔ Ubud/Seminyak Transfer'), 'Hotel to airport', '11:00', null, null],
                ],
            ],
            [
                'name' => 'Maldives Overwater Honeymoon', 'destination' => 'Maldives', 'days' => 4, 'nights' => 3, 'cities' => 'South Malé Atoll', 'featured' => true, 'image' => 'destinations/maldives-villas',
                'gallery' => ['destinations/maldives-resort'],
                'summary' => 'Three nights in an overwater villa with speedboat transfers, a snorkelling safari, a dolphin cruise and a private sandbank dinner.',
                'highlights' => ['Overwater Villa with lagoon access', 'Snorkelling safari with turtles', 'Sunset dolphin cruise', 'Private sandbank dinner'],
                'inclusions' => ['3 nights with breakfast', 'Return speedboat transfers', 'Activities listed', 'GST & service fees'],
                'exclusions' => ['International flights', 'Green tax payable at resort', 'Personal expenses'],
                'default_adults' => 2,
                'items' => [
                    [1, 'Island arrival', 'airport_transfer', $tr('Malé ↔ Coral Lagoon Speedboat Transfer'), 'Speedboat to Coral Lagoon', '13:00', null, null],
                    [1, 'Island arrival', 'accommodation', $room('Coral Lagoon Overwater Resort', 1), 'Coral Lagoon — Overwater Villa', '14:00', 3, null],
                    [2, 'Reef day', 'activity', $act('Reef Snorkelling Safari'), 'Reef Snorkelling Safari', '09:00', null, null],
                    [2, 'Reef day', 'activity', $act('Sunset Dolphin Cruise'), 'Sunset Dolphin Cruise', '17:00', null, null],
                    [3, 'Pure relaxation', 'meal', $meal('Private Sandbank Dinner'), 'Private Sandbank Dinner', '19:30', null, null],
                    [4, 'Departure', 'airport_transfer', $tr('Malé ↔ Coral Lagoon Speedboat Transfer'), 'Speedboat to Malé airport', '11:00', null, null],
                ],
            ],
            [
                'name' => 'Manali Mountain Adventure', 'destination' => 'Manali', 'days' => 5, 'nights' => 4, 'cities' => 'Manali · Solang', 'featured' => false, 'image' => 'destinations/manali-mountains',
                'gallery' => ['destinations/manali-snow', 'activities/trekking'],
                'summary' => 'Paragliding over Solang Valley, a meadow trek with a mountaineer and cosy nights among the deodars.',
                'highlights' => ['Tandem paragliding in Solang', 'Hampta valley day trek', 'Log-cabin style resort', 'Himachali home dinner'],
                'inclusions' => ['4 nights with breakfast', 'Airport transfers', 'Adventure activities listed', 'GST & service fees'],
                'exclusions' => ['Flights', 'Rohtang permits', 'Personal expenses'],
                'items' => [
                    [1, 'Into the valley', 'airport_transfer', $tr('Kullu Airport (KUU) ↔ Manali Hotel Transfer'), 'Kullu airport to Manali', '10:00', null, null],
                    [1, 'Into the valley', 'accommodation', $room('Pine Valley Resort'), 'Pine Valley Resort — Deluxe Valley View', '14:00', 4, null],
                    [2, 'Fly high', 'activity', $act('Solang Valley Paragliding'), 'Solang Valley Paragliding', '10:00', null, null],
                    [3, 'Trek day', 'activity', $act('Hampta Valley Day Trek'), 'Hampta Valley Day Trek', '07:30', null, null],
                    [3, 'Trek day', 'guide', $guide('Karan Thakur'), 'Guide: Karan Thakur', '07:30', null, 8],
                    [4, 'Old Manali', 'meal', $meal('Himachali Home-style Dinner'), 'Himachali Home-style Dinner', '19:30', null, null],
                    [5, 'Departure', 'airport_transfer', $tr('Kullu Airport (KUU) ↔ Manali Hotel Transfer'), 'Hotel to Kullu airport', '09:00', null, null],
                ],
            ],
            [
                'name' => 'Singapore City Lights', 'destination' => 'Singapore', 'days' => 4, 'nights' => 3, 'cities' => 'Singapore', 'featured' => false, 'image' => 'destinations/singapore-merlion',
                'gallery' => ['destinations/singapore-mbs', 'activities/theme-park'],
                'summary' => 'Marina Bay views, futuristic gardens, a Sentosa island day and a hawker-centre feast.',
                'highlights' => ['3 nights at Marina Skyline Hotel', 'Gardens by the Bay & SkyPark', 'Sentosa adventure day', 'Hawker-centre food trail'],
                'inclusions' => ['3 nights with breakfast', 'Airport transfers', 'Attractions listed', 'GST & service fees'],
                'exclusions' => ['Flights', 'Singapore visa', 'Personal expenses'],
                'items' => [
                    [1, 'Arrival', 'airport_transfer', $tr('Changi Airport ↔ Marina Bay Transfer'), 'Changi to Marina Bay', '10:00', null, null],
                    [1, 'Arrival', 'accommodation', $room('Marina Skyline Hotel'), 'Marina Skyline Hotel — Deluxe King', '15:00', 3, null],
                    [1, 'Arrival', 'attraction', $act('Gardens by the Bay & SkyPark Observation Deck'), 'Gardens by the Bay & SkyPark', '18:30', null, null],
                    [2, 'Sentosa', 'activity', $act('Sentosa Island Adventure Day'), 'Sentosa Island Adventure Day', '10:00', null, null],
                    [3, 'Food & culture', 'meal', $meal('Hawker-centre Food Trail'), 'Hawker-centre Food Trail', '19:00', null, null],
                    [4, 'Departure', 'airport_transfer', $tr('Changi Airport ↔ Marina Bay Transfer'), 'Hotel to Changi', '11:00', null, null],
                ],
            ],
        ];

        $svc = app(TourPackageService::class);
        foreach ($packages as $p) {
            $pkg = TourPackage::query()->updateOrCreate(['slug' => Str::slug($p['name'])], [
                'destination_id' => $d[$p['destination']], 'name' => $p['name'], 'summary' => $p['summary'],
                'description' => $p['summary']."\n\nEvery element of this tour can be changed in the StayVoyage Tour Builder — swap hotels, add nights, change activities or invite more travellers, and the price updates instantly.",
                'duration_days' => $p['days'], 'duration_nights' => $p['nights'], 'cities' => $p['cities'], 'default_adults' => $p['default_adults'] ?? 2,
                'max_travelers' => 12, 'image_path' => "seed/{$p['image']}.jpg", 'gallery' => array_map(fn ($g) => "seed/{$g}.jpg", $p['gallery']),
                'highlights' => $p['highlights'], 'inclusions' => $p['inclusions'], 'exclusions' => $p['exclusions'],
                'difficulty' => str_contains($p['name'], 'Adventure') ? 'moderate' : 'easy',
                'is_featured' => $p['featured'], 'status' => 'active',
                'meta_title' => "{$p['name']} — {$p['days']} days", 'meta_description' => $p['summary'],
            ]);
            $pkg->forceFill(['rating_avg' => round(4.5 + (crc32($p['name']) % 5) / 10, 1), 'review_count' => 20 + crc32($p['name']) % 180])->saveQuietly();
            $pkg->items()->delete();
            foreach ($p['items'] as $i => [$day, $dayTitle, $type, $id, $title, $time, $nights, $hours]) {
                $morph = match (true) {
                    $type === 'accommodation' => 'hotel_room',
                    in_array($type, ['flight', 'train', 'bus', 'taxi', 'private_car', 'rental_car', 'airport_transfer', 'hotel_transfer'], true) => 'tour_transportation',
                    in_array($type, ['activity', 'attraction'], true) => 'tour_activity',
                    $type === 'guide' => 'tour_guide',
                    $type === 'meal' => 'tour_meal',
                    default => null,
                };
                $pkg->items()->create([
                    'day_number' => $day, 'day_title' => $dayTitle, 'item_type' => $type,
                    'itemable_type' => $morph && $id ? $morph : null, 'itemable_id' => $morph ? $id : null,
                    'title' => $title, 'start_time' => $time, 'nights' => $nights, 'hours' => $hours, 'sort_order' => $i + 1,
                ]);
            }
            $svc->recalculatePriceFrom($pkg);
        }
    }
}

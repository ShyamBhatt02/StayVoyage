<?php

namespace App\Enums;

enum TripItemType: string
{
    case Accommodation = 'accommodation';
    case Flight = 'flight';
    case Train = 'train';
    case Bus = 'bus';
    case Taxi = 'taxi';
    case PrivateCar = 'private_car';
    case RentalCar = 'rental_car';
    case AirportTransfer = 'airport_transfer';
    case HotelTransfer = 'hotel_transfer';
    case Activity = 'activity';
    case Attraction = 'attraction';
    case Guide = 'guide';
    case Meal = 'meal';
    case Other = 'other';

    /** Types priced from the tour_transportations catalogue. */
    public static function transport(): array
    {
        return [
            self::Flight->value, self::Train->value, self::Bus->value, self::Taxi->value, self::PrivateCar->value,
            self::RentalCar->value, self::AirportTransfer->value, self::HotelTransfer->value,
        ];
    }

    public function group(): string
    {
        return match (true) {
            $this === self::Accommodation => 'accommodation',
            in_array($this->value, self::transport(), true) => in_array($this, [self::AirportTransfer, self::HotelTransfer], true) ? 'transfers' : 'transportation',
            $this === self::Activity, $this === self::Attraction => 'activities',
            $this === self::Guide => 'guides',
            $this === self::Meal => 'meals',
            default => 'other',
        };
    }
}

<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculator
{
    public function calculate(array $data): string
    {
        $rates = [
            'BASIC' => '12.00',
            'PLUS' => '20.00',
            'PREMIUM' => '30.00',
        ];

        $coverageMultipliers = [
            100000 => '1.00',
            300000 => '1.25',
            500000 => '1.50',
        ];

        $plan = $data['plan_code'];
        $coverage = (int) $data['sum_insured'];

        if (!isset($rates[$plan])) {
            throw new InvalidArgumentException('Unsupported plan code.');
        }

        if (!isset($coverageMultipliers[$coverage])) {
            throw new InvalidArgumentException('Unsupported sum insured.');
        }

        $age = Carbon::parse($data['date_of_birth'])
            ->diffInYears(Carbon::parse($data['trip_start']));

        if ($age < 18) {
            $ageMultiplier = '1.10';
        } elseif ($age <= 60) {
            $ageMultiplier = '1.00';
        } elseif ($age <= 70) {
            $ageMultiplier = '1.50';
        } else {
            $ageMultiplier = '2.00';
        }

        $destinationMultipliers = [
            'DOMESTIC' => '1.00',
            'INTERNATIONAL' => '1.80',
        ];

        $destination = $data['destination'];

        if (!isset($destinationMultipliers[$destination])) {
            throw new InvalidArgumentException('Unsupported destination.');
        }

        $start = Carbon::parse($data['trip_start']);
        $end = Carbon::parse($data['trip_end']);

        if ($end->lt($start)) {
            throw new InvalidArgumentException('Trip end cannot precede trip start.');
        }

        $days = $start->diffInDays($end) + 1;

        $premium = (float) $rates[$plan]
            * $days
            * (float) $ageMultiplier
            * (float) $destinationMultipliers[$destination]
            * (float) $coverageMultipliers[$coverage];

        return number_format(round($premium, 2), 2, '.', '');
    }
}

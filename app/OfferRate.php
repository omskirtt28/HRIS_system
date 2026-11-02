<?php
declare(strict_types=1);
/** Offer rate types (SPEC-offer-rate-type.md). Monthly is canonical; Daily/Hourly are converted with one factor set. */
final class OfferRate {
    const TYPES=['MONTHLY'=>'Monthly rate','DAILY'=>'Daily rate','HOURLY'=>'Hourly rate'];
    // NEEDS VERIFICATION: 26 days/month and 8 hours/day are John's default until confirmed (payroll hourly_divisor may define the rule).
    const DAYS_PER_MONTH=26;
    const HOURS_PER_DAY=8;
    /** @return array{monthly:float,daily:float,hourly:float} */
    public static function convert(string $type,float $amount): array {
        if(!isset(self::TYPES[$type])) throw new RuntimeException('Choose Monthly, Daily or Hourly rate.');
        if(!is_finite($amount)||$amount<=0||$amount>9999999.99) throw new RuntimeException('Enter a rate greater than zero.');
        $monthly=match($type){'MONTHLY'=>$amount,'DAILY'=>$amount*self::DAYS_PER_MONTH,'HOURLY'=>$amount*self::HOURS_PER_DAY*self::DAYS_PER_MONTH};
        $daily=$monthly/self::DAYS_PER_MONTH;
        return ['monthly'=>round($monthly,2),'daily'=>round($daily,2),'hourly'=>round($daily/self::HOURS_PER_DAY,2)];
    }
}

<?php

namespace Amsgames\LaravelShop\Traits;

/**
 * This file is part of LaravelShop,
 * A shop solution for Laravel.
 *
 * @author Alejandro Mostajo
 * @copyright Amsgames, LLC
 * @license MIT
 * @package Amsgames\LaravelShop
 */

use Shop;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

trait ShopCalculationsTrait
{
    /**
     * Property used to stored calculations.
     * @var array
     */
    private $shopCalculations = null;

    /**
     * Returns total amount of items in cart.
     *
     * @return int
     */
    public function getCountAttribute()
    {
        if (empty($this->shopCalculations)) $this->runCalculations();
        return round($this->shopCalculations->itemCount, 2);
    }

    /**
     * Returns total price of all the items in cart.
     *
     * @return float
     */
    public function getTotalPriceAttribute()
    {
        if (empty($this->shopCalculations)) $this->runCalculations();
        return round($this->shopCalculations->totalPrice, 2);
    }

    /**
     * Returns total tax of all the items in cart.
     *
     * @return float
     */
    public function getTotalTaxAttribute()
    {
        if (empty($this->shopCalculations)) $this->runCalculations();
        return round($this->shopCalculations->totalTax + ($this->totalPrice * Config::get('shop.tax')), 2);
    }

    /**
     * Returns total tax of all the items in cart.
     *
     * @return float
     */
    public function getTotalShippingAttribute()
    {
        if (empty($this->shopCalculations)) $this->runCalculations();
        return round($this->shopCalculations->totalShipping, 2);
    }

    /**
     * Returns total discount amount based on all coupons applied.
     *
     * @return float
     */
    public function getTotalDiscountAttribute()
    {
        if (empty($this->shopCalculations)) $this->runCalculations();

        return round($this->shopCalculations->totalDiscount, 2);
    }

    /**
     * Returns total amount to be charged base on total price, tax and discount.
     *
     * @return float
     */
    public function getTotalAttribute()
    {
        if (empty($this->shopCalculations)) $this->runCalculations();
        return $this->totalPrice + $this->totalTax + $this->totalShipping - $this->totalDiscount;
    }

    /**
     * Returns formatted total price of all the items in cart.
     *
     * @return string
     */
    public function getDisplayTotalPriceAttribute()
    {
        return Shop::format($this->totalPrice);
    }

    /**
     * Returns formatted total tax of all the items in cart.
     *
     * @return string
     */
    public function getDisplayTotalTaxAttribute()
    {
        return Shop::format($this->totalTax);
    }

    /**
     * Returns formatted total tax of all the items in cart.
     *
     * @return string
     */
    public function getDisplayTotalShippingAttribute()
    {
        return Shop::format($this->totalShipping);
    }

    /**
     * Returns formatted total discount amount based on all coupons applied.
     *
     * @return string
     */
    public function getDisplayTotalDiscountAttribute()
    {
        return Shop::format($this->totalDiscount);
    }

    /**
     * Returns formatted total amount to be charged base on total price, tax and discount.
     *
     * @return string
     */
    public function getDisplayTotalAttribute()
    {
        return Shop::format($this->total);
    }

    /**
     * Returns cache key used to store calculations.
     *
     * @return string.
     */
    public function getCalculationsCacheKeyAttribute()
    {
        return 'shop_' . $this->table . '_' . $this->attributes['id'] . '_calculations';
    }

    /**
     * Runs calculations.
     */
    private function runCalculations()
    {
        if (!empty($this->shopCalculations)) return $this->shopCalculations;
        $cacheKey = $this->calculationsCacheKey;
        if (Config::get('shop.cache_calculations')
            && Cache::has($cacheKey)
        ) {
            $this->shopCalculations = Cache::get($cacheKey);
            return $this->shopCalculations;
        }
        $this->shopCalculations = DB::table($this->table)
            ->select([
                DB::raw('sum(' . Config::get('shop.item_table') . '.quantity) as itemCount'),
                DB::raw('sum(' . Config::get('shop.item_table') . '.price * ' . Config::get('shop.item_table') . '.quantity) as totalPrice'),
                DB::raw('sum(' . Config::get('shop.item_table') . '.tax * ' . Config::get('shop.item_table') . '.quantity) as totalTax'),
                DB::raw('sum(' . Config::get('shop.item_table') . '.shipping * ' . Config::get('shop.item_table') . '.quantity) as totalShipping')
            ])
            ->join(
                Config::get('shop.item_table'),
                Config::get('shop.item_table') . '.' . ($this->table == Config::get('shop.order_table') ? 'order_id' : $this->table . '_id'),
                '=',
                $this->table . '.id'
            )
            ->where($this->table . '.id', $this->attributes['id'])
            ->first();

        //Add total discount, edit totalPrice
        $PivotOn = get_class($this);
        $Discounts = $this->calculateDiscounts($PivotOn, $this->attributes['id']);
        if(get_class($this)=='App\Order')
        {
            $this->shopCalculations->totalShipping=$this->shipping;
        }
        //Remove Cash Discount
        //Remove % Discount
        $PercentageDiscount = $this->shopCalculations->totalPrice * $Discounts['percent'];
        $this->shopCalculations->CashDiscount = $Discounts['cash'];
        $this->shopCalculations->PercentDiscount = $Discounts['percent'];
        $this->shopCalculations->PercentCashDiscount = $PercentageDiscount;
        $this->shopCalculations->totalDiscount = $Discounts['cash'] + $PercentageDiscount;

        if (Config::get('shop.cache_calculations')) {
            Cache::put(
                $cacheKey,
                $this->shopCalculations,
                Config::get('shop.cache_calculations_minutes')
            );
        }
        return $this->shopCalculations;
    }

    /**
     * Resets cart calculations.
     */
    private function resetCalculations ()
    {
        $this->shopCalculations = null;
        if (Config::get('shop.cache_calculations')) {
            Cache::forget($this->calculationsCacheKey);
        }
    }

    /**
     * Calculates the discount required on a class with a specified id
     * @param $PivotOn
     * @param $id
     * @return array
     */
    private function calculateDiscounts($PivotOn, $id)
    {    $Discount = [
            'cash'    => 0.00,
            'percent' => 0.00,
        ];
        $Class = new $PivotOn;
        $Coupons = $Class::find($id);

        if($Coupons){
            $Coupons=$Coupons->coupons;
            if($Coupons){
                foreach ($Coupons as $coupon)
                {
                    if (!is_null($coupon->value) && $coupon->value != 0.00 )
                        $Discount['cash'] += $coupon->value;
                    else
                        $Discount['percent'] += $coupon->discount;
                }
            }
        }

        return $Discount;
    }

}
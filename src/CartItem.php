<?php

namespace LukePOLO\LaraCart;

use Illuminate\Support\Arr;
use LukePOLO\LaraCart\Contracts\CouponContract;
use LukePOLO\LaraCart\Exceptions\ModelNotFound;
use LukePOLO\LaraCart\Traits\CartOptionsMagicMethodsTrait;
use Money\Money;

/**
 * Class CartItem.
 *
 * @property int    id
 * @property int    qty
 * @property float  tax
 * @property float  price
 * @property string name
 * @property array  options
 * @property bool   taxable
 */
class CartItem
{
    const ITEM_ID = 'id';
    const ITEM_QTY = 'qty';
    const ITEM_TAX = 'tax';
    const ITEM_NAME = 'name';
    const ITEM_PRICE = 'price';
    const ITEM_TAXABLE = 'taxable';
    const ITEM_OPTIONS = 'options';

    use CartOptionsMagicMethodsTrait;

    protected $itemHash;
    protected $itemModel;
    protected $excludeFromHash;
    protected $itemModelRelations;

    public $locale;
    public $coupon;
    public $lineItem;
    public $active = true;
    public $subItems = [];
    public $currencyCode;

    /**
     * This tracks the discounts per item , we do this so we can properly
     * round taxes when you have a qty > 0.
     */
    public $discounted = [];

    /**
     * CartItem constructor.
     *
     * @param            $id
     * @param            $name
     * @param int        $qty
     * @param string     $price
     * @param array      $options
     * @param bool       $taxable
     * @param bool|false $lineItem
     */
    public function __construct($id, $name, $qty, $price, $options = [], $taxable = true, $lineItem = false)
    {
        $this->id = $id;
        $this->qty = $qty;
        $this->name = $name;
        $this->taxable = $taxable;
        $this->lineItem = $lineItem;
        $this->price = (config('laracart.prices_in_cents', false) === true ? intval($price) : floatval($price));
        $this->tax = config('laracart.tax');
        $this->itemModel = config('laracart.item_model', null);
        $this->itemModelRelations = config('laracart.item_model_relations', []);
        $this->excludeFromHash = config('laracart.exclude_from_hash', []);

        foreach ($options as $option => $value) {
            $this->$option = $value;
        }

        $this->tax = $this->options["tax"] ?? config('laracart.tax');
    }

    /**
     * Generates a hash based on the cartItem array.
     *
     * @param bool $force
     *
     * @return string itemHash
     */
    public function generateHash($force = false)
    {
        if ($this->lineItem === false) {
            $this->itemHash = null;

            $cartItemArray = (array) clone $this;

            unset($cartItemArray['discounted']);
            unset($cartItemArray['options']['qty']);

            foreach ($this->excludeFromHash as $option) {
                unset($cartItemArray['options'][$option]);
            }

            ksort($cartItemArray['options']);

            $this->itemHash = app(LaraCart::HASH)->hash($cartItemArray);
        } elseif ($force || empty($this->itemHash) === true) {
            $this->itemHash = app(LaraCart::RANHASH);
        }

        app('events')->dispatch(
            'laracart.updateItem',
            [
                'item'    => $this,
                'newHash' => $this->itemHash,
            ]
        );

        return $this->itemHash;
    }

    /**
     * Gets the hash for the item.
     *
     * @return mixed
     */
    public function getHash()
    {
        return $this->itemHash;
    }

    /**
     * Search for matching options on the item.
     *
     * @return mixed
     */
    public function find($data)
    {
        foreach ($data as $key => $value) {
            if ($this->$key !== $value) {
                return false;
            }
        }

        return $this;
    }

    /**
     * Finds a sub item by its hash.
     *
     * @param $subItemHash
     *
     * @return mixed
     */
    public function findSubItem($subItemHash)
    {
        return Arr::get($this->subItems, $subItemHash);
    }

    /**
     * Adds an sub item to a item.
     *
     * @param array $subItem
     *
     * @return CartSubItem
     */
    public function addSubItem(array $subItem)
    {
        $subItem = new CartSubItem($subItem);

        $this->subItems[$subItem->getHash()] = $subItem;

        $this->update();

        return $subItem;
    }

    /**
     * Removes a sub item from the item.
     *
     * @param $subItemHash
     */
    public function removeSubItem($subItemHash)
    {
        unset($this->subItems[$subItemHash]);

        $this->update();
    }

    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Gets the price of the item with or without tax, with the proper format.
     *
     * @return string
     */
    public function total($format = false)
    {
        $total = 0;

        if ($this->active) {
            $subTotalPerItem = 0;
            for ($qty = 0; $qty < $this->qty; $qty++) {
                $subTotalPerItem += $this->subTotalPerItem(false);
            }

            $total += LaraCart::formatMoney($subTotalPerItem + $this->taxSummary(), null, null, $format);

            $total -= $this->getDiscount(false);

            if ($total < 0) {
                $total = 0;
            }
        }

        return $total;
    }

    public function finalTotal($format = false){
        $total = 0;

        if ($this->active) {
            $price = ($this->taxable ? $this->price : 0);
            $CheckBruttoTotal = (round($price * (1+($this->tax)), 2) * $this->qty);
            $Netto = round(($price * $this->qty),2);
            $Brutto = round(($Netto*(1+($this->tax))),2);
            if($Brutto != $CheckBruttoTotal){
                $Brutto = $CheckBruttoTotal;
            }
            $total = LaraCart::formatMoney(($Brutto), null, null, $format);
        }
        return $total;
    }

    public function taxTotal()
    {
        $total = 0;

//        foreach ($this->taxSummary() as $itemSummary) {
//            $total += array_sum($itemSummary);
//        }

        return $this->taxSummary();
    }


    /**
     * Gets the sub total of the item based on the qty.
     *
     * @param bool $format
     *
     * @return float|string
     */
    public function subTotal()
    {
        return $this->subTotalPerItem() * $this->qty;
    }

    public function subTotalPerItem()
    {
        $subTotal = $this->active ? ($this->price + $this->subItemsTotal()) : 0;

        return $subTotal;
    }

    /**
     * Gets the totals for the options.
     *
     * @return float
     */
    public function subItemsTotal()
    {
        $total = 0;

        foreach ($this->subItems as $subItem) {
            $total += $subItem->subTotal(false);
        }

        return $total;
    }

    /**
     * Gets the discount of an item.
     *
     * @return string
     */
    public function getDiscount()
    {
        return array_sum($this->discounted);
    }


    /**
     * @param CouponContract $coupon
     *
     * @return $this
     */
    public function addCoupon(CouponContract $coupon)
    {
        $coupon->appliedToCart = false;
        app('laracart')->addCoupon($coupon);
        $this->coupon = $coupon;

        return $this;
    }

    public function taxSummary()
    {
        $taxed = 0;
        $toTax = 0;
//        for ($qty = 0; $qty < $this->qty; $qty++) {
//            // keep track of what is discountable
//            $discountable = $this->discounted[$qty] ?? 0;
//            $price = ($this->taxable ? $this->price : 0);
//
//            $taxable = $price - ($discountable > 0 ? $discountable : 0);
//            // track what has been discounted so far
//            $discountable = $discountable - $price;
//
//            $toTax += $taxable;
//        }

        $toTax1 = 0;
        for ($qty = 0; $qty < $this->qty; $qty++) {
            // keep track of what is discountable
            $discountable = $this->discounted[$qty] ?? 0;
            $price = ($this->taxable ? $this->price : 0);

            $taxable = $price - ($discountable > 0 ? $discountable : 0);
            // track what has been discounted so far
            $discountable = $discountable - $price;

            $toTax1 += $taxable;
        }

        $toTax1 = $toTax1 / $this->qty;

        $CheckBruttoTotal = (round($toTax1 * (1+($this->tax)), 2) * $this->qty);
        $Netto = round(($toTax1 * $this->qty),2);
        $Brutto = round(($Netto*(1+($this->tax))),2);
        if($Brutto != $CheckBruttoTotal){
            $Brutto = $CheckBruttoTotal;
        }
        $taxed = round(($Brutto-$Netto),2);


        return $taxed;
    }


    /**
     * Sets the related model to the item.
     *
     * @param       $itemModel
     * @param array $relations
     *
     * @throws ModelNotFound
     */
    public function setModel($itemModel, $relations = [])
    {
        if (!class_exists($itemModel)) {
            throw new ModelNotFound('Could not find relation model');
        }

        $this->itemModel = $itemModel;
        $this->itemModelRelations = $relations;
    }

    /**
     * Gets the items model class.
     */
    public function getItemModel()
    {
        return $this->itemModel;
    }

    /**
     * Returns a Model.
     *
     * @throws ModelNotFound
     */
    public function getModel()
    {
        $itemModel = (new $this->itemModel())->with($this->itemModelRelations)->find($this->id);

        if (empty($itemModel)) {
            throw new ModelNotFound('Could not find the item model for '.$this->id);
        }

        return $itemModel;
    }

    /**
     *  A way to find sub items.
     *
     * @param $data
     *
     * @return array
     */
    public function searchForSubItem($data)
    {
        $matches = [];

        foreach ($this->subItems as $subItem) {
            if ($subItem->find($data)) {
                $matches[] = $subItem;
            }
        }

        return $matches;
    }

    public function disable()
    {
        $this->active = false;
        $this->update();
    }

    public function enable()
    {
        $this->active = true;
        $this->update();
    }

    public function update()
    {
        $this->generateHash();
        app('laracart')->update();
    }
}

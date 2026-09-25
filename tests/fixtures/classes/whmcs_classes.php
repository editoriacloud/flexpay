<?php
// Minimal stand-ins for WHMCS 8.2+ classes used by the gateway module.

namespace WHMCS\Exception\Module {
    class InvalidConfiguration extends \Exception {}
}

namespace WHMCS\Module\Gateway {
    class Balance
    {
        public $amount; public $currency; public $label; public $color;
        public static function factory($amount, $currency, $label = 'status.available', $color = '#5dc560')
        {
            $b = new self();
            [$b->amount, $b->currency, $b->label, $b->color] = [$amount, $currency, $label, $color];
            return $b;
        }
    }
    class BalanceCollection
    {
        public $items = [];
        public static function factoryFromItems(Balance ...$items)
        {
            $c = new self();
            $c->items = $items;
            return $c;
        }
    }
}

namespace WHMCS\Billing\Payment\Transaction {
    class Information
    {
        public $data = [];
        public function __call($name, $args)
        {
            $this->data[lcfirst(substr($name, 3))] = $args[0] ?? null;
            return $this;
        }
    }
}

namespace WHMCS {
    class Carbon extends \DateTime
    {
        public static function parse($s)
        {
            return new self($s);
        }
    }
}

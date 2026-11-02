<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class OrderDateAndTime extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'order_date_and_time';
        $this->label = __('Date and Time', 'woo-discount-rules-pro');
        $this->group = __('Date & Time', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/DateTime/date-and-time.php';
    }

    public function check($cart, $options)
    {
        if (isset($options->from) && isset($options->to)) {
            if (!empty($options->from) && !empty($options->to)) {
                return ($this->doComparisionOperation('greater_than_or_equal', current_time('timestamp'), strtotime($options->from)) && $this->doComparisionOperation('less_than_or_equal', current_time('timestamp'), strtotime($options->to)));
            } elseif (!empty($options->from) && empty($options->to)) {
                return $this->doComparisionOperation('greater_than_or_equal', current_time('timestamp'), strtotime($options->from));
            } elseif (empty($options->from) && !empty($options->to)) {
                return $this->doComparisionOperation('less_than_or_equal', current_time('timestamp'), strtotime($options->to));
            } else {
                return false;
            }
        }
        return false;
    }
}
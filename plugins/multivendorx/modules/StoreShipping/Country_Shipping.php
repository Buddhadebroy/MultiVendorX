<?php
/**
 * Class Country_Shipping
 *
 * @package multivendorx
 */

namespace MultiVendorX\StoreShipping;

use MultiVendorX\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * MultiVendorX Country Shipping Module.
 *
 * @class       Module class
 * @version     5.0.0
 * @author      MultiVendorX
 */
class Country_Shipping extends \WC_Shipping_Method {

    /**
     * Constructor for your shipping class
     *
     * @access public
     *
     * @return void
     */
    public function __construct() {
        $this->id = 'multivendorx-country-shipping';

        $settings = MultiVendorX()->setting->get_setting( 'shipping_modules', array() )['country-wise-shipping'] ?? array();

        $this->enabled = ! empty( $settings['enable'] ) ? 'yes' : 'no';
        $this->title   = $settings['country_shipping_method_name'] ?? $this->get_option( 'title' ) ?: __( 'Shipping Cost', 'multivendorx' );

        $taxable          = MultiVendorX()->setting->get_setting( 'taxable', array() );
        $this->tax_status = in_array( 'taxable', $taxable, true ) ? 'taxable' : 'none';
    }

    /**
     * Override admin options to show a custom message instead of settings
     */
    public function admin_options() {
        $url = admin_url( 'admin.php?page=multivendorx#&tab=settings&subtab=shipping' );
		?>
        <h2><?php echo esc_html( $this->method_title ); ?></h2>
        <p>
            <?php
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: URL to MultiVendorX shipping settings page */
                    __( 'This shipping method is fully managed in the <a href="%s" target="_blank">MultiVendorX Shipping Settings</a>.', 'multivendorx' ),
                    esc_url( $url )
                )
            );
            ?>
        </p>
		<?php
    }

    /**
     * Checking is gateway enabled or not
     */
    public function is_method_enabled() {
        return 'yes' === $this->enabled;
    }

    /**
     * Calculate shipping.
     *
     * @param array $package Package.
     *
     * @return void
     */
    public function calculate_shipping( $package = array() ) {
        $products = $package['contents'] ?? array();
        $store_id = (int) ( $package['store_id'] ?? 0 );

        if ( empty( $products ) || ! $store_id || ! self::is_shipping_enabled_for_seller( $store_id ) ) {
            return;
        }

        $result = $this->calculate_per_seller(
            $products,
            $package['destination']['country'] ?? '',
            $package['destination']['state'] ?? '',
            $store_id
        );

        if ( $result['is_shipping_available'] ) {
            $this->add_store_shipping_rates( $store_id, $result['amount'], $result['is_free_shipping'] );
        }
    }

    /**
     * Check if shipping for this product is enabled
     *
     * @param  int $store_id Store ID.
     *
     * @return boolean
     */
    public static function is_shipping_enabled_for_seller( $store_id ) {
        $store = new \MultiVendorX\Store\Store( $store_id );
        return Utill::STORE_SETTINGS_KEYS['shipping_by_country']
            === ( $store->meta_data[ Utill::STORE_SETTINGS_KEYS['shipping_options'] ] ?? '' );
    }

    /**
     * Calculate shipping cost for a single store.
     *
     * @param array  $products            Store products.
     * @param string $destination_country Destination country.
     * @param string $destination_state   Destination state.
     * @param int    $store_id            Store ID.
     *
     * @return array
     */
    public function calculate_per_seller( $products, $destination_country, $destination_state, $store_id ) {
        $meta = ( new \MultiVendorX\Store\Store( $store_id ) )->meta_data;
        $keys = Utill::STORE_SETTINGS_KEYS;

        $default_cost = (float) ( $meta[ $keys['country_shipping_type_price'] ] ?? 0 );
        $product_cost = (float) ( $meta[ $keys['country_additional_product'] ] ?? 0 );
        $qty_cost     = (float) ( $meta[ $keys['country_additional_qty'] ] ?? 0 );
        $free_amount  = apply_filters(
            'multivendorx_free_shipping_minimum_order_amount',
            (float) ( $meta[ $keys['country_free_shipping_amount'] ] ?? 0 ),
            $store_id
        );

        $rates_raw = $meta[ $keys['country_shipping_rates'] ] ?? array();
        $rates     = (array) ( is_string( $rates_raw ) ? json_decode( $rates_raw, true ) : $rates_raw );

        // Find matching country / "everywhere" rate.
        $country_rate = $everywhere_rate = null;
        foreach ( $rates as $rate ) {
            if ( empty( $rate['country'] ) ) {
                continue;
            }
            if ( 'everywhere' === $rate['country'] ) {
                $everywhere_rate = $rate;
            } elseif ( $destination_country === $rate['country'] ) {
                $country_rate = $rate;
            }
        }

        // Destination eligibility + location cost.
        $location_cost = 0;
        $available     = true;

        if ( ! empty( $rates ) ) {
            if ( $country_rate ) {
                $location_cost = (float) ( $country_rate['cost'] ?? 0 );

                if ( $destination_state && ! empty( $country_rate['states'] ) ) {
                    foreach ( $country_rate['states'] as $state ) {
                        if ( $destination_state === ( $state['state'] ?? '' ) ) {
                            $location_cost += (float) ( $state['cost'] ?? 0 );
                            break;
                        }
                    }
                }
            } elseif ( $everywhere_rate ) {
                $location_cost = (float) ( $everywhere_rate['cost'] ?? 0 );
            } else {
                $available = false; // Country rules exist but destination isn't covered.
            }
        }

        if ( ! $available ) {
            return array(
				'amount'                => 0,
				'is_shipping_available' => false,
				'is_free_shipping'      => false,
			);
        }

        // Product totals (skipping virtual/downloadable) + qty cost.
        $products_total = 0;
        $qty_total_cost = 0;
        $physical_count = 0;
        $consider_tax   = apply_filters( 'multivendorx_free_shipping_threshold_consider_tax', true );

        foreach ( $products as $product ) {
            $product_id = $product['variation_id'] ?? $product['product_id'] ?? 0;

            if ( 'yes' === get_post_meta( $product_id, '_virtual', true ) || 'yes' === get_post_meta( $product_id, '_downloadable', true ) ) {
                continue;
            }
            ++$physical_count;

            $qty = (int) ( $product['quantity'] ?? 1 );
            if ( $qty > 1 ) {
                $qty_total_cost += ( $qty - 1 ) * $qty_cost;
            }

            $subtotal = (float) ( $product['line_subtotal'] ?? 0 );
            $discount = $subtotal - (float) ( $product['line_total'] ?? 0 );

            if ( $consider_tax ) {
                $subtotal += (float) ( $product['line_subtotal_tax'] ?? 0 );
                $discount += (float) ( $product['line_subtotal_tax'] ?? 0 ) - (float) ( $product['line_tax'] ?? 0 );
            }

            $products_total += round( $subtotal - $discount, wc_get_price_decimals() );
        }

        // Free shipping — only when destination is covered AND threshold is met.
        if ( $free_amount && $products_total >= $free_amount ) {
            return array(
				'amount'                => 0,
				'is_shipping_available' => true,
				'is_free_shipping'      => true,
			);
        }

        $additional_product_cost = max( 0, $physical_count - 1 ) * $product_cost;
        $amount                  = $default_cost + $qty_total_cost + $additional_product_cost + $location_cost;

        return array(
            'amount'                => apply_filters(
                'multivendorx_shipping_country_calculate_amount',
                $amount,
                compact( 'default_cost', 'qty_total_cost', 'additional_product_cost', 'location_cost' ),
                $products,
                $destination_country,
                $destination_state
            ),
            'is_shipping_available' => true,
            'is_free_shipping'      => false,
        );
    }

    /**
     * Add shipping rates for a store.
     *
     * @param int   $store_id         Store ID.
     * @param float $amount           Shipping amount.
     * @param bool  $is_free_shipping Whether free shipping applies.
     *
     * @return void
     */
    public function add_store_shipping_rates( $store_id, $amount, $is_free_shipping = false ) {
        $meta     = ( new \MultiVendorX\Store\Store( $store_id ) )->meta_data;
        $tax_rate = 'none' === $this->tax_status ? false : apply_filters( 'multivendorx_is_apply_tax_on_shipping_rates', '' );

        $this->add_rate(
            array(
                'id'    => $this->id . ':' . $store_id,
                'label' => $is_free_shipping ? __( 'Free Shipping', 'multivendorx' ) : $this->title,
                'cost'  => $is_free_shipping ? 0 : $amount,
                'taxes' => $tax_rate,
            )
        );

        $pickup_cost = (float) ( $meta[ Utill::STORE_SETTINGS_KEYS['country_local_pickup_cost'] ] ?? 0 );

        if ( $pickup_cost > 0 ) {
            $this->add_rate(
                array(
                    'id'    => 'local_pickup:' . $store_id,
                    'label' => __( 'Pickup from Store', 'multivendorx' ),
                    'cost'  => $pickup_cost,
                    'taxes' => $tax_rate,
                )
            );
        }
    }
}
<?php
/**
 * WooCommerce checkout — lets the site use any Woo payment gateway it already has.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the booking to the Woo cart as a hidden "Booking" product priced from the booking,
 * then syncs order status back to the booking.
 */
class OSB_Gateway_Woocommerce extends OSB_Gateway {

	/**
	 * Id.
	 *
	 * @var string
	 */
	public $id = 'woo';

	/**
	 * Hook into WooCommerce.
	 */
	public function __construct() {
		add_action(
			'before_woocommerce_init',
			static function () {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OSB_FILE, true );
				}
			}
		);

		if ( ! $this->is_configured() ) {
			return;
		}
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_cart_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_item_name' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'cart_item_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_permalink', array( $this, 'cart_item_permalink' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line_item' ), 10, 3 );
		add_action( 'woocommerce_new_order', array( $this, 'link_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 10, 4 );
		add_action( 'woocommerce_remove_cart_item', array( $this, 'cart_item_removed' ), 10, 2 );
	}

	/**
	 * Admin name.
	 *
	 * @return string
	 */
	public function admin_name() {
		return __( 'WooCommerce checkout', 'osam-bookings' );
	}

	/**
	 * Woo active?
	 *
	 * @return bool
	 */
	public function is_configured() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Hidden placeholder product.
	 *
	 * @return int
	 */
	public function product_id() {
		$id = (int) get_option( 'osb_wc_product_id' );
		if ( $id && 'product' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
			return $id;
		}
		$product = new WC_Product_Simple();
		$product->set_name( __( 'Booking', 'osam-bookings' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_regular_price( '0' );
		$product->set_tax_status( apply_filters( 'osb_wc_tax_status', 'none' ) );
		$id = $product->save();
		update_option( 'osb_wc_product_id', $id );
		return $id;
	}

	/**
	 * Put the booking in the cart.
	 *
	 * @param object $booking    Booking.
	 * @param string $return_url Page URL.
	 * @return array|WP_Error
	 */
	public function process( $booking, $return_url ) {
		if ( function_exists( 'wc_load_cart' ) && ( null === WC()->cart ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart ) {
			return new WP_Error( 'osb_woo', __( 'The shop cart is unavailable.', 'osam-bookings' ) );
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		$key = WC()->cart->add_to_cart(
			$this->product_id(),
			1,
			0,
			array(),
			array(
				'osb_booking_id' => (int) $booking->id,
				'osb_token'      => $booking->token,
			)
		);
		if ( ! $key ) {
			return new WP_Error( 'osb_woo', __( 'Could not add the booking to the cart.', 'osam-bookings' ) );
		}

		// Pre-fill checkout with what the customer already typed.
		if ( WC()->customer ) {
			$parts = explode( ' ', trim( $booking->customer_name ), 2 );
			WC()->customer->set_billing_first_name( $parts[0] );
			WC()->customer->set_billing_last_name( isset( $parts[1] ) ? $parts[1] : '' );
			WC()->customer->set_billing_email( $booking->customer_email );
			WC()->customer->set_billing_phone( $booking->customer_phone );
			WC()->customer->save();
		}
		WC()->cart->calculate_totals();
		WC()->cart->set_session();

		return array( 'redirect' => apply_filters( 'osb_wc_redirect_url', wc_get_checkout_url(), $booking ) );
	}

	/**
	 * Booking row for a cart item.
	 *
	 * @param array $item Cart item.
	 * @return object|null
	 */
	private function cart_booking( $item ) {
		if ( empty( $item['osb_booking_id'] ) ) {
			return null;
		}
		$booking = OSB_DB::get( 'bookings', (int) $item['osb_booking_id'] );
		return ( $booking && hash_equals( $booking->token, (string) $item['osb_token'] ) ) ? $booking : null;
	}

	/**
	 * Price each booking line from the booking amount.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public function set_cart_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			$booking = $this->cart_booking( $item );
			if ( $booking ) {
				$item['data']->set_price( OSB_Repeat::charge_total( $booking ) );
			}
		}
	}

	/**
	 * Show booking date/time under the cart line.
	 *
	 * @param array $data Data.
	 * @param array $item Item.
	 * @return array
	 */
	public function cart_item_data( $data, $item ) {
		$booking = $this->cart_booking( $item );
		if ( $booking ) {
			$d      = OSB_Bookings::details( $booking );
			$data[] = array(
				'key'   => __( 'When', 'osam-bookings' ),
				'value' => $d['date'] . ' ' . $d['time'],
			);
			if ( $d['staff_name'] ) {
				$data[] = array(
					'key'   => __( 'With', 'osam-bookings' ),
					'value' => $d['staff_name'],
				);
			}
			if ( $d['seats'] > 1 ) {
				$data[] = array(
					'key'   => OSB_Settings::label( 'seats' ),
					'value' => $d['seats'],
				);
			}
		}
		return $data;
	}

	/**
	 * Use the service/event name as the line name.
	 *
	 * @param string $name Name.
	 * @param array  $item Item.
	 * @return string
	 */
	public function cart_item_name( $name, $item ) {
		$booking = $this->cart_booking( $item );
		if ( $booking ) {
			$d = OSB_Bookings::details( $booking );
			return esc_html( $d['item_name'] );
		}
		return $name;
	}

	/**
	 * Fixed quantity.
	 *
	 * @param string $html Html.
	 * @param string $key  Key.
	 * @param array  $item Item.
	 * @return string
	 */
	public function cart_item_quantity( $html, $key, $item ) {
		return ! empty( $item['osb_booking_id'] ) ? '1' : $html;
	}

	/**
	 * No product link for bookings.
	 *
	 * @param string $link Link.
	 * @param array  $item Item.
	 * @return string
	 */
	public function cart_item_permalink( $link, $item ) {
		return ! empty( $item['osb_booking_id'] ) ? '' : $link;
	}

	/**
	 * Drop bookings whose hold has expired.
	 */
	public function validate_cart() {
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( empty( $item['osb_booking_id'] ) ) {
				continue;
			}
			$booking = $this->cart_booking( $item );
			if ( ! $booking || 'pending_payment' !== $booking->status ) {
				WC()->cart->remove_cart_item( $key );
				wc_add_notice( __( 'A booking in your basket has expired and was removed. Please book again.', 'osam-bookings' ), 'error' );
			}
		}
	}

	/**
	 * Store booking id on the order line.
	 *
	 * @param WC_Order_Item_Product $line   Line.
	 * @param string                $key    Cart key.
	 * @param array                 $values Cart values.
	 */
	public function order_line_item( $line, $key, $values ) {
		$booking = $this->cart_booking( $values );
		if ( ! $booking ) {
			return;
		}
		$d = OSB_Bookings::details( $booking );
		$line->set_name( $d['item_name'] );
		$line->add_meta_data( '_osb_booking_id', (int) $booking->id, true );
		$line->add_meta_data( __( 'Booking', 'osam-bookings' ), '#' . $booking->id, true );
		$line->add_meta_data( __( 'When', 'osam-bookings' ), $d['date'] . ' ' . $d['time'], true );
		if ( $d['staff_name'] ) {
			$line->add_meta_data( __( 'With', 'osam-bookings' ), $d['staff_name'], true );
		}
	}

	/**
	 * Booking ids on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return int[]
	 */
	private function order_booking_ids( $order ) {
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			$id = (int) $item->get_meta( '_osb_booking_id' );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Remember which order holds the booking (classic and block checkout).
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 */
	public function link_order( $order_id, $order = null ) {
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $this->order_booking_ids( $order ) as $booking_id ) {
			OSB_DB::update( 'bookings', $booking_id, array( 'wc_order_id' => (int) $order->get_id() ) );
		}
	}

	/**
	 * Sync order status to bookings.
	 *
	 * @param int      $order_id Order.
	 * @param string   $from     From.
	 * @param string   $to       To.
	 * @param WC_Order $order    Order.
	 */
	public function order_status_changed( $order_id, $from, $to, $order ) {
		foreach ( $this->order_booking_ids( $order ) as $booking_id ) {
			$booking = OSB_DB::get( 'bookings', $booking_id );
			if ( ! $booking ) {
				continue;
			}
			if ( ! $booking->wc_order_id ) {
				OSB_DB::update( 'bookings', $booking_id, array( 'wc_order_id' => (int) $order_id ) );
			}
			if ( in_array( $to, array( 'processing', 'completed' ), true ) ) {
				OSB_Bookings::mark_paid( $booking, $this->id, 'wc-' . $order_id . '-' . $booking_id, $booking->amount, array( 'order_id' => $order_id ) );
			} elseif ( in_array( $to, array( 'cancelled', 'failed' ), true ) && 'pending_payment' === $booking->status ) {
				OSB_Bookings::payment_failed( $booking_id );
			} elseif ( in_array( $to, array( 'cancelled', 'failed' ), true ) && in_array( $booking->status, array( 'pending', 'confirmed' ), true ) ) {
				OSB_Bookings::set_status( $booking_id, 'cancelled' );
			} elseif ( 'refunded' === $to ) {
				OSB_DB::update( 'bookings', $booking_id, array( 'payment_status' => 'refunded' ) );
				if ( class_exists( 'OSB_Invoices' ) ) {
					OSB_Invoices::on_refunded( $booking_id );
				}
				OSB_Bookings::set_status( $booking_id, 'cancelled' );
			}
		}
	}

	/**
	 * Release the slot if the customer removes the booking from the basket.
	 *
	 * @param string  $key  Cart key.
	 * @param WC_Cart $cart Cart.
	 */
	public function cart_item_removed( $key, $cart ) {
		$item    = isset( $cart->cart_contents[ $key ] ) ? $cart->cart_contents[ $key ] : array();
		$booking = $this->cart_booking( $item );
		if ( $booking && 'pending_payment' === $booking->status && ! $booking->wc_order_id ) {
			OSB_Bookings::payment_failed( $booking->id, false ); // Removed on purpose: no "not completed" email.
		}
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function settings_fields() {
		return array(
			'woo_title' => array(
				__( 'Title shown to customers', 'osam-bookings' ),
				'text',
				null,
				__( 'Sends the customer to the WooCommerce checkout, so any gateway set up in WooCommerce (Stripe, PayPal, Klarna, BACS…) can be used. Bank transfer / cheque orders keep the slot held until you mark the order Processing.', 'osam-bookings' ),
			),
		);
	}
}

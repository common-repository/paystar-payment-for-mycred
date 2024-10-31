<?php

/*
Plugin Name: paystar-payment-for-mycred
Plugin URI: https://paystar.ir
Description: paystar-payment-for-mycred
Version: 1.0
Author: ماژول بانک
Author URI: https://www.modulebank.ir
Text Domain: paystar-payment-for-mycred
Domain Path: /languages
 */

add_action('mycred_load_hooks', 'mycred_paystar_init', 0);
function mycred_paystar_init()
{
	load_plugin_textdomain('paystar-payment-for-mycred', false, basename(dirname(__FILE__)) . '/languages');
	__('paystar-payment-for-mycred', 'paystar-payment-for-mycred');
	add_filter( 'mycred_setup_gateways', 'add_myCRED_Payment_Gateway_PayStar' );
	function add_myCRED_Payment_Gateway_PayStar($gateways)
	{
		$gateways['paystar'] = array(
			'title'    => __( 'PayStar', 'paystar-payment-for-mycred' ),
			'callback' => array('myCRED_Payment_Gateway_PayStar')
		);
		return $gateways;
	}
	if (class_exists('myCRED_Payment_Gateway'))
	{
		class myCRED_Payment_Gateway_PayStar extends myCRED_Payment_Gateway
		{
			function __construct($gateway_prefs)
			{
				$types = mycred_get_types();
				$default_exchange = array();
				foreach ( $types as $type => $label )
					$default_exchange[ $type ] = 1;
				parent::__construct(array(
						'id'                  => 'paystar',
						'label'               => __( 'PayStar', 'paystar-payment-for-mycred' ),
						'gateway_logo_url'    => plugin_dir_url(__FILE__).'images/logo.png',
						'defaults'            => array(
							'paystar_terminal' => '',
							'exchange'         => $default_exchange
						)
					), $gateway_prefs);
			}

			public function prep_sale( $new_transaction = false )
			{
				if ( ! isset( $this->prefs['paystar_terminal'] ) || empty( $this->prefs['paystar_terminal'] ) )
					wp_die( __( 'Please setup this gateway before attempting to make a purchase!', 'paystar-payment-for-mycred' ) );
				require_once(dirname(__FILE__) . '/paystar_payment_helper.class.php');
				$p = new PayStar_Payment_Helper($this->prefs['paystar_terminal']);
				$r = $p->paymentRequest(array(
						'amount'   => intval(ceil($this->cost)),
						'order_id' => $this->transaction_id . '#' . time(),
						'callback' => $this->callback_url()."&gateway=paystar&custom=".$this->transaction_id,
					));
				if ($r)
				{
					$this->redirect_to = 'https://core.paystar.ir/api/pardakht/payment';
					$this->redirect_fields = array('token' => esc_html($p->data->token));
				}
				else
				{
					wp_die( esc_html($p->error) );
				}
			}

			function process()
			{
				if ( isset( $_POST['status'], $_POST['order_id'], $_POST['ref_num'] ) )
				{
					$post_status = sanitize_text_field($_POST['status']);
					$post_order_id = sanitize_text_field($_POST['order_id']);
					$post_ref_num = sanitize_text_field($_POST['ref_num']);
					$post_tracking_code = sanitize_text_field($_POST['tracking_code']);
					list($pending_post_id, $nothing) = explode('#', $post_order_id);
					$pending_payment = $this->get_pending_payment( $pending_post_id );
					if ( $pending_payment !== false )
					{
						$new_call = array();
						require_once(dirname(__FILE__) . '/paystar_payment_helper.class.php');
						$p = new PayStar_Payment_Helper($this->prefs['paystar_terminal']);
						$r = $p->paymentVerify($x = array(
								'status' => $post_status,
								'order_id' => $post_order_id,
								'ref_num' => $post_ref_num,
								'tracking_code' => $post_tracking_code,
								'amount' => $pending_payment->cost
							));
						if ($r)
						{
							$new_call[] = sprintf(__("pay completed. order id : %s . payment refrence id : %s", 'paystar-payment-for-mycred'), $pending_post_id, $p->txn_id);
							if ( $this->complete_payment( $pending_payment, $p->txn_id ) )
							{
								$this->trash_pending_payment( $pending_post_id );
								header('location: '.$this->get_thankyou());
								exit;die;
							}
							else
							{
								$new_call[] = __( 'Failed to credit users account', 'paystar-payment-for-mycred' );
							}
						}
						else
						{
							$new_call[] = esc_html($p->error);
						}
					}
					$this->log_call( $pending_post_id, $new_call );
					header('location: '.$this->get_cancelled( $pending_post_id ));
					exit;die;
				}
			}

			public function ajax_buy() {
				$content  = $this->checkout_header();
				$content .= $this->checkout_logo();
				$content .= $this->checkout_order();
				$content .= $this->checkout_cancel();
				$content .= $this->checkout_footer();
				$this->send_json( $content );
			}

			public function checkout_page_body() {
				echo $this->checkout_header();
				echo $this->checkout_logo( false );
				echo $this->checkout_order();
				echo $this->checkout_cancel();
				echo $this->checkout_footer();
			}

			function preferences()
			{
				?>
				<label class="subheader" for="<?php echo esc_html($this->field_id('paystar_terminal')); ?>"><?php _e( 'PayStar Terminal', 'paystar-payment-for-mycred' ); ?></label><ol><li><div class="h2"><input type="text" name="<?php echo esc_html($this->field_name('paystar_terminal')); ?>" id="<?php echo esc_html($this->field_id('paystar_terminal')); ?>" value="<?php echo esc_html($this->prefs['paystar_terminal']); ?>" class="long" /></div></li></ol>
				<label class="subheader"><?php _e( 'Exchange Rates', 'paystar-payment-for-mycred' ); ?></label><ol><?php $this->exchange_rate_setup(); ?></ol>
				<?php
			}
		}
	}
}

?>
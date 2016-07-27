<?php

// Plugify_GForm_Braintree class

final class Plugify_GForm_Braintree extends GFPaymentAddOn {

	protected $_version = '1.0';

  protected $_min_gravityforms_version = '2.0.3';
  protected $_slug = 'gravity-forms-braintree';
  protected $_path = 'gravity-forms-braintree/lib/class.plugify-gform-braintree.php';
  protected $_full_path = __FILE__;
  protected $_title = 'Braintree';
  protected $_short_title = 'Braintree';
  protected $_requires_credit_card = true;
  protected $_supports_callbacks = false;
  protected $_enable_rg_autoupgrade = true;

	/**
	* Class constructor. Send __construct call to parent
	* @since 1.0
	* @return void
	*/
	public function __construct () {

		// Build parent
		parent::__construct();

	}

	/**
	* Override init_frontend to assign front end based filters and actions required for operation
	*
	* @since 1.0
	* @return void
	*/
	public function init_frontend () {

		// init_frontend on GFPaymentAddOn
		parent::init_frontend();

	}

	/**
	* After form has been submitted, send CC details to Braintree and ensure the card is going to work
	* If not, void the validation result (processed elsewhere) and have the submit the form again
	*
	* @param $feed - Current configured payment feed
	* @param $submission_data - Contains form field data submitted by the user as well as payment information (i.e. payment amount, setup fee, line items, etc...)
	* @param $form - Current form array containing all form settings
	* @param $entry - Current entry array containing entry information (i.e data submitted by users). NOTE: the entry hasn't been saved to the database at this point, so this $entry object does not have the "ID" property and is only a memory representation of the entry.
	* @return array - Return an $authorization array in the following format:
	* [
	*  "is_authorized" => true|false,
	*  "error_message" => "Error message",
	*  "transaction_id" => "XXX",
	*
	*  //If the payment is captured in this method, return a "captured_payment" array with the following information about the payment
	*  "captured_payment" => ["is_success"=>true|false, "error_message" => "error message", "transaction_id" => "xxx", "amount" => 20]
	* ]
	* @since 1.0
	* @return void
	*/
	public function authorize( $feed, $submission_data, $form, $entry ) {

		// Prepare authorization response payload
		$authorization = array(
			'is_authorized' => false,
			'error_message' => apply_filters( 'gform_braintree_credit_card_failure_message', __( 'Your card could not be billed. Please ensure the details you entered are correct and try again.', 'gravity-forms-braintree' ) ),
			'transaction_id' => '',
			'captured_payment' => array(
				'is_success' => false,
				'error_message' => '',
				'transaction_id' => '',
				'amount' => $submission_data['payment_amount']
			)
		);


		// Perform capture in this function. For this version, we won't authorize and then capture later
		// at least, not in this version
		if( $settings = $this->get_plugin_settings() ) {

			// Sanitize card number, removing dashes and spaces
			$card_number = str_replace( array( '-', ' ' ), '', $submission_data['card_number'] );

			// Prepare Braintree payload
			$args = array(
				'amount' => $submission_data['payment_amount'],
				'creditCard' => array(
					'number' => $card_number,
					'expirationDate' => sprintf( '%s/%s', $submission_data['card_expiration_date'][0], $submission_data['card_expiration_date'][1]),
					'cardholderName' => $submission_data['card_name'],
					'cvv' => $submission_data['card_security_code']
				),
				'customer' => array(
					'firstName' => $submission_data['card_name']
				  ),
				'billing' => array(
					'firstName' => $submission_data['card_name'],
					'streetAddress' => $submission_data['address'],
					'locality' => $submission_data['city'],
					'postalCode' => $submission_data['zip']
					)
			);

			try {

				// Configure Braintree environment
				Braintree\Configuration::environment( strtolower( $settings['environment'] ) );
				Braintree\Configuration::merchantId( $settings['merchant-id']);
				Braintree\Configuration::publicKey( $settings['public-key'] );
				Braintree\Configuration::privateKey( $settings['private-key'] );

				// Set to auto settlemt if applicable
				if( $settings['settlement'] == 'Yes' ) {
					$args['options']['submitForSettlement'] = 'true';
				}

				// Send transaction to Braintree
				$result = Braintree\Transaction::sale( $args );

				// Update response to reflect successful payment
				if( $result->success == '1' ) {

					$authorization['is_authorized'] = true;
					$authorization['error_message'] = '';
					$authorization['transaction_id'] = $result->transaction->_attributes['id'];

					$authorization['captured_payment'] = array(
						'is_success' => true,
						'transaction_id' => $result->transaction->_attributes['id'],
						'amount' => $result->transaction->_attributes['amount'],
						'error_message' => '',
						'payment_method' => 'Credit Card'
					);

				}
				else {

					// Append gateway response text to error message if it exists. If it doesn't exist, a more hardcore
					// failure has occured and it won't do the user any good to see it other than a general error message
					if( isset( $result->_attributes['transaction']->_attributes['processorResponseText'] ) ) {
						$authorization['error_message'] .= sprintf( '. Your bank said: %s.', $result->_attributes['transaction']->_attributes['processorResponseText'] );
					}

				}

			}
			catch( Exception $e ) {
				// Do nothing with exception object, just fallback to generic failure
			}

			return $authorization;

		}

		return false;

	}

	/**
	 * Override this method to add integration code to the payment processor in order to create a subscription. This method is executed during the form validation process and allows
	 * the form submission process to fail with a validation error if there is anything wrong when creating the subscription.
	 *
	 * @param $feed - Current configured payment feed
	 * @param $submission_data - Contains form field data submitted by the user as well as payment information (i.e. payment amount, setup fee, line items, etc...)
	 * @param $form - Current form array containing all form settings
	 * @param $entry - Current entry array containing entry information (i.e data submitted by users). NOTE: the entry hasn't been saved to the database at this point, so this $entry object does not have the 'ID' property and is only a memory representation of the entry.
	 *
	 * @return array - Return an $subscription array in the following format:
	 * [
	 *  'is_success'=>true|false,
	 *  'error_message' => 'error message',
	 *  'subscription_id' => 'xxx',
	 *  'amount' => 10
	 *
	 *  //To implement an initial/setup fee for gateways that don't support setup fees as part of subscriptions, manually capture the funds for the setup fee as a separate transaction and send that payment
	 *  //information in the following 'captured_payment' array
	 *  'captured_payment' => ['name' => 'Setup Fee', 'is_success'=>true|false, 'error_message' => 'error message', 'transaction_id' => 'xxx', 'amount' => 20]
	 * ]
	 */
	public function subscribe( $feed, $submission_data, $form, $entry ) {

		// Prepare authorization response payload
		$authorization = [
			'is_authorized' => false,
			'error_message' => apply_filters( 'gform_braintree_credit_card_failure_message', __( 'Your card could not be billed. Please ensure the details you entered are correct and try again.', 'gravity-forms-braintree' ) ),
			'subscription_id' => '',
			'amount' => '',
			'captured_payment' => [
				'is_success' => false,
				'error_message' => '',
				'subscription_id' => '',
				'amount' => $submission_data['payment_amount']
			]
		];

		if( $settings = $this->get_plugin_settings() ) {

			// Sanitize card number, removing dashes and spaces
			$card_number = str_replace(array('-', ' '), '', $submission_data['card_number']);

			// Prepare Braintree payload
			$namePieces = explode(' ', $submission_data['card_name']);
			$args = [
				'creditCard' => [
					'billingAddress' => [
						'countryName' => $submission_data['country'],
						'streetAddress' => $submission_data['address'],
						'locality' => $submission_data['city'],
						'region' => $submission_data['state'],
						'postalCode' => $submission_data['zip']
					],
					'number' => $card_number,
					'expirationDate' => sprintf('%s/%s', $submission_data['card_expiration_date'][0], $submission_data['card_expiration_date'][1]),
					'cardholderName' => $submission_data['card_name'],
					'cvv' => $submission_data['card_security_code']
				],
				'email' => $submission_data['email'],
				'lastName' => array_pop($namePieces),
				'firstName' => implode(' ', $namePieces)
			];

			try {
				// Configure Braintree environment
				Braintree\Configuration::environment(strtolower($settings['environment']));
				Braintree\Configuration::merchantId($settings['merchant-id']);
				Braintree\Configuration::publicKey($settings['public-key']);
				Braintree\Configuration::privateKey($settings['private-key']);

				$plans = Braintree\Plan::all();

				// See if there is a plan with a matching dollar value.
				$thePlan = null;
				if (count($plans) == 1) {
					$thePlan = $plans[0];
				} else if (count($plans) > 1) {
					foreach ($plans as $plan) {
						if ((float)$submission_data['payment_amount'] == (float)$plan->price) {
							$thePlan = $plan;
							break;
						}
					}
					if (empty($thePlan)) {
						$thePlan = $plans[0];
					}
				} else {
					$authorization['error_message'] = apply_filters('gform_braintree_no_plans_failure_message', __('No subscription plans are available.', 'gravity-forms-braintree'));
				}

				if (!empty($thePlan)) {
					$collection = Braintree\Customer::search([
						Braintree\CustomerSearch::email()->is($args['email'])
					]);

					if ($collection->maximumCount() > 0) {
						foreach ($collection as $customer) {
							$result = $customer;
						}
					} else {
						$result = Braintree\Customer::create($args);
					}

					if (!empty($result)) {
						if (get_class($result) != 'Braintree\Customer') {
							$result = $result->customer;
						}

						$subscription = [
							'paymentMethodToken' => $result->creditCards[0]->token,
							'planId' => $thePlan->id
						];

						if ((float)$submission_data['payment_amount'] != (float)$plan->price) {
							$subscription['price'] = (float)$submission_data['payment_amount'];
						}

						$subscriptionResult = Braintree\Subscription::create($subscription);

						if ($subscriptionResult->success == true) {

							$authorization['is_success'] = true;
							$authorization['error_message'] = '';
							$authorization['subscription_id'] = $subscriptionResult->subscription->id;
							$authorization['amount'] = $subscriptionResult->subscription->price;

							$authorization['captured_payment'] = [
								'is_success' => true,
								'subscription_id' => $subscriptionResult->subscription->id,
								'transaction_id' => $subscriptionResult->subscription->transactions[0]->id,
								'amount' => $subscriptionResult->subscription->price,
								'error_message' => ''
							];

						}
					} else {
						$authorization['error_message'] = apply_filters('gform_braintree_customer_create_failure_message', __('Failed to create a customer.', 'gravity-forms-braintree'));
					}
				} else {
					$authorization['error_message'] = apply_filters('gform_braintree_no_plan_message', __('No subscription plan found.', 'gravity-forms-braintree'));
				}

			} catch (Exception $e) {
				// Do nothing with exception object, just fallback to generic failure
			}
		}

		return $authorization;
	}

	/**
	* Create and display feed settings fields.
	*
	* @since 1.0
	* @return array
	*/
	public function feed_settings_fields () {

		// Get defaults from GFPaymentAddOn
		$settings = parent::feed_settings_fields();

		// Remove billing information
		//$settings = $this->remove_field( 'billingInformation', $settings );

		// Remove options
		$settings = $this->remove_field( 'options', $settings );

		// Remove the subscription option from transaction type dropdown
		$transaction_type = $this->get_field( 'transactionType', $settings );

		$settings = $this->replace_field( 'transactionType', $transaction_type, $settings );

		// Return sanitized settings
		return $settings;

	}

	/**
	* Create and display plugin settings fields. These are settings for Braintree in particular, not a feed
	*
	* @since 1.0
	* @return array
	*/
	public function plugin_settings_fields () {

		return array(

      array(
        'title' => 'Account Settings',
        'fields' => array(
          array(
            'name' => 'merchant-id',
            'tooltip' => 'Your Braintree Merchant ID',
            'label' => 'Merchant ID',
            'type' => 'text',
            'class' => 'medium'
          ),
					array(
						'name' => 'public-key',
						'tooltip' => 'Your Braintree Account Public Key',
						'label' => 'Public Key',
						'type' => 'text',
						'class' => 'medium'
					),
					array(
						'name' => 'private-key',
						'tooltip' => 'Your Braintree Account Private Key',
						'label' => 'Private Key',
						'type' => 'text',
						'class' => 'medium'
					)
        )
      ),
			array(
				'title' => 'Payment Settings',
				'fields' => array(
					array(
						'name' => 'settlement',
						'tooltip' => 'Choosing \'Yes\' will tell Braintree to automatically submit your transactions for settlement upon receipt',
						'label' => 'Automatic Settlement Submission',
						'type' => 'radio',
						'choices' => array(
							array(
								'label' => 'Yes',
								'name' => 'yes'
							),
							array(
								'label' => 'No',
								'name' => 'no'
							)
						)
					)
				)
			),
			array(
				'title' => 'Environment Settings',
				'fields' => array(
					array(
						'name' => 'environment',
						'tooltip' => 'Do you want to process test payments or real payments?',
						'label' => 'API Endpoint',
						'type' => 'radio',
						'choices' => array(
							array(
								'label' => 'Sandbox',
								'name' => 'sandbox'
							),
							array(
								'label' => 'Production',
								'name' => 'production'
							)
						)
					)
				)
			)

    );

	}

	/**
	* Helper function to determine if all Braintree settings have been set.
	* Does not check if they are correct, only that they have been set, IE not null
	* @param @settings Plugin settings to check if valid
	* @since 1.0
	* @return boolean
	*/
	public function settings_are_valid ( $settings ) {

		if( empty( $settings ) ) {
			return false;
		}

		foreach( $settings as $setting ) {
			if( '' == $setting ) {
				return false;
			}
		}

		return true;

	}

	/**
	* Get plugin settings
	*
	* @since 1.0
	* @return array|boolean
	*/
	public function get_plugin_settings () {

		$settings = parent::get_plugin_settings();

		if( $this->settings_are_valid( $settings ) ) {
			return $settings;
		}
		else {
			return false;
		}

	}

}

?>

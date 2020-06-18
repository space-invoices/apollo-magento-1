<?php

/**
 * Steps when order placed:
 * onOrderSaved / status: ''
 * onOrderPlaced / status: 'pending'
 * onOrderSaved / status: 'pending'
 */

class Apollo_OrderManagement_Model_Observer
{
  public function __construct()
  {
    $this->token = Mage::getStoreConfig('apollo_ordermanagement_options/section_one/access_token');
    $this->integration_id = Mage::getStoreConfig('apollo_ordermanagement_options/section_one/integration_id');
  }

  // On new order send it to API as JSON
  public function onOrderPlaced(Varien_Event_Observer $observer)
  {
    // Mage::log("onOrderPlaced", null, 'apollo.log');
    $order = $observer->getEvent()->getOrder();
    $this->sendOrderData($order);
  }

  // On update send order status change to API
  public function onOrderSaved(Varien_Event_Observer $observer)
  {
    // Mage::log("onOrderSaved", null, 'apollo.log');
    $order = $observer->getEvent()->getOrder();

    if ($order->getStatus() != '') {
      $this->sendOrderData($order);
    }
  }

  private function sendOrderData($order)
  {
    if (!$this->token || !$this->integration_id) {
      // Missing auth data
      return;
    }

    $data = (object) [
      'order' => (object) [],
      'items' => [],
      'shipping_address' => (object) [],
      'shipping_country' => '',
      'billing_address' => (object) [],
      'billing_country' => '',
      'payment_type' => '',
    ];

    $order_data = $order->getData();
    $data->order = $order_data;

    // Shipping
    $shipping_address = $order->getShippingAddress();
    $shipping_data = $shipping_address->getData();

    $country = Mage::getModel('directory/country')->loadByCode($shipping_address->getCountryId());

    $data->shipping_address = $shipping_data;
    $data->shipping_country->country = $country->getName();

    // Billing
    $billing_address = $order->getBillingAddress();
    $billing_data = $billing_address->getData();

    $country = Mage::getModel('directory/country')->loadByCode($billing_address->getCountryId());

    $data->billing_address = $billing_data;
    $data->billing_country = $country->getName();

    // Payment
    $payment = $order->getPayment()->getMethodInstance();
    $payment_type = $payment->getCode();
    $data->payment_type = $payment_type;

    // Items
    $items = [];

    foreach ($order->getAllItems() as $item) :
      $item_data = $item->getData();
      $items[] = $item_data;
    endforeach;

    $data->items = $items;

    // Mage::log($order->getStatus(), null, 'apollo.log');
    // Mage::log(json_encode($data), null, 'apollo.log');

    $response = $this->callAPI('POST', 'https://api-test.spaceinvoices.com/v1/magento-1/' . $this->integration_id . '/order', json_encode($data));
    $response_json = json_decode($response, true);

    $errors = $response_json['response']['errors'];
    $reponse_data = $response_json['response']['data'][0];

    if ($errors) {
      Mage::log(json_encode($errors), null, 'apollo.log');
    }
  }

  private function callAPI($method, $url, $data, $api_key = false)
  {
    $curl = curl_init();

    switch ($method) {
      case "POST":
        curl_setopt($curl, CURLOPT_POST, 1);

        if ($data)
          curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        break;

      case "PUT":
        curl_setopt($curl, CURLOPT_PUT, 1);
        break;

      default:
        if ($data)
          $url = sprintf("%s?%s", $url, http_build_query($data));
    }

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    if ($api_key) {
      curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Authorization: ' . $api_key,
        'Content-Type: application/json',
      ));
    }

    $result = curl_exec($curl);

    curl_close($curl);

    return $result;
  }
}

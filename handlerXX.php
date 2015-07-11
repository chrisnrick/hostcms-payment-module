<?php

/**
 * beGateway
 * Версия 1.0.0
 */

require_once CMS_FOLDER . 'hostcmsfiles/lib/beGateway/lib/beGateway.php';

class Shop_Payment_System_HandlerXX extends Shop_Payment_System_Handler
{
	/* Идентификатор магазина */
	private $_shop_id = '361';

  /* Секретный ключ магазина */
  private $_shop_key = 'b8647b68898b084b836474ed8d61ffe117c9a01168d867f24953b776ddcb134d';

  /* Домен платежного шлюза, полученный от платежной системы */
  private $_gateway_base = 'demo-gateway.begateway.com';

  /* Домен страницы оплаты, полученный от платежной системы */
  private $_checkout_base = 'checkout.begateway.com';

	/* Идентификатор валюты, в которой будет производиться платеж.
	 Сумма к оплате будет пересчитана из валюты магазина в указанную валюту */
	private $_begateway_currency_id = 1;

	/* Определяем коэффициент перерасчета цены */
	private $_coefficient = 1;

  /* Скрывать блок данных покупателя на странице оплаты */
  private $_hideAddress = TRUE;

  /* Включить отладку запросов к платежной системе */
  private $_debug = FALSE;

  /* Конструктор */
  public function __construct(Shop_Payment_System_Model $oShop_Payment_System_Model)
  {
    $this->_setupLogger();
    \beGateway\Settings::$shopId = $this->_shop_id;
    \beGateway\Settings::$shopKey = $this->_shop_key;
    \beGateway\Settings::$gatewayBase = 'https://' . $this->_gateway_base;
    \beGateway\Settings::$checkoutBase = 'https://' . $this->_checkout_base;
    parent::__construct($oShop_Payment_System_Model);
  }

	/* Вызывается на 4-ом шаге оформления заказа*/
	public function execute()
	{
		parent::execute();

		$this->printNotification();

		return $this;
	}

	protected function _processOrder()
	{
		parent::_processOrder();

		// Установка XSL-шаблонов в соответствии с настройками в узле структуры
		$this->setXSLs();

		// Отправка писем клиенту и пользователю
		$this->send();

		return $this;
	}

	/* вычисление суммы товаров заказа */
	public function getSumWithCoeff()
	{
		return Shop_Controller::instance()->round(($this->_begateway_currency_id > 0
				&& $this->_shopOrder->shop_currency_id > 0
			? Shop_Controller::instance()->getCurrencyCoefficientInShopCurrency(
				$this->_shopOrder->Shop_Currency,
				Core_Entity::factory('Shop_Currency', $this->_begateway_currency_id)
			)
			: 0) * $this->_shopOrder->getAmount() * $this->_coefficient);
	}

	/* обработка ответа от платёжной системы */
  public function paymentProcessing()
  {
    /* Пришло подтверждение оплаты, обработаем его */
    $this->ProcessResult();
    return true;
  }

	/* оплачивает заказ */
	function ProcessResult()
	{

    $webhook = new \beGateway\Webhook;

    if (!$webhook->isAuthorized() ||
        !$webhook->isSuccess() ||
        $this->_shopOrder->paid ||
        is_null($order_id = intval(Core_Array::getRequest('order_id'))) ||
        $order_id != $webhook->getTrackingId() )
    {
      return FALSE;
    }

    $sum = $this->getSumWithCoeff();
    $oShop_Currency = Core_Entity::factory('Shop_Currency')->find($this->_begateway_currency_id);

    /* конвертировать RUR код в RUB */
    $currency = $oShop_Currency->code;
    $currency = ($currency == 'RUR') ? 'RUB' : $currency;

    $money = new \beGateway\Money;
    $money->setCurrency($currency);
    $money->setAmount($sum);

    if ($money->getCents() == $webhook->getResponse()->transaction->amount &&
        $currency == $webhook->getResponse()->transaction->currency
       )
		{
			$this->shopOrderBeforeAction(clone $this->_shopOrder);

      $result = array(
        "Товар оплачен.",
        "Атрибуты:",
        "Номер сайта продавца: " . $this->_shop_id,
        "Внутренний номер покупки продавца: " . $this->_shopOrder->id,
        "Сумма платежа: " . $sum,
        "Валюта платежа: " . $oShop_Currency->code,
        "UID платежа: " . $webhook->getUid(),
        "Статус платежа: успешно"
      );

      if (isset($webhook->getResponse()->transaction->three_d_secure_verification))
      {
        $result []= "3-D Secure: " . $webhook->getResponse()->transaction->three_d_secure_verification->pa_status;
      }

      $this->_shopOrder->system_information = implode($result,"\n");

			$this->_shopOrder->paid();
			$this->setXSLs();
			$this->send();

			ob_start();
			$this->changedOrder('changeStatusPaid');
			ob_get_clean();
		}
	}

	/* печатает форму отправки запроса на сайт платёжной системы */
	public function getNotification()
	{
		$sum = $this->getSumWithCoeff();

		$oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
		$site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';

		$shop_path = $this->_shopOrder->Shop->Structure->getPath();
    $shopUrl = 'http://'.$site_alias.$shop_path;
		$handlerUrl = $shopUrl . "cart/?order_id={$this->_shopOrder->id}";

		$successUrl = $handlerUrl . "&payment=success";
		$failUrl = $handlerUrl . "&payment=fail";
    $cancelUrl = $handlerUrl;

    /* в режиме разработки переопределить домен для доставки нотификации о платеже */
    $handlerUrl = str_replace('hostcms.local', 'hostcms.webhook.begateway.com:8443', $handlerUrl);

		$oShop_Currency = Core_Entity::factory('Shop_Currency')->find($this->_begateway_currency_id);

		if(!is_null($oShop_Currency->id))
		{
			$serviceName = 'Оплата счета N ' . $this->_shopOrder->id;

      $this->_setupLogger();

      $transaction = new \beGateway\GetPaymentPageToken;

      /* конвертировать RUR код в RUB */
      $currency = $oShop_Currency->code;
      $currency = ($currency == 'RUR') ? 'RUB' : $currency;

      $transaction->money->setCurrency($currency);
      $transaction->money->setAmount($sum);
      $transaction->setDescription($serviceName);
      $transaction->setTrackingId($this->_shopOrder->id);
      $transaction->setLanguage('ru');
      $transaction->setNotificationUrl($handlerUrl . '&webhook=begateway');
      $transaction->setSuccessUrl($successUrl);
      $transaction->setDeclineUrl($failUrl);
      $transaction->setFailUrl($cancelUrl);
      $transaction->setCancelUrl($shopUrl);

      if ($this->_shopOrder->email) $transaction->customer->setEmail($this->_shopOrder->email);
      if ($this->_hideAddress) $transaction->setAddressHidden();

      $response = $transaction->submit();

      if ($response->isSuccess()) {

        ?>
        <h1>Оплата банковской картой</h1>
        <p>Сумма к оплате составляет <strong><?php echo $sum?> <?php echo $oShop_Currency->name?></strong></p>

        <p>Для оплаты нажмите кнопку "Оплатить".</p>

        <p style="color: rgb(112, 112, 112);">
          Внимание! Нажимая &laquo;Оплатить&raquo; Вы подтверждаете передачу контактных данных на сервер платежной системы для оплаты.
        </p>

        <form action="https://<?php echo $this->_checkout_base . '/checkout'; ?>" name="pay" method="post">
          <input type="hidden" name="token" value="<?php echo $response->getToken(); ?>">
          <input type="submit" name="button" value="Оплатить">
        </form>
        <?php
      } else {
        ?>
        <h1>Ошибка создания идентификатора платежа</h1>
        <p>Вернитесь назад и попробуйте еще раз</p>
        <?php if ($response->getMessage()) echo "<p>Причина: " . $response->getMessage(); ?>
        <?php
      }
		}
		else
		{
			?><h1>Не найдена валюта с идентификатором <?php $this->_begateway_currency_id?>!</h1><?php
		}
	}

	public function getInvoice()
	{
		return $this->getNotification();
	}

  private function _setupLogger()
  {
    if ($this->_debug) {
      \beGateway\Logger::getInstance()->setLogLevel(\beGateway\Logger::DEBUG);
    }
  }
}

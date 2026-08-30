<?php

class OrderController extends Controller
{
    /**
     * Страница оформления заказа.
     */
    public function checkout()
    {
        $deliveryMethods =
            Delivery::getMethodsWithServices();

        $currentLanguage =
            Translator::currentLanguage();

        $deliveryMethods =
            DeliveryTranslator::localizeTree(
                $deliveryMethods,
                $currentLanguage['code'] ?? Language::SOURCE_CODE
            );

        $this->view(
            'order/checkout',
            [
                'deliveryMethods' =>
                    $deliveryMethods
            ]
        );
    }


    public function store()
    {
        PublicInterfaceTranslator::seed();

        $customerName = trim($_POST['customer_name'] ?? '');
        $customerEmail = trim($_POST['customer_email'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $deliveryMethod = trim($_POST['delivery_method'] ?? '');
        $deliveryService = trim($_POST['delivery_service'] ?? '');
        $deliveryServiceOption = trim($_POST['delivery_service_option'] ?? '');
        $deliveryCountry = trim($_POST['delivery_country'] ?? '');
        $deliveryCity = trim($_POST['delivery_city'] ?? '');
        $deliveryAddress = trim($_POST['delivery_address'] ?? '');
        $deliveryOptionInput = trim($_POST['delivery_option_input'] ?? '');
        $deliveryPostcode = trim($_POST['delivery_postcode'] ?? '');
        $comment = trim($_POST['comment'] ?? '');

        $method = Delivery::findMethodBySlug(
            $deliveryMethod
        );

        if (!$method) {
            die(
                Translator::t(
                    'public.order.error_method',
                    'Некоректний спосіб доставки.'
                )
            );
        }

        $availableServices = Delivery::getServicesByMethodId(
            $method['id']
        );

        if (!empty($availableServices)) {
            if ($deliveryService === '') {
                die(
                    Translator::t(
                        'public.order.error_choose_service',
                        'Оберіть службу доставки.'
                    )
                );
            }

            $service = Delivery::findServiceBySlug(
                $method['id'],
                $deliveryService
            );

            if (!$service) {
                die(
                    Translator::t(
                        'public.order.error_service',
                        'Некоректна служба доставки.'
                    )
                );
            }

            $availableOptions = Delivery::getOptionsByServiceId(
                $service['id']
            );

            if (!empty($availableOptions)) {
                if ($deliveryServiceOption === '') {
                    die(
                        Translator::t(
                            'public.order.error_choose_option',
                            'Оберіть варіант отримання.'
                        )
                    );
                }

                $serviceOption = Delivery::findOptionBySlug(
                    $service['id'],
                    $deliveryServiceOption
                );

                if (!$serviceOption) {
                    die(
                        Translator::t(
                            'public.order.error_option',
                            'Некоректний варіант отримання.'
                        )
                    );
                }

                $optionInput = DeliveryOptionInput::getPublicBySelection(
                    $deliveryMethod,
                    $deliveryService,
                    $deliveryServiceOption
                );

                if (!empty($optionInput['is_enabled'])) {
                    $optionValue =
                        $deliveryOptionInput !== ''
                            ? $deliveryOptionInput
                            : $deliveryAddress;

                    if ($optionValue === '') {
                        $fieldLabel = trim(
                            $optionInput['field_label'] ?? ''
                        );

                        if ($fieldLabel !== '') {
                            $message = Translator::t(
                                'public.order.error_fill_field',
                                'Заповніть поле «{field}».'
                            );

                            die(
                                str_replace(
                                    '{field}',
                                    $fieldLabel,
                                    $message
                                )
                            );
                        }

                        die(
                            Translator::t(
                                'public.order.error_delivery_data',
                                'Вкажіть дані доставки.'
                            )
                        );
                    }

                    $deliveryAddress = $optionValue;
                }

            } else {
                $deliveryServiceOption = '';
            }

        } else {
            $deliveryService = '';
            $deliveryServiceOption = '';
        }

        if ($customerName === '' || $customerEmail === '') {
            die(
                Translator::t(
                    'public.order.error_name_email',
                    'Заповніть ім’я та Email.'
                )
            );
        }

        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            die(
                Translator::t(
                    'public.order.error_email',
                    'Некоректний Email.'
                )
            );
        }

        $userId =
            !empty($_SESSION['user_id'])
                ? (int) $_SESSION['user_id']
                : null;

        $items = [];

        if ($userId) {
            $items = Cart::getDetailedItemsByUserId(
                $userId
            );
        } else {
            foreach ($_SESSION['cart'] ?? [] as $cartItem) {
                $productId = (int) ($cartItem['product_id'] ?? 0);
                $sizeId = (int) ($cartItem['size_id'] ?? 0);
                $quantity = (int) ($cartItem['quantity'] ?? 0);

                if (
                    $productId <= 0
                    || $sizeId <= 0
                    || $quantity <= 0
                ) {
                    continue;
                }

                $product = Product::findById(
                    $productId
                );

                if (!$product) {
                    continue;
                }

                $size = Product::getAttributeValueById(
                    $sizeId
                );

                $items[] = [
                    'product' => $product,
                    'size_id' => $sizeId,
                    'size' => $size,
                    'quantity' => $quantity
                ];
            }
        }

        if (empty($items)) {
            die(
                Translator::t(
                    'public.order.error_empty_cart',
                    'Кошик порожній.'
                )
            );
        }

        $total = 0;

        foreach ($items as $item) {
            $unitPrice = Product::getCurrentPrice(
                $item['product']
            );

            $total +=
                $unitPrice
                * (int) $item['quantity'];
        }

        $result = Order::create(
            $userId,
            $customerName,
            $customerEmail,
            $customerPhone,
            $deliveryMethod,
            $deliveryService,
            $deliveryServiceOption,
            $deliveryCountry,
            $deliveryCity,
            $deliveryAddress,
            $deliveryPostcode,
            $comment,
            $items,
            $total
        );

        $orderToken = $result['token'];

        if ($userId) {
            Cart::clearByUserId(
                $userId
            );
        } else {
            $_SESSION['cart'] = [];
        }

        header(
            'Location: /Anabelka/order/success?token='
            . urlencode($orderToken)
        );

        exit;
    }


    public function success()
    {
        $token = trim($_GET['token'] ?? '');

        if ($token === '') {
            $this->view('order/error');
            return;
        }

        $order = Order::findByToken(
            $token
        );

        if (!$order) {
            $this->view('order/error');
            return;
        }

        $this->view(
            'order/success',
            [
                'order' => $order
            ]
        );
    }
}

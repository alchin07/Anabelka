<?php

class CustomerOrderController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();

        if (empty($_SESSION['user_id'])) {
            header('Location: /Anabelka/login');
            exit;
        }

        $orders = CustomerOrderHistory::forUser(
            (int) $_SESSION['user_id']
        );

        $this->view(
            'order/history',
            [
                'orders' => $orders
            ]
        );
    }
}

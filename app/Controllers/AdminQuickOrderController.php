<?php

class AdminQuickOrderController extends Controller
{
    public function index()
    {
        $orders =
            QuickOrder::getAllWithItems();

        $this->view(
            'admin/orders/index',
            [
                'orders' => $orders
            ]
        );
    }
}

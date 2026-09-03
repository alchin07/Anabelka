<?php

class App
{
    public function run()
    {
        // Запускаем сессию для корзины.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        require_once __DIR__ . '/Controller.php';
        require_once __DIR__ . '/Router.php';
        require_once __DIR__ . '/Database.php';

        require_once __DIR__ . '/../Models/Category.php';
        require_once __DIR__ . '/../Models/CategoryTranslator.php';
        require_once __DIR__ . '/../Models/Product.php';
        require_once __DIR__ . '/../Models/ProductTranslator.php';
        require_once __DIR__ . '/../Models/AdminProduct.php';
        require_once __DIR__ . '/../Models/Cart.php';
        require_once __DIR__ . '/../Models/User.php';
        require_once __DIR__ . '/../Models/Order.php';
        require_once __DIR__ . '/../Models/QuickOrder.php';
        require_once __DIR__ . '/../Models/Delivery.php';
        require_once __DIR__ . '/../Models/DeliveryRequirements.php';
        require_once __DIR__ . '/../Models/DeliveryOptionInput.php';
        require_once __DIR__ . '/../Models/Language.php';
        require_once __DIR__ . '/../Models/TranslationWorkflow.php';
        require_once __DIR__ . '/../Models/AppSetting.php';
        require_once __DIR__ . '/../Models/AITranslationUsage.php';
        require_once __DIR__ . '/../Models/AITranslationProviderHealth.php';
        require_once __DIR__ . '/../Models/Translator.php';
        require_once __DIR__ . '/../Models/PublicInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/ProductInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/DeliveryTranslator.php';

        require_once __DIR__ . '/../Services/AITranslationProviderInterface.php';
        require_once __DIR__ . '/../Services/OpenAITranslationProvider.php';
        require_once __DIR__ . '/../Services/GeminiTranslationProvider.php';
        require_once __DIR__ . '/../Services/GroqTranslationProvider.php';
        require_once __DIR__ . '/../Services/DeepLTranslationProvider.php';
        require_once __DIR__ . '/../Services/AITranslationService.php';
        require_once __DIR__ . '/../Services/TranslationDashboardService.php';

        require_once __DIR__ . '/../Controllers/HomeController.php';
        require_once __DIR__ . '/../Controllers/CatalogController.php';
        require_once __DIR__ . '/../Controllers/ProductController.php';
        require_once __DIR__ . '/../Controllers/CartController.php';
        require_once __DIR__ . '/../Controllers/AuthController.php';
        require_once __DIR__ . '/../Controllers/OrderController.php';
        require_once __DIR__ . '/../Controllers/QuickOrderController.php';
        require_once __DIR__ . '/../Controllers/LanguageController.php';
        require_once __DIR__ . '/../Controllers/AdminQuickOrderController.php';
        require_once __DIR__ . '/../Controllers/AdminDeliveryController.php';
        require_once __DIR__ . '/../Controllers/AdminDeliveryOptionInputController.php';
        require_once __DIR__ . '/../Controllers/AdminDeliveryTranslationController.php';
        require_once __DIR__ . '/../Controllers/DeliveryOptionInputController.php';
        require_once __DIR__ . '/../Controllers/AdminLanguageController.php';
        require_once __DIR__ . '/../Controllers/AdminCategoryController.php';
        require_once __DIR__ . '/../Controllers/AdminProductController.php';
        require_once __DIR__ . '/../Controllers/AdminAITranslationController.php';
        require_once __DIR__ . '/../Controllers/AdminTranslationController.php';

        $router = new Router();

        require __DIR__ . '/../../routes/Web.php';

        $router->dispatch(
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD']
        );
    }
}

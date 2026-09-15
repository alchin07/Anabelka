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

        require_once __DIR__ . '/../Models/Department.php';
        require_once __DIR__ . '/../Models/Category.php';
        require_once __DIR__ . '/../Models/CategoryTranslator.php';
        require_once __DIR__ . '/../Models/ProductImage.php';
        require_once __DIR__ . '/../Models/ProductVariantStock.php';
        require_once __DIR__ . '/../Models/Product.php';
        require_once __DIR__ . '/../Models/ProductDepartmentSync.php';
        require_once __DIR__ . '/../Models/ProductTranslator.php';
        require_once __DIR__ . '/../Models/HomePage.php';
        require_once __DIR__ . '/../Models/HomeInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/AdultAccess.php';
        require_once __DIR__ . '/../Models/AdminProduct.php';
        require_once __DIR__ . '/../Models/Inventory.php';
        require_once __DIR__ . '/../Models/Cart.php';
        require_once __DIR__ . '/../Models/CartColorMigration.php';
        require_once __DIR__ . '/../Models/Favorite.php';
        require_once __DIR__ . '/../Models/User.php';
        require_once __DIR__ . '/../Models/CustomerProfile.php';
        require_once __DIR__ . '/../Models/CustomerAddress.php';
        require_once __DIR__ . '/../Models/CustomerEmailVerification.php';
        require_once __DIR__ . '/../Models/CustomerPasswordReset.php';
        require_once __DIR__ . '/../Models/CustomerSocialIdentity.php';
        require_once __DIR__ . '/../Models/RegistrationConsent.php';
        require_once __DIR__ . '/../Models/PasswordPolicyInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/PasswordPolicy.php';
        require_once __DIR__ . '/../Models/CustomerAccount.php';
        require_once __DIR__ . '/../Models/CustomerAccountInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/CustomerEmailVerificationInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/PasswordResetInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/RegistrationInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/SocialAuthInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/SocialConnectionsInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/CustomerRankRequest.php';
        require_once __DIR__ . '/../Models/CustomerRankRequestInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/CustomerNotification.php';
        require_once __DIR__ . '/../Models/CustomerNotificationInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/AdminUser.php';
        require_once __DIR__ . '/../Models/Order.php';
        require_once __DIR__ . '/../Models/QuickOrder.php';
        require_once __DIR__ . '/../Models/CustomerOrderHistory.php';
        require_once __DIR__ . '/../Models/AdminOrder.php';
        require_once __DIR__ . '/../Models/AdminDashboard.php';
        require_once __DIR__ . '/../Models/Delivery.php';
        require_once __DIR__ . '/../Models/DeliveryRequirements.php';
        require_once __DIR__ . '/../Models/DeliveryOptionInput.php';
        require_once __DIR__ . '/../Models/Language.php';
        require_once __DIR__ . '/../Models/TranslationWorkflow.php';
        require_once __DIR__ . '/../Models/AppSetting.php';
        require_once __DIR__ . '/../Models/UserRank.php';
        require_once __DIR__ . '/../Models/UserRankTranslator.php';
        require_once __DIR__ . '/../Models/UserInvitation.php';
        require_once __DIR__ . '/../Models/AdminAccess.php';
        require_once __DIR__ . '/../Models/AdminNotificationCenter.php';
        require_once __DIR__ . '/../Models/AdminActionAudit.php';
        require_once __DIR__ . '/../Models/AdminManagement.php';
        require_once __DIR__ . '/../Models/AdminRolePermission.php';
        require_once __DIR__ . '/../Models/AdminRoleCleanup.php';
        require_once __DIR__ . '/../Models/AdminProfile.php';
        require_once __DIR__ . '/../Models/AITranslationUsage.php';
        require_once __DIR__ . '/../Models/AITranslationProviderHealth.php';
        require_once __DIR__ . '/../Models/Translator.php';
        require_once __DIR__ . '/../Models/PublicInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/ProductInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/DeliveryTranslator.php';
        require_once __DIR__ . '/../Models/SearchInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/FavoriteInterfaceTranslator.php';
        require_once __DIR__ . '/../Models/CatalogSearch.php';
        require_once __DIR__ . '/../Models/SearchQueryLog.php';

        ProductDepartmentSync::ensure();
        ProductVariantStock::ensureTable();

        require_once __DIR__ . '/../Services/AITranslationProviderInterface.php';
        require_once __DIR__ . '/../Services/OpenAITranslationProvider.php';
        require_once __DIR__ . '/../Services/GeminiTranslationProvider.php';
        require_once __DIR__ . '/../Services/GroqTranslationProvider.php';
        require_once __DIR__ . '/../Services/DeepLTranslationProvider.php';
        require_once __DIR__ . '/../Services/AITranslationService.php';
        require_once __DIR__ . '/../Services/TranslationDashboardService.php';
        require_once __DIR__ . '/../Services/CategoryManager.php';
        require_once __DIR__ . '/../Services/EmailVerificationMailer.php';
        require_once __DIR__ . '/../Services/EmailVerificationService.php';
        require_once __DIR__ . '/../Services/PasswordResetService.php';
        require_once __DIR__ . '/../Services/GoogleOAuthProvider.php';
        require_once __DIR__ . '/../Services/FacebookOAuthProvider.php';
        require_once __DIR__ . '/../Services/SocialAuthService.php';

        require_once __DIR__ . '/../Controllers/HomeController.php';
        require_once __DIR__ . '/../Controllers/CatalogController.php';
        require_once __DIR__ . '/../Controllers/ProductController.php';
        require_once __DIR__ . '/../Controllers/AdultController.php';
        require_once __DIR__ . '/../Controllers/SearchController.php';
        require_once __DIR__ . '/../Controllers/CartController.php';
        require_once __DIR__ . '/../Controllers/CartColorController.php';
        require_once __DIR__ . '/../Controllers/FavoriteController.php';
        require_once __DIR__ . '/../Controllers/AuthController.php';
        require_once __DIR__ . '/../Controllers/CustomerAccountController.php';
        require_once __DIR__ . '/../Controllers/EmailVerificationController.php';
        require_once __DIR__ . '/../Controllers/PasswordResetController.php';
        require_once __DIR__ . '/../Controllers/SocialAuthController.php';
        require_once __DIR__ . '/../Controllers/LegalController.php';
        require_once __DIR__ . '/../Controllers/InvitationController.php';
        require_once __DIR__ . '/../Controllers/OrderController.php';
        require_once __DIR__ . '/../Controllers/QuickOrderController.php';
        require_once __DIR__ . '/../Controllers/CustomerOrderController.php';
        require_once __DIR__ . '/../Controllers/LanguageController.php';
        require_once __DIR__ . '/../Controllers/AdminAuthController.php';
        require_once __DIR__ . '/../Controllers/AdminDashboardController.php';
        require_once __DIR__ . '/../Controllers/AdminOrderController.php';
        require_once __DIR__ . '/../Controllers/AdminSearchController.php';
        require_once __DIR__ . '/../Controllers/AdminUserController.php';
        require_once __DIR__ . '/../Controllers/AdminUserRankController.php';
        require_once __DIR__ . '/../Controllers/AdminAdministratorController.php';
        require_once __DIR__ . '/../Controllers/AdminDeliveryController.php';
        require_once __DIR__ . '/../Controllers/AdminDeliveryOptionInputController.php';
        require_once __DIR__ . '/../Controllers/AdminDeliveryTranslationController.php';
        require_once __DIR__ . '/../Controllers/DeliveryOptionInputController.php';
        require_once __DIR__ . '/../Controllers/AdminLanguageController.php';
        require_once __DIR__ . '/../Controllers/AdminCategoryController.php';
        require_once __DIR__ . '/../Controllers/AdminProductController.php';
        require_once __DIR__ . '/../Controllers/AdminProductVariantController.php';
        require_once __DIR__ . '/../Controllers/AdminAITranslationController.php';
        require_once __DIR__ . '/../Controllers/AdminTranslationController.php';

        $router = new Router();

        require __DIR__ . '/../../routes/AdminAuth.php';
        require __DIR__ . '/../../routes/AdminSecurity.php';
        require __DIR__ . '/../../routes/CustomerAccount.php';
        require __DIR__ . '/../../routes/Legal.php';
        require __DIR__ . '/../../routes/PasswordReset.php';
        require __DIR__ . '/../../routes/SocialAuth.php';
        require __DIR__ . '/../../routes/Web.php';
        require __DIR__ . '/../../routes/Adult.php';
        require __DIR__ . '/../../routes/CartColor.php';
        require __DIR__ . '/../../routes/Favorites.php';

        $router->dispatch(
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD']
        );
    }
}

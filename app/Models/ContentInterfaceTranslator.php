<?php

class ContentInterfaceTranslator
{
    private static $seeded = false;


    public static function seed()
    {
        if (self::$seeded) {
            return;
        }

        Translator::currentLanguage();
        $db = Database::connect();

        $translations = [
            'uk' => [
                'news.title' => 'Новини',
                'news.intro' => 'Останні новини, оновлення та події Анабельки.',
                'news.empty' => 'Опублікованих новин поки немає.',
                'news.read_more' => 'Читати далі',
                'news.back' => 'Усі новини',
                'news.not_found' => 'Новину не знайдено',
                'news.not_found_text' => 'Ця публікація недоступна або ще не опублікована.',
                'reviews.title' => 'Відгуки покупців',
                'reviews.intro' => 'Свіжі схвалені відгуки покупців про товари Анабельки.',
                'reviews.empty' => 'Схвалених відгуків поки немає.',
                'reviews.product_title' => 'Відгуки про товар',
                'reviews.product_intro' => 'Відгуки публікуються після модерації.',
                'reviews.product_empty' => 'Схвалених відгуків про цей товар поки немає.',
                'reviews.rating' => 'Оцінка',
                'reviews.body' => 'Ваш відгук',
                'reviews.submit' => 'Надіслати на модерацію',
                'reviews.login_to_review' => 'Увійдіть, щоб залишити відгук',
                'storefront.discounts.title' => 'Знижки',
                'storefront.discounts.intro' => 'Товари Анабельки з актуальними знижками.',
                'storefront.discounts.empty' => 'Товарів зі знижкою поки немає.',
                'storefront.new.title' => 'Новинки',
                'storefront.new.intro' => 'Найновіші товари Анабельки без розділів 18+.',
                'storefront.new.empty' => 'Нових товарів поки немає.',
                'storefront.pagination.previous' => 'Назад',
                'storefront.pagination.next' => 'Далі',
                'storefront.error_title' => 'Розділ тимчасово недоступний',
                'storefront.error_message' => 'Спробуйте відкрити сторінку трохи пізніше.'
            ],
            'ru' => [
                'news.title' => 'Новости',
                'news.intro' => 'Последние новости, обновления и события Анабельки.',
                'news.empty' => 'Опубликованных новостей пока нет.',
                'news.read_more' => 'Читать далее',
                'news.back' => 'Все новости',
                'news.not_found' => 'Новость не найдена',
                'news.not_found_text' => 'Эта публикация недоступна или ещё не опубликована.',
                'reviews.title' => 'Отзывы покупателей',
                'reviews.intro' => 'Свежие одобренные отзывы покупателей о товарах Анабельки.',
                'reviews.empty' => 'Одобренных отзывов пока нет.',
                'reviews.product_title' => 'Отзывы о товаре',
                'reviews.product_intro' => 'Отзывы публикуются после модерации.',
                'reviews.product_empty' => 'Одобренных отзывов об этом товаре пока нет.',
                'reviews.rating' => 'Оценка',
                'reviews.body' => 'Ваш отзыв',
                'reviews.submit' => 'Отправить на модерацию',
                'reviews.login_to_review' => 'Войдите, чтобы оставить отзыв',
                'storefront.discounts.title' => 'Скидки',
                'storefront.discounts.intro' => 'Товары Анабельки с актуальными скидками.',
                'storefront.discounts.empty' => 'Товаров со скидкой пока нет.',
                'storefront.new.title' => 'Новинки',
                'storefront.new.intro' => 'Самые новые товары Анабельки без разделов 18+.',
                'storefront.new.empty' => 'Новых товаров пока нет.',
                'storefront.pagination.previous' => 'Назад',
                'storefront.pagination.next' => 'Далее',
                'storefront.error_title' => 'Раздел временно недоступен',
                'storefront.error_message' => 'Попробуйте открыть страницу немного позже.'
            ],
            'en' => [
                'news.title' => 'News',
                'news.intro' => 'Latest news, updates and events from Anabelka.',
                'news.empty' => 'There is no published news yet.',
                'news.read_more' => 'Read more',
                'news.back' => 'All news',
                'news.not_found' => 'News item not found',
                'news.not_found_text' => 'This publication is unavailable or has not been published yet.',
                'reviews.title' => 'Customer reviews',
                'reviews.intro' => 'Latest approved customer reviews about Anabelka products.',
                'reviews.empty' => 'There are no approved reviews yet.',
                'reviews.product_title' => 'Product reviews',
                'reviews.product_intro' => 'Reviews are published after moderation.',
                'reviews.product_empty' => 'There are no approved reviews for this product yet.',
                'reviews.rating' => 'Rating',
                'reviews.body' => 'Your review',
                'reviews.submit' => 'Submit for moderation',
                'reviews.login_to_review' => 'Sign in to leave a review',
                'storefront.discounts.title' => 'Discounts',
                'storefront.discounts.intro' => 'Anabelka products with current discounts.',
                'storefront.discounts.empty' => 'There are no discounted products yet.',
                'storefront.new.title' => 'New arrivals',
                'storefront.new.intro' => 'The newest Anabelka products excluding 18+ sections.',
                'storefront.new.empty' => 'There are no new products yet.',
                'storefront.pagination.previous' => 'Previous',
                'storefront.pagination.next' => 'Next',
                'storefront.error_title' => 'Section temporarily unavailable',
                'storefront.error_message' => 'Please try opening this page again a little later.'
            ]
        ];

        $stmt = $db->prepare("
            INSERT IGNORE INTO interface_translations
            (translation_key, language_code, value, source, status)
            VALUES
            (:translation_key, :language_code, :value, 'manual', 'approved')
        ");

        foreach ($translations as $languageCode => $items) {
            foreach ($items as $key => $value) {
                $stmt->execute([
                    'translation_key' => $key,
                    'language_code' => $languageCode,
                    'value' => $value
                ]);
            }
        }

        self::$seeded = true;
    }
}

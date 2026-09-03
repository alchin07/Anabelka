<?php

class AdminDeliveryController extends Controller
{
    /**
     * Страница управления доставкой.
     *
     * Пока только просмотр структуры.
     */
    public function index()
    {
        $deliveryMethods =
    Delivery::getAllForAdmin();

        $this->view(
            'admin/delivery/index',
            [
                'deliveryMethods' =>
                    $deliveryMethods
            ]
        );
    }

  /**
 * Включение / выключение
 * способа доставки.
 */
public function toggleMethod()
{
    $methodId =
        (int) (
            $_POST['method_id']
            ?? 0
        );

    $isActive =
        (int) (
            $_POST['is_active']
            ?? 0
        );


    if ($methodId <= 0) {

        http_response_code(400);

        exit(
            'Некорректный способ доставки.'
        );
    }


    Delivery::setMethodActive(
        $methodId,
        $isActive === 1
    );

    if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH']
        === 'XMLHttpRequest'
) {

    http_response_code(200);

    echo 'OK';

    exit;
    }


    header(
        'Location: /Anabelka/admin/delivery'
    );

    exit;
}

  /**
 * Включение / выключение
 * службы доставки.
 */
public function toggleService()
{
    $serviceId =
        (int) (
            $_POST['service_id']
            ?? 0
        );

    $isActive =
        (int) (
            $_POST['is_active']
            ?? 0
        );


    if ($serviceId <= 0) {

        http_response_code(400);

        exit(
            'Некорректная служба доставки.'
        );
    }


    Delivery::setServiceActive(
        $serviceId,
        $isActive === 1
    );

    if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH']
        === 'XMLHttpRequest'
) {

    http_response_code(200);

    echo 'OK';

    exit;
    }


    header(
        'Location: /Anabelka/admin/delivery'
    );

    exit;
}

  /**
 * Включение / выключение
 * варианта получения.
 */
public function toggleOption()
{
    $optionId =
        (int) (
            $_POST['option_id']
            ?? 0
        );

    $isActive =
        (int) (
            $_POST['is_active']
            ?? 0
        );


    if ($optionId <= 0) {

        http_response_code(400);

        exit(
            'Некорректный вариант получения.'
        );
    }


    Delivery::setOptionActive(
        $optionId,
        $isActive === 1
    );


    /*
     * AJAX-запрос:
     * страницу не перезагружаем.
     */
    if (
        isset(
            $_SERVER[
                'HTTP_X_REQUESTED_WITH'
            ]
        ) &&
        $_SERVER[
            'HTTP_X_REQUESTED_WITH'
        ] === 'XMLHttpRequest'
    ) {

        http_response_code(200);

        echo 'OK';

        exit;
    }


    /*
     * Запасной вариант:
     * обычная отправка формы.
     */
    header(
        'Location: /Anabelka/admin/delivery'
    );

    exit;
}

  /**
 * Редактирование элемента доставки.
 */
public function update()
{
    $type =
        trim(
            $_POST['type']
            ?? ''
        );

    $id =
        (int) (
            $_POST['id']
            ?? 0
        );

    $name =
        trim(
            $_POST['name']
            ?? ''
        );

    $description =
        trim(
            $_POST['description']
            ?? ''
        );


    if (
        $id <= 0
        ||
        $name === ''
    ) {

        http_response_code(400);

        echo 'Некорректные данные.';

        exit;
    }


    if (!in_array($type, ['method', 'service', 'option'], true)) {

        http_response_code(400);

        echo 'Некорректный тип.';

        exit;
    }

    /*
     * Підготовка таблиці перекладів до транзакції: ensureTable()
     * може виконати DDL під час першого запуску.
     */
    $currentSource = DeliveryTranslator::getSourceForEntity(
        $type,
        $id
    );

    if (!$currentSource) {
        http_response_code(404);
        echo 'Элемент доставки не найден.';
        exit;
    }

    $db = Database::connect();

    try {
        $db->beginTransaction();

        if ($type === 'method') {
            $updated = Delivery::updateMethod(
                $id,
                $name,
                $description
            );
        } elseif ($type === 'service') {
            $updated = Delivery::updateService(
                $id,
                $name,
                $description
            );
        } else {
            $updated = Delivery::updateOption(
                $id,
                $name,
                $description
            );
        }

        if (!$updated) {
            throw new RuntimeException(
                'Не удалось сохранить изменения.'
            );
        }

        if (TranslationWorkflow::sourceChanged(
            $currentSource['name'] ?? '',
            $currentSource['description'] ?? '',
            $name,
            $description
        )) {
            DeliveryTranslator::markOutdated($type, $id);
        }

        $db->commit();

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        http_response_code(500);
        echo $e->getMessage();
        exit;
    }


    http_response_code(200);

    echo 'OK';

    exit;
}

  /**
 * Удаление элемента доставки.
 */
public function delete()
{
    $type =
        trim(
            $_POST['type']
            ?? ''
        );

    $id =
        (int) (
            $_POST['id']
            ?? 0
        );


    if ($id <= 0) {

        http_response_code(400);

        echo 'Некорректный ID.';

        exit;
    }


    if ($type === 'method') {

        Delivery::deleteMethod(
            $id
        );

    } elseif ($type === 'service') {

        Delivery::deleteService(
            $id
        );

    } elseif ($type === 'option') {

        Delivery::deleteOption(
            $id
        );

    } else {

        http_response_code(400);

        echo 'Некорректный тип.';

        exit;
    }


    http_response_code(200);

    echo 'OK';

    exit;
}

  /**
 * Добавление способа доставки.
 */
public function createMethod()
{
    $name =
        trim(
            $_POST['name']
            ?? ''
        );


    $description =
        trim(
            $_POST['description']
            ?? ''
        );


    if (
        $name === ''
    ) {

        http_response_code(400);

        echo 'Укажите название способа доставки.';

        exit;
    }


    try {

        $result =
            Delivery::createMethod(
                $name,
                $description
            );


        $id =
            $result['id'];

        $slug =
            $result['slug'];

        $sortOrder =
            $result['sort_order'];


        header(
            'Content-Type: application/json; charset=UTF-8'
        );


        echo json_encode(
            [
                'success' => true,
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'description' =>
                    $description,
                'sort_order' =>
                    $sortOrder,
                'is_active' => 1
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;

    } catch (PDOException $e) {

        http_response_code(400);

        echo 'Не удалось создать способ доставки.';

        exit;
    }
}

  public function createService()
{
    $deliveryMethodId =
        (int) (
            $_POST['delivery_method_id']
            ?? 0
        );

    $name =
        trim(
            $_POST['name']
            ?? ''
        );

    $description =
        trim(
            $_POST['description']
            ?? ''
        );


    if (
        $deliveryMethodId <= 0
        ||
        $name === ''
    ) {

        http_response_code(400);

        echo 'Заполните обязательные поля.';

        exit;
    }


    try {

        $result =
            Delivery::createService(
                $deliveryMethodId,
                $name,
                $description
            );


        $id =
            $result['id'];

        $slug =
            $result['slug'];

        $sortOrder =
            $result['sort_order'];


        header(
            'Content-Type: application/json; charset=UTF-8'
        );


        echo json_encode(
            [
                'success' => true,
                'id' => $id,
                'delivery_method_id' =>
                    $deliveryMethodId,
                'name' => $name,
                'slug' => $slug,
                'description' =>
                    $description,
                'sort_order' =>
                    $sortOrder,
                'is_active' => 1
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;

    } catch (PDOException $e) {

        http_response_code(400);

        echo 'Не удалось создать службу доставки.';

        exit;
    }
}

  public function createOption()
{
    $deliveryServiceId =
        (int) (
            $_POST['delivery_service_id']
            ?? 0
        );

    $name =
        trim(
            $_POST['name']
            ?? ''
        );

    $description =
        trim(
            $_POST['description']
            ?? ''
        );


    if (
        $deliveryServiceId <= 0
        ||
        $name === ''
    ) {

        http_response_code(400);

        echo 'Укажите название опции доставки.';

        exit;
    }


    try {

        $result =
            Delivery::createOption(
                $deliveryServiceId,
                $name,
                $description
            );


        $id =
            $result['id'];

        $slug =
            $result['slug'];

        $sortOrder =
            $result['sort_order'];


        header(
            'Content-Type: application/json; charset=UTF-8'
        );


        echo json_encode(
            [
                'success' => true,
                'id' => $id,
                'delivery_service_id' =>
                    $deliveryServiceId,
                'name' => $name,
                'slug' => $slug,
                'description' =>
                    $description,
                'sort_order' =>
                    $sortOrder,
                'is_active' => 1
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;

    } catch (PDOException $e) {

        http_response_code(400);

        echo 'Не удалось создать опцию доставки.';

        exit;
    }
}
}

<?php

final class AdminChatController extends Controller
{
    private const MAX_FILE_BYTES = 8388608;
    private const MAX_FILES = 4;

    public function index(): void
    {
        $error = '';
        try {
            AdminChat::listFor(AdminAccess::currentId());
        } catch (Throwable $e) {
            error_log('Admin chat schema: ' . $e->getMessage());
            $error = 'Чат ще не підготовлено. Спочатку застосуйте міграцію 2026-10-21_admin_chat.sql.';
        }
        $this->view('admin/chat/index', [
            'pageTitle' => 'Чат адміністраторів',
            'adminId' => AdminAccess::currentId(),
            'csrfToken' => AdminAccess::csrfToken(),
            'loadError' => $error
        ]);
    }

    public function list(): void
    {
        $this->api(function (): array {
            $actorId = AdminAccess::currentId();
            return [
                'conversations' => AdminChat::listFor($actorId),
                'admins' => AdminChat::admins($actorId),
                'unread_total' => AdminChat::unreadTotal($actorId)
            ];
        });
    }

    public function messages(): void
    {
        $this->api(function (): array {
            $id = max(0, (int) ($_GET['conversation_id'] ?? 0));
            $after = max(0, (int) ($_GET['after'] ?? 0));
            $receiptFrom = max(0, (int) ($_GET['receipt_from'] ?? 0));
            $actorId = AdminAccess::currentId();
            return AdminChat::history($id, $actorId, $after, $receiptFrom);
        });
    }

    public function direct(): void
    {
        $this->api(function (): array {
            return ['conversation_id' => AdminChat::direct(
                AdminAccess::currentId(),
                (int) ($_POST['admin_id'] ?? 0)
            )];
        });
    }

    public function group(): void
    {
        $this->api(function (): array {
            $members = $_POST['members'] ?? [];
            if (!is_array($members)) {
                throw new InvalidArgumentException('Некоректні учасники.');
            }
            $id = AdminChat::createGroup(
                AdminAccess::currentId(),
                (string) ($_POST['title'] ?? ''),
                $members
            );
            AdminAccess::audit('chat.group_created', ['conversation_id' => $id]);
            return ['conversation_id' => $id];
        });
    }

    public function manageGroup(): void
    {
        $this->api(function (): array {
            $id = (int) ($_POST['conversation_id'] ?? 0);
            $action = (string) ($_POST['action'] ?? '');
            AdminChat::manageGroup(
                $id, AdminAccess::currentId(), $action,
                (int) ($_POST['member_id'] ?? 0),
                (string) ($_POST['title'] ?? '')
            );
            AdminAccess::audit('chat.group_updated', [
                'conversation_id' => $id, 'action' => $action
            ]);
            return ['conversation_id' => $id];
        });
    }

    public function send(): void
    {
        $this->api(function (): array {
            $id = (int) ($_POST['conversation_id'] ?? 0);
            $body = trim((string) ($_POST['body'] ?? ''));
            $files = $this->uploads();
            if ($body === '' && !$files) {
                throw new InvalidArgumentException('Введіть повідомлення або додайте файл.');
            }
            // Валідація форматів та розміру до будь-якого запису в БД.
            $checked = array_map([$this, 'validateUpload'], $files);
            $db = AdminChat::db();
            $moved = [];
            $db->beginTransaction();
            try {
                $messageId = AdminChat::send(
                    $id, AdminAccess::currentId(), $body,
                    (int) ($_POST['reply_to_id'] ?? 0)
                );
                foreach ($checked as $file) {
                    $dir = $this->storageDirectory();
                    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                        throw new RuntimeException('Не вдалося створити сховище вкладень.');
                    }
                    $diskName = bin2hex(random_bytes(20));
                    $path = $dir . '/' . $diskName;
                    if (!move_uploaded_file($file['tmp_name'], $path)) {
                        throw new RuntimeException('Не вдалося завантажити файл.');
                    }
                    @chmod($path, 0600);
                    $moved[] = $path;
                    AdminChat::addAttachment(
                        $messageId, $diskName, $file['original_name'],
                        $file['mime_type'], $file['size']
                    );
                }
                $db->commit();
                return ['message_id' => $messageId];
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                foreach ($moved as $path) {
                    @unlink($path);
                }
                throw $e;
            }
        });
    }

    public function edit(): void
    {
        $this->api(function (): array {
            AdminChat::edit(
                (int) ($_POST['message_id'] ?? 0),
                AdminAccess::currentId(),
                (string) ($_POST['body'] ?? '')
            );
            return [];
        });
    }

    public function delete(): void
    {
        $this->api(function (): array {
            AdminChat::delete(
                (int) ($_POST['message_id'] ?? 0),
                AdminAccess::currentId()
            );
            return [];
        });
    }

    public function read(): void
    {
        $this->api(function (): array {
            $watermark = AdminChat::read(
                (int) ($_POST['conversation_id'] ?? 0),
                AdminAccess::currentId(),
                (int) ($_POST['message_id'] ?? 0)
            );
            return ['viewer_read_message_id' => $watermark];
        });
    }

    public function search(): void
    {
        $this->api(function (): array {
            return ['results' => AdminChat::search(
                AdminAccess::currentId(), (string) ($_GET['q'] ?? '')
            )];
        });
    }

    public function file(): void
    {
        try {
            $file = AdminChat::file(
                (int) ($_GET['id'] ?? 0),
                AdminAccess::currentId()
            );
            $path = $this->storageDirectory() . '/' . $file['disk_name'];
            if (!is_file($path) || !is_readable($path)) {
                throw new DomainException('Вкладення не знайдено.');
            }
            $type = (string) $file['mime_type'];
            $inline = in_array($type, [
                'image/jpeg', 'image/png', 'image/webp', 'image/gif'
            ], true);
            $name = str_replace(["\r", "\n", '"', '\\'], '_',
                (string) $file['original_name']);
            header('Content-Type: ' . $type);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            header('Content-Security-Policy: default-src ' . "'none'; sandbox");
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
                . '; filename="file"; filename*=UTF-8' . "''" . rawurlencode($name));
            header('Content-Length: ' . filesize($path));
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            readfile($path);
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
            echo 'Вкладення не знайдено.';
        }
    }

    private function api(callable $run): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: private, no-store');
        try {
            $data = $run();
            echo json_encode(
                array_merge(['success' => true], $data),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (DomainException $e) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => $e->getMessage()],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('Admin chat: ' . get_class($e) . ': ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false,
                'message' => 'Не вдалося виконати дію. Спробуйте пізніше.'],
                JSON_UNESCAPED_UNICODE);
        }
    }

    private function uploads(): array
    {
        if (empty($_FILES['files'])) {
            return [];
        }
        $input = $_FILES['files'];
        $names = $input['name'] ?? [];
        if (!is_array($names)) {
            $names = [$names];
            foreach (['tmp_name', 'error', 'size'] as $key) {
                $input[$key] = [$input[$key] ?? null];
            }
        }
        $files = [];
        foreach ($names as $index => $name) {
            $error = (int) ($input['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Помилка завантаження файлу. Перевірте розмір.');
            }
            $files[] = [
                'name' => (string) $name,
                'tmp_name' => (string) ($input['tmp_name'][$index] ?? ''),
                'size' => (int) ($input['size'][$index] ?? 0)
            ];
        }
        if (count($files) > self::MAX_FILES) {
            throw new InvalidArgumentException('Можна надіслати до 4 файлів за раз.');
        }
        return $files;
    }

    private function validateUpload(array $file): array
    {
        $size = $file['size'];
        $tmp = $file['tmp_name'];
        if ($size <= 0 || $size > self::MAX_FILE_BYTES || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Файл завеликий або пошкоджений (максимум 8 МБ).');
        }
        $original = basename(str_replace('\\', '/', $file['name']));
        $original = preg_replace('/[\x00-\x1f\x7f]/', '_', $original) ?: 'file';
        $original = function_exists('mb_substr')
            ? mb_substr($original, 0, 200, 'UTF-8')
            : substr($original, 0, 200);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'], 'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/vnd.ms-excel'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
            'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip']
        ];
        if (!isset($allowed[$ext])) {
            throw new InvalidArgumentException('Формат файлу не підтримується.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!in_array($mime, $allowed[$ext], true)) {
            throw new InvalidArgumentException('Тип файлу не відповідає його розширенню.');
        }
        if (str_starts_with($mime, 'image/')) {
            $image = @getimagesize($tmp);
            if ($image === false || ($image['mime'] ?? '') !== $mime) {
                throw new InvalidArgumentException('Зображення пошкоджене.');
            }
        }
        return [
            'tmp_name' => $tmp, 'size' => $size,
            'original_name' => $original, 'mime_type' => $mime
        ];
    }

    private function storageDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage/admin-chat';
    }
}

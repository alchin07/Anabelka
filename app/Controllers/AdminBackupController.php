<?php

class AdminBackupController extends Controller
{
    private const BACKUP_DIR = 'storage/full-backups';
    private const ARCHIVE_PREFIX = 'Anabelka_full_';


    public function index()
    {
        $this->assertDeveloper();

        $created = $this->safeBackupName($_GET['created'] ?? '');
        $error = trim((string) ($_SESSION['admin_backup_error'] ?? ''));
        unset($_SESSION['admin_backup_error']);

        $this->view('admin/system/backup', [
            'pageTitle' => 'Адмін-панель — Резервна копія',
            'csrfToken' => AdminAccess::csrfToken(),
            'created' => $created,
            'error' => $error,
            'backups' => $this->listBackups()
        ]);
    }


    public function create()
    {
        $this->assertDeveloper();
        $this->assertCsrf();

        $archivePath = '';
        $dumpPath = '';

        try {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException(
                    'Розширення ZipArchive недоступне в PHP.'
                );
            }

            @set_time_limit(0);
            @ini_set('max_execution_time', '0');
            @ignore_user_abort(true);

            $root = $this->projectRoot();
            $backupDir = $this->backupDirectory();
            $this->protectBackupDirectory($backupDir);

            $stamp = date('Y-m-d_H-i-s');
            $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
            $filename = self::ARCHIVE_PREFIX
                . $stamp
                . '_'
                . $suffix
                . '.zip';
            $archivePath = $backupDir . DIRECTORY_SEPARATOR . $filename;
            $dumpPath = $backupDir
                . DIRECTORY_SEPARATOR
                . '.database-'
                . $suffix
                . '.sql';

            $databaseInfo = $this->writeDatabaseDump($dumpPath);
            $zip = new ZipArchive();
            $result = $zip->open(
                $archivePath,
                ZipArchive::CREATE | ZipArchive::OVERWRITE
            );

            if ($result !== true) {
                throw new RuntimeException(
                    'Не вдалося створити ZIP-архів. Код ZipArchive: '
                    . (string) $result
                );
            }

            $stats = [
                'files' => 0,
                'bytes' => 0
            ];

            try {
                $this->addProjectDirectory(
                    $zip,
                    $root,
                    $root,
                    $stats
                );

                if (!$zip->addFile($dumpPath, 'backup/database.sql')) {
                    throw new RuntimeException(
                        'Не вдалося додати database.sql до архіву.'
                    );
                }

                $manifest = [
                    'archive' => $filename,
                    'created_at' => date(DATE_ATOM),
                    'project_root' => $root,
                    'php_version' => PHP_VERSION,
                    'database' => $databaseInfo,
                    'project_files' => (int) $stats['files'],
                    'project_bytes' => (int) $stats['bytes'],
                    'excluded' => [
                        '.git',
                        'storage/full-backups',
                        'storage/deploy-backups'
                    ],
                    'contains_sensitive_data' => true
                ];

                $manifestJson = json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                );

                if ($manifestJson === false) {
                    throw new RuntimeException(
                        'Не вдалося сформувати manifest.json.'
                    );
                }

                $zip->addFromString(
                    'backup/manifest.json',
                    $manifestJson . "\n"
                );
                $zip->addFromString(
                    'backup/RESTORE.txt',
                    $this->restoreInstructions(
                        $databaseInfo['database'] ?? ''
                    )
                );
            } finally {
                $zip->close();
            }

            @unlink($dumpPath);
            $dumpPath = '';

            if (!is_file($archivePath) || filesize($archivePath) <= 0) {
                throw new RuntimeException(
                    'ZIP-архів створено некоректно або він порожній.'
                );
            }

            AdminAccess::audit(
                'system.backup_created',
                [
                    'file' => $filename,
                    'size' => (int) filesize($archivePath),
                    'project_files' => (int) $stats['files'],
                    'database_tables' => (int) (
                        $databaseInfo['tables'] ?? 0
                    )
                ],
                AdminAccess::currentId()
            );

            header(
                'Location: /Anabelka/admin/system/backup?created='
                . rawurlencode($filename)
            );
            exit;
        } catch (Throwable $e) {
            if ($dumpPath !== '') {
                @unlink($dumpPath);
            }

            if ($archivePath !== '') {
                @unlink($archivePath);
            }

            $_SESSION['admin_backup_error'] = $e->getMessage();

            header('Location: /Anabelka/admin/system/backup');
            exit;
        }
    }


    public function download()
    {
        $this->assertDeveloper();

        $filename = $this->safeBackupName($_GET['file'] ?? '');

        if ($filename === '') {
            http_response_code(404);
            exit('Архів не знайдено.');
        }

        $path = $this->backupDirectory()
            . DIRECTORY_SEPARATOR
            . $filename;

        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            exit('Архів не знайдено.');
        }

        AdminAccess::audit(
            'system.backup_downloaded',
            [
                'file' => $filename,
                'size' => (int) filesize($path)
            ],
            AdminAccess::currentId()
        );

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: application/zip');
        header(
            'Content-Disposition: attachment; filename="'
            . str_replace('"', '', $filename)
            . '"'
        );
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }


    private function assertDeveloper()
    {
        $admin = AdminAccess::current();

        if (
            !$admin
            || (string) ($admin['role_slug'] ?? '') !== 'owner'
        ) {
            http_response_code(403);
            exit('Резервні копії доступні лише розробнику.');
        }
    }


    private function assertCsrf()
    {
        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            throw new RuntimeException(
                'Сесію підтвердження втрачено. Оновіть сторінку.'
            );
        }
    }


    private function projectRoot()
    {
        $root = realpath(__DIR__ . '/../..');

        if ($root === false || !is_dir($root)) {
            throw new RuntimeException(
                'Не вдалося визначити каталог проєкту.'
            );
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }


    private function backupDirectory()
    {
        $path = $this->projectRoot()
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::BACKUP_DIR);

        if (!is_dir($path) && !mkdir($path, 0750, true)) {
            throw new RuntimeException(
                'Не вдалося створити каталог резервних копій.'
            );
        }

        if (!is_writable($path)) {
            throw new RuntimeException(
                'Каталог резервних копій недоступний для запису.'
            );
        }

        return $path;
    }


    private function protectBackupDirectory($directory)
    {
        $rules = "Options -Indexes\n\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n\n"
            . "<IfModule mod_access_compat.c>\n"
            . "    Deny from all\n"
            . "</IfModule>\n";

        $path = $directory . DIRECTORY_SEPARATOR . '.htaccess';

        if (!is_file($path)) {
            @file_put_contents($path, $rules, LOCK_EX);
        }
    }


    private function listBackups()
    {
        try {
            $directory = $this->backupDirectory();
        } catch (Throwable $e) {
            return [];
        }

        $files = glob(
            $directory
            . DIRECTORY_SEPARATOR
            . self::ARCHIVE_PREFIX
            . '*.zip'
        );

        if (!is_array($files)) {
            return [];
        }

        usort($files, function ($first, $second) {
            return (int) @filemtime($second)
                <=> (int) @filemtime($first);
        });

        $result = [];

        foreach (array_slice($files, 0, 20) as $path) {
            if (!is_file($path)) {
                continue;
            }

            $result[] = [
                'name' => basename($path),
                'size' => (int) filesize($path),
                'created_at' => (int) filemtime($path)
            ];
        }

        return $result;
    }


    private function safeBackupName($value)
    {
        $value = basename(trim((string) $value));

        if (!preg_match(
            '/^Anabelka_full_[0-9]{4}-[0-9]{2}-[0-9]{2}_'
            . '[0-9]{2}-[0-9]{2}-[0-9]{2}_[a-f0-9]{8}\.zip$/',
            $value
        )) {
            return '';
        }

        return $value;
    }


    private function addProjectDirectory(
        ZipArchive $zip,
        $root,
        $directory,
        array &$stats
    ) {
        $entries = scandir($directory);

        if ($entries === false) {
            throw new RuntimeException(
                'Не вдалося прочитати каталог: ' . $directory
            );
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            $relative = ltrim(
                str_replace('\\', '/', substr($path, strlen($root))),
                '/'
            );

            if ($this->isExcludedPath($relative)) {
                continue;
            }

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $zip->addEmptyDir('Anabelka/' . $relative);
                $this->addProjectDirectory(
                    $zip,
                    $root,
                    $path,
                    $stats
                );
                continue;
            }

            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            if (!$zip->addFile($path, 'Anabelka/' . $relative)) {
                throw new RuntimeException(
                    'Не вдалося додати файл до ZIP: ' . $relative
                );
            }

            $stats['files']++;
            $size = @filesize($path);

            if ($size !== false) {
                $stats['bytes'] += (int) $size;
            }
        }
    }


    private function isExcludedPath($relative)
    {
        $relative = trim(
            str_replace('\\', '/', (string) $relative),
            '/'
        );

        foreach ([
            '.git',
            'storage/full-backups',
            'storage/deploy-backups'
        ] as $excluded) {
            if (
                $relative === $excluded
                || strpos($relative, $excluded . '/') === 0
            ) {
                return true;
            }
        }

        return false;
    }


    private function writeDatabaseDump($path)
    {
        $db = Database::connect();
        $database = (string) $db->query('SELECT DATABASE()')
            ->fetchColumn();

        if ($database === '') {
            throw new RuntimeException(
                'Не вдалося визначити поточну базу даних.'
            );
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException(
                'Не вдалося створити тимчасовий SQL-дамп.'
            );
        }

        $info = [
            'database' => $database,
            'tables' => 0,
            'views' => 0,
            'rows' => 0,
            'triggers' => 0
        ];

        try {
            $this->writeDumpHeader($handle, $db, $database);

            $db->exec(
                'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'
            );
            $db->beginTransaction();

            $objects = $db->query('SHOW FULL TABLES')
                ->fetchAll(PDO::FETCH_NUM);
            $tables = [];
            $views = [];

            foreach ($objects as $object) {
                $name = (string) ($object[0] ?? '');
                $type = strtoupper((string) ($object[1] ?? ''));

                if ($name === '') {
                    continue;
                }

                if ($type === 'VIEW') {
                    $views[] = $name;
                } else {
                    $tables[] = $name;
                }
            }

            foreach ($tables as $table) {
                $info['rows'] += $this->dumpTable(
                    $handle,
                    $db,
                    $table
                );
                $info['tables']++;
            }

            if ($db->inTransaction()) {
                $db->commit();
            }

            foreach ($views as $view) {
                $this->dumpView($handle, $db, $view);
                $info['views']++;
            }

            $info['triggers'] = $this->dumpTriggers($handle, $db);

            fwrite(
                $handle,
                "\nSET FOREIGN_KEY_CHECKS=1;\n"
                . "SET UNIQUE_CHECKS=1;\n"
                . "-- End of Anabelka database dump\n"
            );
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        } finally {
            fclose($handle);
        }

        return $info;
    }


    private function writeDumpHeader($handle, PDO $db, $database)
    {
        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        try {
            $stmt = $db->prepare("
                SELECT
                    DEFAULT_CHARACTER_SET_NAME,
                    DEFAULT_COLLATION_NAME
                FROM information_schema.SCHEMATA
                WHERE SCHEMA_NAME = :database
                LIMIT 1
            ");
            $stmt->execute(['database' => $database]);
            $schema = $stmt->fetch(PDO::FETCH_NUM);

            if ($schema) {
                $charset = (string) ($schema[0] ?? $charset);
                $collation = (string) ($schema[1] ?? $collation);
            }
        } catch (Throwable $e) {
            // Safe defaults above are enough for a portable restore.
        }

        $databaseId = $this->quoteIdentifier($database);
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', $charset);
        $collation = preg_replace('/[^a-zA-Z0-9_]/', '', $collation);

        fwrite(
            $handle,
            "-- Anabelka full database backup\n"
            . "-- Created: " . date(DATE_ATOM) . "\n"
            . "-- Database: " . $database . "\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET UNIQUE_CHECKS=0;\n"
            . "CREATE DATABASE IF NOT EXISTS {$databaseId}"
            . " CHARACTER SET {$charset} COLLATE {$collation};\n"
            . "USE {$databaseId};\n\n"
        );
    }


    private function dumpTable($handle, PDO $db, $table)
    {
        $id = $this->quoteIdentifier($table);
        $create = $db->query('SHOW CREATE TABLE ' . $id)
            ->fetch(PDO::FETCH_NUM);

        if (!$create || empty($create[1])) {
            throw new RuntimeException(
                'Не вдалося отримати структуру таблиці ' . $table
            );
        }

        fwrite(
            $handle,
            "\n-- Table: {$table}\n"
            . "DROP TABLE IF EXISTS {$id};\n"
            . (string) $create[1]
            . ";\n"
        );

        $stmt = $db->query('SELECT * FROM ' . $id);
        $rows = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_map(
                [$this, 'quoteIdentifier'],
                array_keys($row)
            );
            $values = [];

            foreach ($row as $value) {
                $values[] = $this->sqlValue($db, $value);
            }

            fwrite(
                $handle,
                'INSERT INTO '
                . $id
                . ' ('
                . implode(', ', $columns)
                . ') VALUES ('
                . implode(', ', $values)
                . ");\n"
            );
            $rows++;
        }

        return $rows;
    }


    private function dumpView($handle, PDO $db, $view)
    {
        $id = $this->quoteIdentifier($view);
        $create = $db->query('SHOW CREATE VIEW ' . $id)
            ->fetch(PDO::FETCH_ASSOC);

        if (!$create) {
            return;
        }

        $sql = (string) (
            $create['Create View']
            ?? $create['Create view']
            ?? ''
        );

        if ($sql === '') {
            return;
        }

        fwrite(
            $handle,
            "\n-- View: {$view}\n"
            . "DROP VIEW IF EXISTS {$id};\n"
            . $this->portableCreateStatement($sql)
            . ";\n"
        );
    }


    private function dumpTriggers($handle, PDO $db)
    {
        try {
            $triggers = $db->query('SHOW TRIGGERS')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return 0;
        }

        $count = 0;

        foreach ($triggers as $trigger) {
            $name = (string) ($trigger['Trigger'] ?? '');

            if ($name === '') {
                continue;
            }

            try {
                $row = $db->query(
                    'SHOW CREATE TRIGGER '
                    . $this->quoteIdentifier($name)
                )->fetch(PDO::FETCH_ASSOC);
                $sql = $this->findCreateStatement($row, 'TRIGGER');

                if ($sql === '') {
                    continue;
                }

                fwrite(
                    $handle,
                    "\n-- Trigger: {$name}\n"
                    . 'DROP TRIGGER IF EXISTS '
                    . $this->quoteIdentifier($name)
                    . ";\nDELIMITER $$\n"
                    . $this->portableCreateStatement($sql)
                    . "$$\nDELIMITER ;\n"
                );
                $count++;
            } catch (Throwable $e) {
                // One unsupported trigger must not invalidate the whole dump.
            }
        }

        return $count;
    }


    private function findCreateStatement($row, $type)
    {
        if (!is_array($row)) {
            return '';
        }

        $type = strtoupper((string) $type);

        foreach ($row as $key => $value) {
            $text = trim((string) $value);

            if (
                (
                    stripos((string) $key, 'Create') !== false
                    || stripos($text, 'CREATE ') === 0
                )
                && stripos($text, $type) !== false
            ) {
                return $text;
            }
        }

        return '';
    }


    private function portableCreateStatement($sql)
    {
        return preg_replace(
            '/DEFINER=\x60[^\x60]+\x60@\x60[^\x60]+\x60\s*/i',
            '',
            (string) $sql
        );
    }


    private function quoteIdentifier($value)
    {
        $tick = chr(96);

        return $tick
            . str_replace($tick, $tick . $tick, (string) $value)
            . $tick;
    }


    private function sqlValue(PDO $db, $value)
    {
        if ($value === null) {
            return 'NULL';
        }

        $quoted = $db->quote((string) $value);

        if ($quoted === false) {
            return '0x' . bin2hex((string) $value);
        }

        return $quoted;
    }


    private function restoreInstructions($database)
    {
        return "ANABELKA — FULL BACKUP\n"
            . "======================\n\n"
            . "Архив содержит:\n"
            . "1. Anabelka/ — полный снимок файлов проекта.\n"
            . "2. backup/database.sql — структура и данные базы.\n"
            . "3. backup/manifest.json — сведения о резервной копии.\n\n"
            . "ВОССТАНОВЛЕНИЕ\n"
            . "1. Остановить сайт или включить режим обслуживания.\n"
            . "2. Сохранить отдельную копию текущих файлов и БД.\n"
            . "3. Заменить каталог проекта содержимым папки Anabelka/.\n"
            . "4. В phpMyAdmin импортировать backup/database.sql"
            . ($database !== '' ? " в базу {$database}" : '')
            . ".\n"
            . "5. Проверить config/database.php и локальные OAuth/API конфиги.\n"
            . "6. Запустить сайт и проверить вход в админ-панель, каталог,"
            . " корзину и оформление заказа.\n\n"
            . "ВАЖНО: архив содержит локальные конфиги и может содержать"
            . " пароли/API-ключи. Хранить только в закрытом месте.\n";
    }
}

<?php

class AdminUserController extends Controller
{
    public function index()
    {
        $filters = AdminUser::normalizeFilters($_GET);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 100;
        $total = AdminUser::count($filters);
        $pages = max(1, (int) ceil($total / $perPage));

        if ($page > $pages) {
            $page = $pages;
        }

        $inviteFlash = $_SESSION['admin_user_invite_flash'] ?? null;
        unset($_SESSION['admin_user_invite_flash']);

        $this->view('admin/users/index', [
            'pageTitle' => 'Адмін-панель · Користувачі',
            'users' => AdminUser::page($filters, $page, $perPage),
            'ranks' => AdminUser::ranks(true),
            'assignableRanks' => AdminUser::assignableRanks(),
            'rankRequests' => CustomerRankRequest::pendingForAdmin(),
            'history' => AdminUser::recentRankHistory(100),
            'summary' => AdminUser::summary(),
            'filters' => $filters,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'inviteFlash' => is_array($inviteFlash) ? $inviteFlash : null,
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? ''))
        ]);
    }


    public function approveRankRequest()
    {
        try {
            $this->verifyCsrf();
            $note = trim((string) ($_POST['note'] ?? ''));
            $result = CustomerRankRequest::approve(
                $_POST['request_id'] ?? 0,
                $_POST['rank_id'] ?? 0,
                AdminAccess::currentId(),
                $note
            );

            $this->createRankDecisionNotification(
                (int) ($result['user_id'] ?? 0),
                'rank_request_approved',
                (int) ($result['request_id'] ?? 0),
                [
                    'rank_name' => (string) ($result['new_rank_name'] ?? ''),
                    'admin_note' => $note
                ]
            );

            AdminAccess::audit(
                'customer.rank_request_approved',
                [
                    'request_id' => (int) ($result['request_id'] ?? 0),
                    'user_id' => (int) ($result['user_id'] ?? 0),
                    'old_rank_id' => (int) ($result['old_rank_id'] ?? 0),
                    'new_rank_id' => (int) ($result['new_rank_id'] ?? 0),
                    'new_rank_name' => (string) ($result['new_rank_name'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirectBack('', 'message', 'Запит схвалено. Ранг користувача підвищено.');
        } catch (Throwable $e) {
            $this->redirectBack('', 'error', $e->getMessage());
        }
    }


    public function rejectRankRequest()
    {
        try {
            $this->verifyCsrf();
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $note = trim((string) ($_POST['note'] ?? ''));

            $result = CustomerRankRequest::reject(
                $requestId,
                AdminAccess::currentId(),
                $note
            );

            $this->createRankDecisionNotification(
                (int) ($result['user_id'] ?? 0),
                'rank_request_rejected',
                $requestId,
                ['admin_note' => $note]
            );

            AdminAccess::audit(
                'customer.rank_request_rejected',
                [
                    'request_id' => $requestId,
                    'user_id' => (int) ($result['user_id'] ?? 0),
                    'note' => $note
                ],
                AdminAccess::currentId()
            );

            $this->redirectBack('', 'message', 'Запит на підвищення рангу відхилено.');
        } catch (Throwable $e) {
            $this->redirectBack('', 'error', $e->getMessage());
        }
    }


    public function createInvitation()
    {
        $createdByUserId = !empty($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;

        try {
            $result = UserInvitation::createAccount(
                $_POST['invite_name'] ?? '',
                $_POST['invite_email'] ?? '',
                $_POST['invite_rank_id'] ?? 0,
                $_POST['invite_channel'] ?? 'other',
                $_POST['invite_contact'] ?? '',
                $createdByUserId
            );

            $_SESSION['admin_user_invite_flash'] = $result;

            header(
                'Location: /Anabelka/admin/users?'
                . http_build_query([
                    'message' => 'Акаунт для запрошення створено.'
                ])
            );
            exit;
        } catch (Throwable $e) {
            header(
                'Location: /Anabelka/admin/users?'
                . http_build_query([
                    'error' => $e->getMessage()
                ])
            );
            exit;
        }
    }


    public function markInvitationSent()
    {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $returnQuery = trim((string) ($_POST['return_query'] ?? ''));

        try {
            UserInvitation::markSent($userId);
            $this->redirectBack(
                $returnQuery,
                'message',
                'Запрошення позначено як надіслане.'
            );
        } catch (Throwable $e) {
            $this->redirectBack(
                $returnQuery,
                'error',
                $e->getMessage()
            );
        }
    }


    public function updateRank()
    {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $rankId = (int) ($_POST['rank_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        $returnQuery = trim((string) ($_POST['return_query'] ?? ''));
        $changedByUserId = !empty($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;

        try {
            $result = AdminUser::changeRank(
                $userId,
                $rankId,
                $changedByUserId,
                $note
            );

            if (
                $userId > 0
                && !empty($_SESSION['user_id'])
                && $userId === (int) $_SESSION['user_id']
                && !empty($result['rank']['slug'])
            ) {
                $_SESSION['user_rank_slug'] = (string) $result['rank']['slug'];
            }

            $message = !empty($result['changed'])
                ? 'Ранг користувача змінено.'
                : 'Користувач уже має цей ранг.';

            $this->redirectBack($returnQuery, 'message', $message);
        } catch (Throwable $e) {
            $this->redirectBack(
                $returnQuery,
                'error',
                $e->getMessage()
            );
        }
    }


    public function deleteAccount()
    {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $returnQuery = trim((string) ($_POST['return_query'] ?? ''));

        try {
            $this->verifyCsrf();
            $user = AdminUser::deleteAccount($userId);

            if (
                !empty($_SESSION['user_id'])
                && (int) $_SESSION['user_id'] === $userId
            ) {
                unset(
                    $_SESSION['user_id'],
                    $_SESSION['user_name'],
                    $_SESSION['user_rank_slug']
                );
            }

            AdminAccess::audit(
                'customer.account_deleted',
                [
                    'user_id' => $userId,
                    'name' => (string) ($user['name'] ?? ''),
                    'email' => (string) ($user['email'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirectBack(
                $returnQuery,
                'message',
                'Акаунт користувача видалено.'
            );
        } catch (Throwable $e) {
            $this->redirectBack(
                $returnQuery,
                'error',
                $e->getMessage()
            );
        }
    }


    public function deactivateAccount()
    {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $returnQuery = trim((string) ($_POST['return_query'] ?? ''));

        try {
            $this->verifyCsrf();
            $result = AdminUser::deactivateAccount($userId);
            $user = $result['user'] ?? [];

            if (
                !empty($_SESSION['user_id'])
                && (int) $_SESSION['user_id'] === $userId
            ) {
                unset(
                    $_SESSION['user_id'],
                    $_SESSION['user_name'],
                    $_SESSION['user_rank_slug']
                );
            }

            if (!empty($result['changed'])) {
                AdminAccess::audit(
                    'customer.account_deactivated',
                    [
                        'user_id' => $userId,
                        'name' => (string) ($user['name'] ?? ''),
                        'email' => (string) ($user['email'] ?? '')
                    ],
                    AdminAccess::currentId()
                );
            }

            $this->redirectBack(
                $returnQuery,
                'message',
                !empty($result['changed'])
                    ? 'Акаунт користувача деактивовано.'
                    : 'Акаунт уже деактивований.'
            );
        } catch (Throwable $e) {
            $this->redirectBack(
                $returnQuery,
                'error',
                $e->getMessage()
            );
        }
    }


    private function createRankDecisionNotification(
        $userId,
        $type,
        $requestId,
        array $data
    ) {
        try {
            CustomerNotification::create(
                (int) $userId,
                (string) $type,
                $data,
                'rank_request',
                (int) $requestId
            );
        } catch (Throwable $e) {
            error_log('Customer notification error: ' . $e->getMessage());
        }
    }


    private function verifyCsrf()
    {
        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            throw new RuntimeException(
                'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
            );
        }
    }


    private function redirectBack($returnQuery, $key, $message)
    {
        $query = [];

        if ($returnQuery !== '') {
            parse_str($returnQuery, $query);
        }

        unset($query['message'], $query['error']);
        $query[$key] = $message;
        $url = '/Anabelka/admin/users';
        $encoded = http_build_query($query);

        if ($encoded !== '') {
            $url .= '?' . $encoded;
        }

        header('Location: ' . $url);
        exit;
    }
}

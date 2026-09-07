<?php

class AdminUserRankController extends Controller
{
    public function index()
    {
        try {
            $defaultRank = UserRank::defaultRegistrationRank();
            $error = trim((string) ($_GET['error'] ?? ''));
        } catch (Throwable $e) {
            $defaultRank = null;
            $error = trim((string) ($_GET['error'] ?? ''));

            if ($error === '') {
                $error = $e->getMessage();
            }
        }

        $this->view('admin/ranks/index', [
            'pageTitle' => 'Адмін-панель · Ранги',
            'ranks' => UserRank::allWithUsage(),
            'summary' => UserRank::summary(),
            'defaultRank' => $defaultRank,
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => $error
        ]);
    }


    public function create()
    {
        try {
            UserRank::create(
                $_POST['rank_name'] ?? ''
            );

            $this->redirect('message', 'Ранг створено та додано в кінець списку.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function update()
    {
        try {
            UserRank::update(
                $_POST['rank_id'] ?? 0,
                $_POST['name'] ?? ''
            );

            $this->redirect('message', 'Ранг оновлено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function move()
    {
        try {
            $moved = UserRank::move(
                $_POST['rank_id'] ?? 0,
                $_POST['direction'] ?? ''
            );

            $this->redirect(
                'message',
                $moved
                    ? 'Порядок рангів змінено.'
                    : 'Ранг уже знаходиться на межі списку.'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function toggle()
    {
        try {
            $active = UserRank::toggle(
                $_POST['rank_id'] ?? 0
            );

            $this->redirect(
                'message',
                $active ? 'Ранг увімкнено.' : 'Ранг вимкнено.'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function setDefault()
    {
        try {
            $rank = UserRank::setDefaultRegistrationRank(
                $_POST['rank_id'] ?? 0
            );

            $this->redirect(
                'message',
                'Нові користувачі отримуватимуть ранг «'
                    . ($rank['name'] ?? '')
                    . '».'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    private function redirect($key, $message)
    {
        header(
            'Location: /Anabelka/admin/ranks?'
            . http_build_query([$key => $message])
        );
        exit;
    }
}

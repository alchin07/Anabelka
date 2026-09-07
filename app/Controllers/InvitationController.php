<?php

class InvitationController extends Controller
{
    public function form()
    {
        PublicInterfaceTranslator::seed();

        $token = trim((string) ($_GET['token'] ?? ''));
        $invite = $token !== ''
            ? UserInvitation::findByToken($token)
            : null;

        $this->view('auth/invite', [
            'token' => $token,
            'invite' => $invite,
            'error' => $invite
                ? ''
                : 'Запрошення недійсне або строк його дії закінчився.'
        ]);
    }


    public function accept()
    {
        PublicInterfaceTranslator::seed();

        $token = trim((string) ($_POST['token'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($password !== $passwordConfirm) {
            $this->view('auth/invite', [
                'token' => $token,
                'invite' => UserInvitation::findByToken($token),
                'error' => 'Паролі не співпадають.'
            ]);
            return;
        }

        try {
            $user = UserInvitation::accept($token, $password);

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['user_name'] = (string) $user['name'];
            $_SESSION['user_rank_slug'] = (string) $user['rank_slug'];

            Cart::getOrCreateByUserId(
                $_SESSION['user_id']
            );

            Cart::mergeSessionCart(
                $_SESSION['user_id'],
                $_SESSION['cart'] ?? []
            );

            Favorite::mergeSessionToUser(
                $_SESSION['user_id']
            );

            $_SESSION['cart'] = [];

            header('Location: /Anabelka/');
            exit;
        } catch (Throwable $e) {
            $this->view('auth/invite', [
                'token' => $token,
                'invite' => UserInvitation::findByToken($token),
                'error' => $e->getMessage()
            ]);
        }
    }
}

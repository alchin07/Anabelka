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

        $ranks = UserRank::allWithUsage();

        foreach ($ranks as &$rank) {
            $rank['translations'] = UserRankTranslator::getForRank(
                (int) ($rank['id'] ?? 0)
            );
        }
        unset($rank);

        $this->view('admin/ranks/index', [
            'pageTitle' => 'Адмін-панель · Ранги',
            'ranks' => $ranks,
            'summary' => UserRank::summary(),
            'defaultRank' => $defaultRank,
            'languages' => Language::active(),
            'translationStatusOptions' => TranslationWorkflow::statusOptions(),
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
            $rankId = (int) ($_POST['rank_id'] ?? 0);
            $before = UserRank::find($rankId);

            if (!$before) {
                throw new RuntimeException('Ранг не знайдено.');
            }

            $storedTranslations = UserRankTranslator::getForRank($rankId);
            $newName = trim((string) ($_POST['name'] ?? ''));
            $sourceChanged = trim((string) ($before['name'] ?? '')) !== $newName;

            UserRank::update($rankId, $newName);

            if ($sourceChanged) {
                UserRankTranslator::markOutdated($rankId);
            }

            $translationNames = is_array($_POST['translation_name'] ?? null)
                ? $_POST['translation_name']
                : [];
            $translationSources = is_array($_POST['translation_source'] ?? null)
                ? $_POST['translation_source']
                : [];
            $translationStatuses = is_array($_POST['translation_status'] ?? null)
                ? $_POST['translation_status']
                : [];

            foreach (Language::active() as $language) {
                $code = strtolower(trim((string) ($language['code'] ?? '')));

                if ($code === '' || $code === Language::SOURCE_CODE) {
                    continue;
                }

                if (!array_key_exists($code, $translationNames)) {
                    continue;
                }

                $translatedName = trim((string) ($translationNames[$code] ?? ''));
                $oldTranslatedName = trim((string) (
                    $storedTranslations[$code]['name'] ?? ''
                ));
                $translationChanged = $translatedName !== $oldTranslatedName;
                $status = $translationStatuses[$code] ?? 'approved';

                if (
                    $sourceChanged
                    && !$translationChanged
                    && $translatedName !== ''
                ) {
                    $status = TranslationWorkflow::STATUS_OUTDATED;
                }

                UserRankTranslator::saveForRank(
                    $rankId,
                    $code,
                    $translatedName,
                    $translationSources[$code] ?? 'manual',
                    $status
                );
            }

            $this->redirect('message', 'Ранг і переклади оновлено.');
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

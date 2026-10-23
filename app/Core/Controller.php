<?php

class Controller
{
    protected function recordPageView(string $type, int $entityId = 0, bool $adult = false): void
    {
        if (class_exists('VisitorPageViewCounter')) {
            VisitorPageViewCounter::recordRendered($type, $entityId, $adult);
        }
    }

    protected function view($view, $data = [])
    {
        extract($data);

        $viewFile = __DIR__ . '/../../views/' . $view . '.php';

        if (!file_exists($viewFile)) {
            die("View not found: " . $view);
        }

        require $viewFile;
    }
}

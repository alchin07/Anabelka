<?php

class Controller
{
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
<?php

// Контроллер главной страницы сайта
class HomeController extends Controller
{
    // Метод отображает главную страницу
    public function index()
    {
        // Загружаем представление views/home.php
        $this->view('home');
    }
}
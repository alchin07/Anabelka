<?php

interface AITranslationProviderInterface
{
    public function getCode();

    public function getName();

    public function isConfigured();

    public function translate(
        array $targetLanguage,
        $name,
        $description = '',
        $context = 'catalog'
    );
}

<?php

class GiftCertificateController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();
        HomeInterfaceTranslator::seed();

        $this->view('gift-certificates/index', [
            'currentLanguage' => Translator::currentLanguage()
        ]);
    }
}

<?php

class LegalController extends Controller
{
    public function terms()
    {
        $this->show('terms');
    }


    public function privacy()
    {
        $this->show('privacy');
    }


    private function show($document)
    {
        PublicInterfaceTranslator::seed();
        RegistrationInterfaceTranslator::seed();

        $document = $document === 'privacy' ? 'privacy' : 'terms';
        $version = $document === 'privacy'
            ? RegistrationConsent::PRIVACY_VERSION
            : RegistrationConsent::TERMS_VERSION;

        $this->view('legal/document', [
            'document' => $document,
            'version' => $version
        ]);
    }
}

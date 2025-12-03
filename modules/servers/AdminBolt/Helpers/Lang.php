<?php

namespace ModulesGarden\AdminBolt\Helpers;

use WHMCS\Database\Capsule;

class Lang
{
    protected string $language = "english";
    protected string $languagePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lang" . DIRECTORY_SEPARATOR;
    protected array $langs = [];

    public function __construct()
    {
        $this->setLanguage();

        $langs = include($this->languagePath . $this->language . ".php");

        if(is_array($langs))
        {
            $this->langs = $langs;
        }
    }

    protected function setLanguage(): void
    {
        $sessionLanguage = $_SESSION['Language'];

        if($sessionLanguage && file_exists($this->languagePath . $sessionLanguage . ".php"))
        {
            $this->language = $sessionLanguage;

            return;
        }

        $clientLanguage = Capsule::table('tblclients')->where('id', '=', $_SESSION['uid'])->first(['language']);

        if($clientLanguage && file_exists($this->languagePath . $clientLanguage->language . ".php"))
        {
            $this->language = $clientLanguage->language;

            return;
        }

        $defaultLanguage = Capsule::table('tblconfiguration')->where('setting', '=', 'language')->first(['value']);

        if($defaultLanguage && file_exists($this->languagePath . $defaultLanguage->value . ".php"))
        {
            $this->language = $defaultLanguage->value;
        }
    }

    public function get(string $lang, array $params = []): string
    {
        $langValue = $this->langs[$lang] ?? $lang;

        foreach($params as $key => $param)
        {
            $langValue = str_replace(':' . $key, $param, $langValue);
        }

        return $langValue;
    }
}
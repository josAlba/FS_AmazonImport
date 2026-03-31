<?php

namespace FacturaScripts\Plugins\AmazonImport;

use FacturaScripts\Core\Template\InitClass;

final class Init extends InitClass
{
    public function init(): void
    {
        // No necesitamos cargar extensiones, solo el controlador
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
    }
}
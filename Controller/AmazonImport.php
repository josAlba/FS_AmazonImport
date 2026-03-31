<?php

namespace FacturaScripts\Plugins\AmazonImport\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AmazonImport\Lib\AmazonImportService;

class AmazonImport extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['title'] = 'Amazon Import';
        $data['menu'] = 'Importar';
        $data['icon'] = 'fab fa-amazon';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        
        // Procesar solicitud AJAX para obtener límite de subida
        if ($this->request->get('action') === 'get-max-upload') {
            $this->setTemplate(false);
            $response->setContent(json_encode([
                'maxUpload' => $this->getMaxFileUpload()
            ]));
            $response->headers->set('Content-Type', 'application/json');
            return;
        }

        // Procesar formulario si se envió
        if ($this->request->getMethod() === 'POST' && $this->validateFormToken()) {
            $file = $this->request->files->get('amazonfile');
            $mode = $this->request->request->get('mode', 'add');

            if ($file && $file->isValid()) {
                $service = new AmazonImportService();
                $service->import($file->getRealPath(), $mode);
            }
        }
    }
    
    /**
     * Return the max file size that can be uploaded.
     *
     * @return float
     */
    public function getMaxFileUpload(): float
    {
        $maxSize = ini_get('upload_max_filesize');
        $postMaxSize = ini_get('post_max_size');
        
        // Convertir a megabytes
        $convertToBytes = function($size) {
            $unit = strtolower(substr($size, -1));
            $value = (float) substr($size, 0, -1);
            
            switch ($unit) {
                case 'g':
                    return $value * 1024;
                case 'm':
                    return $value;
                case 'k':
                    return $value / 1024;
                default:
                    return $value / (1024 * 1024);
            }
        };
        
        $maxUpload = min($convertToBytes($maxSize), $convertToBytes($postMaxSize));
        return round($maxUpload, 2);
    }
}
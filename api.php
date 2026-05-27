<?php
/**
 * RESTful API Router for PDF Layout Parser
 * Provides endpoints for file management and processing
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class APIRouter
{
    private string $method;
    private string $path;
    private ?array $payload;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '/';
        $this->payload = null;

        if (in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $this->payload = json_decode(file_get_contents('php://input'), true);
            }
        }
    }

    public function route(): void
    {
        if (!API_ENABLED) {
            $this->error('API is disabled', 403);
            return;
        }

        // Rate limiting
        if (!$this->checkRateLimit()) {
            $this->error('Rate limit exceeded', 429);
            return;
        }

        try {
            // Route to appropriate handler
            if (preg_match('#^/api/files$#', $this->path)) {
                if ($this->method === 'GET') {
                    $this->listFiles();
                } elseif ($this->method === 'POST') {
                    $this->uploadFile();
                } else {
                    $this->error('Method not allowed', 405);
                }
            } elseif (preg_match('#^/api/files/([^/]+)$#', $this->path, $matches)) {
                $filename = $matches[1];
                if ($this->method === 'GET') {
                    $this->getFileContext($filename);
                } elseif ($this->method === 'DELETE') {
                    $this->deleteFile($filename);
                } else {
                    $this->error('Method not allowed', 405);
                }
            } elseif (preg_match('#^/api/files/([^/]+)/export$#', $this->path, $matches)) {
                $filename = $matches[1];
                $format = $_GET['format'] ?? 'json';
                $this->exportFile($filename, $format);
            } elseif (preg_match('#^/api/process$#', $this->path)) {
                if ($this->method === 'POST') {
                    $this->processFiles();
                } else {
                    $this->error('Method not allowed', 405);
                }
            } elseif (preg_match('#^/api/health$#', $this->path)) {
                $this->health();
            } else {
                $this->error('Endpoint not found', 404);
            }
        } catch (Exception $e) {
            $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }

    private function listFiles(): void
    {
        $files = glob(UPLOAD_DIR . DIRECTORY_SEPARATOR . '*.pdf');
        $filesList = [];

        if ($files) {
            foreach ($files as $filepath) {
                $basename = basename($filepath);
                $filesList[] = [
                    'name' => $basename,
                    'size' => filesize($filepath),
                    'size_formatted' => format_bytes(filesize($filepath)),
                    'modified' => filemtime($filepath),
                    'modified_formatted' => date('Y-m-d H:i:s', filemtime($filepath))
                ];
            }
        }

        usort($filesList, fn($a, $b) => $b['modified'] <=> $a['modified']);
        $this->json(['files' => $filesList, 'total' => count($filesList)]);
    }

    private function getFileContext(string $filename): void
    {
        $filename = sanitize_filename(basename($filename));
        $contextPath = CONTEXT_DIR . DIRECTORY_SEPARATOR . $filename . '.json';

        if (!file_exists($contextPath)) {
            $this->error('Context file not found', 404);
            return;
        }

        $context = json_decode(file_get_contents($contextPath), true);
        $this->json([
            'filename' => $filename,
            'pages' => $context,
            'page_count' => count($context ?? [])
        ]);
    }

    private function uploadFile(): void
    {
        if (!isset($_FILES['pdf_file'])) {
            $this->error('No file provided', 400);
            return;
        }

        $file = $_FILES['pdf_file'];
        $errors = validate_file_upload($file);

        if (!empty($errors)) {
            $this->error(implode('; ', $errors), 400);
            return;
        }

        $filename = sanitize_filename($file['name']);
        $targetPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($targetPath) && !($this->payload['overwrite'] ?? false)) {
            $this->error('File already exists', 409);
            return;
        }

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $this->error('Failed to move uploaded file', 500);
            return;
        }

        $this->json(['success' => true, 'filename' => $filename, 'size' => $file['size']], 201);
    }

    private function deleteFile(string $filename): void
    {
        $filename = sanitize_filename(basename($filename));
        $pdfPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;
        $contextPath = CONTEXT_DIR . DIRECTORY_SEPARATOR . $filename . '.json';

        if (!file_exists($pdfPath)) {
            $this->error('File not found', 404);
            return;
        }

        $success = true;
        if (!@unlink($pdfPath)) {
            $success = false;
        }
        if (file_exists($contextPath) && !@unlink($contextPath)) {
            $success = false;
        }

        if ($success) {
            $this->json(['success' => true, 'message' => 'File deleted']);
        } else {
            $this->error('Failed to delete file', 500);
        }
    }

    private function exportFile(string $filename, string $format): void
    {
        $filename = sanitize_filename(basename($filename));
        $contextPath = CONTEXT_DIR . DIRECTORY_SEPARATOR . $filename . '.json';

        if (!file_exists($contextPath)) {
            $this->error('Context file not found', 404);
            return;
        }

        $context = json_decode(file_get_contents($contextPath), true);

        if ($format === 'csv') {
            $this->exportAsCSV($filename, $context);
        } elseif ($format === 'xml') {
            $this->exportAsXML($filename, $context);
        } else {
            $this->json(['context' => $context]);
        }
    }

    private function processFiles(): void
    {
        if (!ENABLE_BATCH_PROCESSING) {
            $this->error('Batch processing is disabled on this server', 403);
            return;
        }

        $filenames = $this->payload['filenames'] ?? null;
        $processAll = $this->payload['process_all'] ?? false;
        $force = $this->payload['force'] ?? false;

        if ($filenames === null && !$processAll) {
            $this->error('No filenames provided', 400);
            return;
        }

        if ($processAll) {
            $files = glob(UPLOAD_DIR . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
            $filenames = array_map('basename', $files);
        }

        $results = [];
        $python = get_python_executable();
        // Use document_parser_agent CLI module (python -m agentic_model.document_manager_agent.document_parser_agent.cli)

        foreach ($filenames as $rawName) {
            $name = sanitize_filename(basename($rawName));
            $pdfPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $name;
            $contextOut = CONTEXT_DIR . DIRECTORY_SEPARATOR . $name . '.json';

            if (!file_exists($pdfPath)) {
                $results[$name] = ['success' => false, 'error' => 'File not found'];
                continue;
            }

            $cmd = sprintf('"%s" -m agentic_model.document_manager_agent.document_parser_agent.cli "%s" --no-summary -o "%s" 2>&1',
                $python, $pdfPath, $contextOut);

            $out = [];
            exec($cmd, $out, $rv);
            if ($rv !== 0) {
                $results[$name] = ['success' => false, 'exit' => $rv, 'output' => implode("\n", $out)];
            } else {
                $results[$name] = ['success' => true];
            }
        }

        $this->json(['results' => $results]);
    }

    private function exportAsCSV(string $filename, array $context): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Page', 'Block Type', 'X', 'Y', 'Width', 'Height', 'Text']);

        foreach ($context as $page) {
            $pageNum = $page['page_number'] ?? 0;
            foreach ($page['blocks'] ?? [] as $block) {
                fputcsv($output, [
                    $pageNum,
                    $block['kind'] ?? '',
                    $block['x'] ?? 0,
                    $block['y'] ?? 0,
                    $block['w'] ?? 0,
                    $block['h'] ?? 0,
                    $block['text'] ?? ''
                ]);
            }
        }

        fclose($output);
        exit;
    }

    private function exportAsXML(string $filename, array $context): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xml"');

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><document></document>');
        $xml->addAttribute('filename', $filename);

        foreach ($context as $page) {
            $pageElem = $xml->addChild('page');
            $pageElem->addAttribute('number', (string)($page['page_number'] ?? 0));
            $pageElem->addAttribute('width', (string)($page['width'] ?? 0));
            $pageElem->addAttribute('height', (string)($page['height'] ?? 0));

            foreach ($page['blocks'] ?? [] as $block) {
                $blockElem = $pageElem->addChild('block');
                $blockElem->addAttribute('type', $block['kind'] ?? '');
                $blockElem->addAttribute('x', (string)($block['x'] ?? 0));
                $blockElem->addAttribute('y', (string)($block['y'] ?? 0));
                $blockElem->addAttribute('width', (string)($block['w'] ?? 0));
                $blockElem->addAttribute('height', (string)($block['h'] ?? 0));
                $blockElem->addChild('text', htmlspecialchars($block['text'] ?? ''));
            }
        }

        echo $xml->asXML();
        exit;
    }

    private function health(): void
    {
        $this->json([
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '2.0.0',
            'features' => [
                'batch_processing' => ENABLE_BATCH_PROCESSING,
                'export_csv' => ENABLE_EXPORT_CSV,
                'export_xml' => ENABLE_EXPORT_XML,
                'text_search' => ENABLE_TEXT_SEARCH
            ]
        ]);
    }

    private function checkRateLimit(): bool
    {
        return true;
    }

    private function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    private function error(string $message, int $statusCode = 400): void
    {
        $this->json(['error' => $message], $statusCode);
    }
}

// Route API requests
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api') === 0) {
    $router = new APIRouter();
    $router->route();
}

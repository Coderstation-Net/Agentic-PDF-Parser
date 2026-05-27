<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';

$pdfUrl = '';
$extractedPages = [];
$error = null;
$success = null;
$totalPages = 0;
$processingStatus = null;

$jsonOut = CONTEXT_DIR . DIRECTORY_SEPARATOR . 'layout_output.json';

function getDocumentContextDir(string $filename): string {
    $safeName = sanitize_filename(basename($filename));
    return CONTEXT_DIR . DIRECTORY_SEPARATOR . $safeName;
}

function ensureDocumentContextDir(string $filename): string {
    $dir = getDocumentContextDir($filename);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Also ensure subdirectories exist
    $subdirs = ['extracted', 'sentence_fixer', 'fine_tuning', 'embeddings'];
    foreach ($subdirs as $sub) {
        $subDir = $dir . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($subDir)) {
            @mkdir($subDir, 0755, true);
        }
    }
    return $dir;
}

function clearDirectoryFiles(string $dir): void {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    if (!is_array($files)) return;
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function isPidRunning(int $pid): bool {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $output = [];
        exec("tasklist /FI \"PID eq $pid\" 2>&1", $output);
        foreach ($output as $line) {
            if (strpos($line, (string)$pid) !== false) {
                return true;
            }
        }
        return false;
    } else {
        $output = [];
        exec("ps -p $pid 2>&1", $output, $resultCode);
        return $resultCode === 0;
    }
}

function killProcess(int $pid): void {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec("taskkill /F /T /PID $pid 2>&1");
    } else {
        exec("kill -9 $pid 2>&1");
    }
}

function checkAndKillPreviousProcess(string $pidFilePath): void {
    if (is_file($pidFilePath)) {
        $pid = (int)trim((string)file_get_contents($pidFilePath));
        if ($pid > 0 && isPidRunning($pid)) {
            killProcess($pid);
        }
        @unlink($pidFilePath);
    }
}

function getProgressFromFile(string $progressPath, string $defaultTitle, string $pidPath = ''): array {
    if (is_file($progressPath)) {
        $data = json_decode((string)file_get_contents($progressPath), true);
        if (is_array($data)) {
            $percent = (int)($data['percent'] ?? 0);
            if ($percent < 100 && $pidPath && is_file($pidPath)) {
                $pid = (int)trim((string)file_get_contents($pidPath));
                if ($pid > 0 && !isPidRunning($pid)) {
                    $data['status'] = 'failed';
                }
            }
            return $data;
        }
    }
    
    if ($pidPath && is_file($pidPath)) {
        $pid = (int)trim((string)file_get_contents($pidPath));
        if ($pid > 0 && !isPidRunning($pid)) {
            return [
                'percent' => 0,
                'page' => 0,
                'total' => 0,
                'title' => $defaultTitle,
                'status' => 'failed'
            ];
        }
    }
    
    return [
        'percent' => 0,
        'page' => 0,
        'total' => 0,
        'title' => $defaultTitle,
        'status' => 'Initializing...'
    ];
}

function startBackgroundProcess(string $command): void {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start "" /B ' . $command, 'r'));
    } else {
        exec($command . ' > /dev/null 2>&1 &');
    }
}

function writePageJsonFiles(string $filename, array $pages): bool {
    $dir = ensureDocumentContextDir($filename) . DIRECTORY_SEPARATOR . 'extracted';
    $ok = true;
    foreach ($pages as $idx => $page) {
        $pageNum = isset($page['page_number']) ? (int) $page['page_number'] : ($idx + 1);
        if ($pageNum < 1) $pageNum = $idx + 1;
        $pagePath = $dir . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
        $enc = json_encode($page, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($enc === false || @file_put_contents($pagePath, $enc) === false) {
            $ok = false;
        }
    }
    return $ok;
}

function loadPageJsonFiles(string $filename): array {
    $contextDir = getDocumentContextDir($filename);
    $dir = $contextDir . DIRECTORY_SEPARATOR . 'extracted';
    if (!is_dir($dir)) return [];
    $pageFiles = glob($dir . DIRECTORY_SEPARATOR . 'page_*.json') ?: [];
    natsort($pageFiles);
    $pages = [];
    foreach ($pageFiles as $file) {
        if (!is_file($file)) continue;
        $row = json_decode((string) file_get_contents($file), true);
        if (is_array($row)) {
            $pages[] = $row;
        }
    }

    if (empty($pages)) return [];

    // Proactively load and merge fixed sentence blocks from the dedicated fixed JSON output file!
    $fixedDir = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer';
    if (is_dir($fixedDir)) {
        foreach ($pages as $idx => &$page) {
            $pNum = isset($page['page_number']) ? (int) $page['page_number'] : ($idx + 1);
            $fixedPagePath = $fixedDir . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pNum);
            if (is_file($fixedPagePath)) {
                $fp = json_decode(file_get_contents($fixedPagePath), true);
                if (is_array($fp) && isset($fp['fixed_blocks'])) {
                    $page['fixed_blocks'] = $fp['fixed_blocks'];
                } elseif (is_array($fp) && isset($fp['fixed'])) {
                    $page['fixed'] = $fp['fixed'];
                }
            }
        }
        unset($page);
    }

    // Proactively load and merge Fine Tuning Q&A pairs!
    $fineTunedPath = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json';
    if (is_file($fineTunedPath)) {
        $fineTunedRaw = file_get_contents($fineTunedPath);
        $fineTunedData = json_decode($fineTunedRaw, true);
        if (is_array($fineTunedData)) {
            foreach ($pages as $idx => &$page) {
                $pNum = isset($page['page_number']) ? (int) $page['page_number'] : ($idx + 1);
                $page['qa_pairs'] = [];
                foreach ($fineTunedData as $qa) {
                    $qNum = isset($qa['page_number']) ? (int) $qa['page_number'] : -1;
                    if ($qNum === $pNum) {
                        $page['qa_pairs'][] = $qa;
                    }
                }
            }
            unset($page);
        }
    }

    // Proactively load and merge Vector Embeddings!
    $embeddingsDir = $contextDir . DIRECTORY_SEPARATOR . 'embeddings';
    $embeddingsData = [];
    $embeddingsPath = $embeddingsDir . DIRECTORY_SEPARATOR . 'embeddings.json';
    if (is_file($embeddingsPath)) {
        $embeddingsRaw = file_get_contents($embeddingsPath);
        $embeddingsData = json_decode($embeddingsRaw, true) ?: [];
    }
    // Scan for individual page JSON files to override/complement
    if (is_dir($embeddingsDir)) {
        $pageFiles = glob($embeddingsDir . DIRECTORY_SEPARATOR . 'page_*.json') ?: [];
        foreach ($pageFiles as $pf) {
            $row = json_decode((string) @file_get_contents($pf), true);
            if (is_array($row)) {
                $pNum = (int)($row['page_number'] ?? 1);
                $found = false;
                foreach ($embeddingsData as $key => $emb) {
                    if ((int)($emb['page_number'] ?? -1) === $pNum) {
                        $embeddingsData[$key] = $row;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $embeddingsData[] = $row;
                }
            }
        }
    }

    if (is_array($embeddingsData)) {
        foreach ($pages as $idx => &$page) {
            $pNum = isset($page['page_number']) ? (int) $page['page_number'] : ($idx + 1);
            $page['embeddings_list'] = [];
            foreach ($embeddingsData as $emb) {
                $eNum = isset($emb['page_number']) ? (int) $emb['page_number'] : -1;
                if ($eNum === $pNum) {
                    $page['embeddings_list'][] = $emb;
                }
            }
        }
        unset($page);
    }

    return $pages;
}

function getExistingFolders(): array {
    $dir = CONTEXT_DIR;
    if (!is_dir($dir)) return [];
    $items = scandir($dir);
    if (!is_array($items)) return [];
    
    $folders = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (!is_dir($path)) continue;
        
        // Ensure the PDF file exists in the folder
        $pdfPath = $path . DIRECTORY_SEPARATOR . $item;
        if (!is_file($pdfPath)) continue;
        
        $hasExtracted = is_file($path . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . 'context.json');
        $hasFixed = is_file($path . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . 'fixed.json');
        $hasFineTuning = is_file($path . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json');
        $hasEmbeddings = is_file($path . DIRECTORY_SEPARATOR . 'embeddings' . DIRECTORY_SEPARATOR . 'embeddings.json');
        
        // Count pages
        $pagesCount = 0;
        $extractedDir = $path . DIRECTORY_SEPARATOR . 'extracted';
        if (is_dir($extractedDir)) {
            $pages = glob($extractedDir . DIRECTORY_SEPARATOR . 'page_*.json') ?: [];
            $pagesCount = count($pages);
        }
        
        $folders[] = [
            'name' => $item,
            'pages' => $pagesCount,
            'has_extracted' => $hasExtracted,
            'has_fixed' => $hasFixed,
            'has_fine_tuning' => $hasFineTuning,
            'has_embeddings' => $hasEmbeddings,
        ];
    }
    return $folders;
}

function renderContextBlocks($blocks) {
    if (!is_array($blocks) || empty($blocks)) return '';
    $rendered = '';
    $seen = [];
    foreach ($blocks as $block) {
        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '') continue;

        if (in_array($text, $seen, true)) continue;
        $seen[] = $text;

        $kind = isset($block['kind']) ? (string) $block['kind'] : 'Text';

        if (strtolower($kind) === 'table' && strpos($text, "|") !== false) {
            $lines = preg_split('/\r?\n/', $text);
            $hdr = array_map('trim', explode('|', trim($lines[0] ?? '', " |\t\n\r\0\x0B")));
            $secondRaw = $lines[1] ?? '';
            $sep = preg_replace('/[^\-|: ]/', '', trim($secondRaw));
            if (preg_match('/^[-\|: \t]+$/', $sep)) {
                $dataLinesSource = array_slice($lines, 2);
            } else {
                $dataLinesSource = array_slice($lines, 1);
            }

            $rows = [];
            foreach ($dataLinesSource as $row) {
                $cells = array_map('trim', explode('|', trim($row, " |\t\n\r\0\x0B")));
                if (count($cells) === 1 && $cells[0] === '') continue;
                $rows[] = $cells;
            }
            $colCount = max(1, count($hdr));
            foreach ($rows as &$r) {
                if (count($r) < $colCount) {
                    $r = array_pad($r, $colCount, '');
                } else if (count($r) > $colCount) {
                    $r = array_slice($r, 0, $colCount);
                }
            }
            unset($r);

            $mb_str_pad = function($input, $pad_length) {
                $diff = strlen($input) - mb_strlen($input, 'UTF-8');
                return str_pad($input, $pad_length + $diff);
            };

            $widths = array_fill(0, $colCount, 0);
            for ($i = 0; $i < $colCount; $i++) {
                $widths[$i] = mb_strlen($hdr[$i] ?? '', 'UTF-8');
            }
            foreach ($rows as $r) {
                for ($i = 0; $i < $colCount; $i++) {
                    $len = mb_strlen($r[$i] ?? '', 'UTF-8');
                    if ($len > $widths[$i]) $widths[$i] = $len;
                }
            }

            $padCells = function($cells) use ($widths, $mb_str_pad) {
                $out = [];
                for ($i = 0; $i < count($widths); $i++) {
                    $cell = $cells[$i] ?? '';
                    $out[] = ' ' . $mb_str_pad($cell, $widths[$i]) . ' ';
                }
                return '|' . implode('|', $out) . '|';
            };

            $headerLine = $padCells($hdr);
            $sepPieces = [];
            foreach ($widths as $w) $sepPieces[] = str_repeat('-', $w + 2);
            $sepLine = '|' . implode('|', $sepPieces) . '|';
            $dataLinesOut = [];
            foreach ($rows as $r) {
                $dataLinesOut[] = $padCells($r);
            }

            $ascii = $headerLine . "\n" . $sepLine . (count($dataLinesOut) ? "\n" . implode("\n", $dataLinesOut) : '');

            $rendered .= '<div class="extracted-table mb-3">';
                $rendered .= '<div class="page-block-text"><pre class="mb-0">' . htmlspecialchars($ascii) . '</pre></div>';
            $rendered .= '</div>';
            continue;
        }

        $paragraphs = preg_split('/\n\s*\n/', $text);
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $rendered .= '<div class="extracted-block mb-3">';
            $rendered .= '<div class="page-block-text">' . nl2br(htmlspecialchars($p)) . '</div>';
            $rendered .= '</div>';
        }
    }
    return $rendered;
}

// Support AJAX check for existing upload
if (isset($_GET['check_file']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $exists = is_file(getDocumentContextDir($name) . DIRECTORY_SEPARATOR . $name);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exists' => $exists], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX check for existing folder
if (isset($_GET['check_folder']) && isset($_GET['foldername'])) {
    $name = sanitize_filename(basename((string) $_GET['foldername']));
    $dir = getDocumentContextDir($name);
    $pdfPath = $dir . DIRECTORY_SEPARATOR . $name;
    $exists = is_dir($dir) && is_file($pdfPath);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exists' => $exists], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX get existing folders list
if (isset($_GET['get_existing_folders'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getExistingFolders(), JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX progress check (parser)
if (isset($_GET['get_progress']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $progressPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'progress.json';
    $pidPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'parser.pid';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getProgressFromFile($progressPath, 'Document Parser', $pidPath), JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX progress check (sentence fixer)
if (isset($_GET['get_progress_fixer']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $progressPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'fixer_progress.json';
    $pidPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'fixer.pid';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getProgressFromFile($progressPath, 'Sentence Fixer', $pidPath), JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX progress check (fine tuning)
if (isset($_GET['get_progress_fine_tuning']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $progressPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'fine_tuning_progress.json';
    $pidPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'fine_tuning.pid';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getProgressFromFile($progressPath, 'Fine Tuning Agent', $pidPath), JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX cancel parse
if (isset($_GET['cancel_parse']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $cancelPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'cancel.flag';
    $pidPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'parser.pid';
    @file_put_contents($cancelPath, '1');
    checkAndKillPreviousProcess($pidPath);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX cancel sentence fixer
if (isset($_GET['cancel_sentence_fixer']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $cancelPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'cancel_fixer.flag';
    $pidPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'fixer.pid';
    @file_put_contents($cancelPath, '1');
    checkAndKillPreviousProcess($pidPath);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX cancel fine tuning
if (isset($_GET['cancel_fine_tuning']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $cancelPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'cancel_fine_tuning.flag';
    $pidPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'fine_tuning.pid';
    @file_put_contents($cancelPath, '1');
    checkAndKillPreviousProcess($pidPath);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX progress check (embeddings)
if (isset($_GET['get_progress_embeddings']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $progressPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'embeddings_progress.json';
    $pidPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'embeddings.pid';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getProgressFromFile($progressPath, 'Embeddings Agent', $pidPath), JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX cancel embeddings
if (isset($_GET['cancel_embeddings']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $cancelPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'cancel_embeddings.flag';
    $pidPath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'embeddings.pid';
    @file_put_contents($cancelPath, '1');
    checkAndKillPreviousProcess($pidPath);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX get embeddings
if (isset($_GET['get_embeddings']) && isset($_GET['filename'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $contextDir = getDocumentContextDir($name);
    $embeddingsDir = $contextDir . DIRECTORY_SEPARATOR . 'embeddings';
    $embeddingsPath = $embeddingsDir . DIRECTORY_SEPARATOR . 'embeddings.json';
    
    $embeddingsData = [];
    if (is_file($embeddingsPath)) {
        $embeddingsData = json_decode(file_get_contents($embeddingsPath), true) ?: [];
    }
    
    // Supplement/overwrite with page_*.json files
    if (is_dir($embeddingsDir)) {
        $pageFiles = glob($embeddingsDir . DIRECTORY_SEPARATOR . 'page_*.json') ?: [];
        foreach ($pageFiles as $pf) {
            $row = json_decode((string) @file_get_contents($pf), true);
            if (is_array($row)) {
                $pNum = (int)($row['page_number'] ?? 1);
                $found = false;
                foreach ($embeddingsData as &$emb) {
                    if ((int)($emb['page_number'] ?? -1) === $pNum) {
                        $emb = $row;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $embeddingsData[] = $row;
                }
            }
        }
    }
    
    // Sort by page number
    usort($embeddingsData, function ($a, $b) {
        return ($a['page_number'] ?? 0) <=> ($b['page_number'] ?? 0);
    });
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($embeddingsData, JSON_UNESCAPED_UNICODE);
    exit;
}

// Support AJAX get page
if (isset($_GET['get_page']) && isset($_GET['filename']) && isset($_GET['page'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $page = (int) $_GET['page'];
    $pagePath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $page);
    header('Content-Type: application/json; charset=utf-8');
    if (is_file($pagePath)) {
        echo file_get_contents($pagePath);
    } else {
        echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Support AJAX get fixed page
if (isset($_GET['get_fixed_page']) && isset($_GET['filename']) && isset($_GET['page'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $page = (int) $_GET['page'];
    $pagePath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $page);
    header('Content-Type: application/json; charset=utf-8');
    if (is_file($pagePath)) {
        echo file_get_contents($pagePath);
    } else {
        echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Support AJAX get fine-tuned page
if (isset($_GET['get_fine_tuned_page']) && isset($_GET['filename']) && isset($_GET['page'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $page = (int) $_GET['page'];
    $pagePath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $page);
    header('Content-Type: application/json; charset=utf-8');
    if (is_file($pagePath)) {
        echo file_get_contents($pagePath);
    } else {
        echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Support AJAX get embeddings page
if (isset($_GET['get_embeddings_page']) && isset($_GET['filename']) && isset($_GET['page'])) {
    $name = sanitize_filename(basename((string) $_GET['filename']));
    $page = (int) $_GET['page'];
    $pagePath = getDocumentContextDir($name) . DIRECTORY_SEPARATOR . 'embeddings' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $page);
    header('Content-Type: application/json; charset=utf-8');
    if (is_file($pagePath)) {
        echo file_get_contents($pagePath);
    } else {
        echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// If user requested to load a specific existing file, store in session and redirect to clean URL
if (isset($_GET['load_file'])) {
    $_SESSION['current_pdf'] = (string) $_GET['load_file'];
    header('Location: ' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Clear current session file
if (isset($_GET['clear_file'])) {
    unset($_SESSION['current_pdf']);
    header('Location: ' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Get current file from session
$currentFile = $_SESSION['current_pdf'] ?? null;
if ($currentFile) {
    $requested = sanitize_filename(basename((string) $currentFile));
    $path = getDocumentContextDir($requested) . DIRECTORY_SEPARATOR . $requested;
    if (is_file($path)) {
        $pdfUrl = 'contexts/' . rawurlencode($requested) . '/' . rawurlencode($requested);
    } else {
        // Fallback for legacy files
        $legacyPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $requested;
        if (is_file($legacyPath)) {
            $pdfUrl = 'uploads/' . rawurlencode($requested);
        }
    }
}

// Handle context edits save via JSON request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');

        $rawPayload = (string) file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);

        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid request.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $action = $payload['action'] ?? '';
        
        // Single Page Actions
        if (in_array($action, ['fix_sentences_page', 'fine_tune_page', 'generate_embeddings_page'])) {
            $pageData = $payload['page'] ?? [];
            if (empty($pageData)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No page data provided.']);
                exit;
            }

            $pythonCmd = get_python_executable();
            $filename = isset($payload['filename']) ? trim((string) $payload['filename']) : '';
            $activeFile = $_SESSION['current_pdf'] ?? '';
            $loadedFilename = '';
            if ($activeFile) {
                $loadedFilename = rawurldecode(basename($activeFile));
            } elseif ($filename) {
                $loadedFilename = rawurldecode(basename($filename));
            }

            $cmdOutput = [];
            session_write_close();

            if ($action === 'fix_sentences_page' && $loadedFilename) {
                // Use the extracted page files directly via --input-dir and --page-num
                $pageNumber = isset($pageData['page_number']) ? (int) $pageData['page_number'] : 1;
                $inputDir  = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'extracted';
                $outputDir = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'sentence_fixer';
                @mkdir($outputDir, 0755, true);

                $command = sprintf(
                    '"%s" -m agentic_model.document_manager_agent.sentence_fixer_agent.cli --input-dir "%s" --output-dir "%s" --page-num %d 2>&1',
                    $pythonCmd, $inputDir, $outputDir, $pageNumber
                );
                exec($command, $cmdOutput, $returnVar);

                $result = '';
                $fixedPagePath = $outputDir . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNumber);
                if ($returnVar === 0 && is_file($fixedPagePath)) {
                    $fp = json_decode(file_get_contents($fixedPagePath), true);
                    if (is_array($fp)) {
                        if (isset($fp['fixed_blocks'])) {
                            $result = renderContextBlocks($fp['fixed_blocks']);
                        } else {
                            $result = $fp['fixed'] ?? '';
                        }

                        // Update global sentence_fixer/fixed.json
                        $fixedPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . 'fixed.json';
                        $fixedPages = [];
                        if (is_file($fixedPath)) {
                            $fixedPages = json_decode(file_get_contents($fixedPath), true) ?: [];
                        }
                        $found = false;
                        foreach ($fixedPages as &$gfp) {
                            if ((int) ($gfp['page_number'] ?? -1) === $pageNumber) {
                                $gfp = $fp;
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $fixedPages[] = $fp;
                        }
                        usort($fixedPages, function ($a, $b) {
                            return ($a['page_number'] ?? 0) <=> ($b['page_number'] ?? 0);
                        });
                        @file_put_contents($fixedPath, json_encode($fixedPages, JSON_UNESCAPED_UNICODE));
                    }
                }

                echo json_encode([
                    'success' => !empty($result),
                    'data'    => $result,
                    'log'     => $cmdOutput
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($action === 'fine_tune_page' && $loadedFilename) {
                $pageNumber = isset($pageData['page_number']) ? (int) $pageData['page_number'] : 1;
                
                // Read from fixed sentence folder or extracted folder if fixed doesn't exist
                $fixedDir = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'sentence_fixer';
                $extractedDir = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'extracted';
                $inputDir = is_dir($fixedDir) ? $fixedDir : $extractedDir;
                
                $outputDir = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'fine_tuning';
                @mkdir($outputDir, 0755, true);

                $command = sprintf(
                    '"%s" -m agentic_model.document_manager_agent.fine_tuning_agent.cli --input-dir "%s" --output-dir "%s" --page-num %d 2>&1',
                    $pythonCmd, $inputDir, $outputDir, $pageNumber
                );
                exec($command, $cmdOutput, $returnVar);

                $result = [];
                $fineTunedPagePath = $outputDir . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNumber);
                if ($returnVar === 0 && is_file($fineTunedPagePath)) {
                    $fp = json_decode(file_get_contents($fineTunedPagePath), true);
                    if (is_array($fp) && isset($fp['qa_pairs'])) {
                        $result = $fp['qa_pairs'];
                        
                        // Update global fine_tuning/fine_tuned.json
                        $fineTunedPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json';
                        $fineTunedPairs = [];
                        if (is_file($fineTunedPath)) {
                            $fineTunedPairs = json_decode(file_get_contents($fineTunedPath), true) ?: [];
                        }
                        // Remove old pairs for this page
                        $fineTunedPairs = array_filter($fineTunedPairs, function ($qa) use ($pageNumber) {
                            return (int) ($qa['page_number'] ?? -1) !== $pageNumber;
                        });
                        foreach ($result as $qa) {
                            $fineTunedPairs[] = [
                                'page_number' => $pageNumber,
                                'context' => $qa['context'] ?? '',
                                'question' => trim((string) $qa['question']),
                                'answer' => trim((string) $qa['answer'])
                            ];
                        }
                        usort($fineTunedPairs, function ($a, $b) {
                            return ($a['page_number'] ?? 0) <=> ($b['page_number'] ?? 0);
                        });
                        $fineTunedPairs = array_values($fineTunedPairs);
                        @file_put_contents($fineTunedPath, json_encode($fineTunedPairs, JSON_UNESCAPED_UNICODE));
                    }
                }

                echo json_encode([
                    'success' => !empty($result),
                    'data'    => $result,
                    'log'     => $cmdOutput
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // For generate_embeddings_page: use temp file approach
            $tempIn  = CONTEXT_DIR . DIRECTORY_SEPARATOR . uniqid('single_in_') . '.json';
            $tempOut = CONTEXT_DIR . DIRECTORY_SEPARATOR . uniqid('single_out_') . '.json';
            file_put_contents($tempIn, json_encode([$pageData], JSON_UNESCAPED_UNICODE));

            $command = sprintf('"%s" -m agentic_model.document_manager_agent.embeddings_agent.cli "%s" -o "%s" 2>&1', $pythonCmd, $tempIn, $tempOut);
            exec($command, $cmdOutput, $returnVar);

            $result = [];
            if ($returnVar === 0 && is_file($tempOut)) {
                $outData = json_decode(file_get_contents($tempOut), true);
                if ($outData && count($outData) > 0) {
                    $result = $outData[0];
                    if ($loadedFilename) {
                        $embeddingsDir = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'embeddings';
                        @mkdir($embeddingsDir, 0755, true);
                        $pNum = (int)($result['page_number'] ?? 1);
                        
                        // Save page-level file: embeddings/page_000N.json
                        $pagePath = $embeddingsDir . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pNum);
                        @file_put_contents($pagePath, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        
                        // Update global embeddings.json
                        $embPath    = $embeddingsDir . DIRECTORY_SEPARATOR . 'embeddings.json';
                        $globalEmbs = is_file($embPath) ? (json_decode(file_get_contents($embPath), true) ?: []) : [];
                        $found      = false;
                        foreach ($globalEmbs as &$ge) {
                            if ((int)($ge['page_number'] ?? -1) === $pNum) { $ge = $result; $found = true; break; }
                        }
                        if (!$found) $globalEmbs[] = $result;
                        @file_put_contents($embPath, json_encode($globalEmbs, JSON_UNESCAPED_UNICODE));
                    }
                }
            }

            @unlink($tempIn);
            @unlink($tempOut);

            echo json_encode([
                'success' => !empty($result),
                'data'    => $result,
                'log'     => $cmdOutput
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'fix_sentences_global') {
            $filename = isset($payload['filename']) ? trim((string) $payload['filename']) : '';
            
            $activeFile = $_SESSION['current_pdf'] ?? '';
            $loadedFilename = '';
            if ($activeFile) {
                $loadedFilename = rawurldecode(basename($activeFile));
            } elseif ($filename) {
                $loadedFilename = rawurldecode(basename($filename));
            }

            if (!$loadedFilename) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No active file loaded.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Tell the frontend that we have started the background process
            echo json_encode(['success' => true, 'started' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }


        if ($action === 'fine_tune_global') {
            $filename = isset($payload['filename']) ? trim((string) $payload['filename']) : '';
            $activeFile = $_SESSION['current_pdf'] ?? '';
            $loadedFilename = $activeFile ? rawurldecode(basename($activeFile)) : ($filename ? rawurldecode(basename($filename)) : '');

            if (!$loadedFilename) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No active file loaded.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $fixedPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . 'fixed.json';
            $contextPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . 'context.json';
            $inputPath = is_file($fixedPath) ? $fixedPath : (is_file($contextPath) ? $contextPath : '');

            if (!$inputPath) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Extracted or fixed context file not found.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $fineTunedPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json';
            $progressPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'progress.json';
            if (is_file($progressPath)) {
                @unlink($progressPath);
            }

            $pythonCmd = get_python_executable();
            $command = sprintf(
                '"%s" -m agentic_model.document_manager_agent.fine_tuning_agent.cli "%s" -o "%s"%s 2>&1',
                $pythonCmd,
                $inputPath,
                $fineTunedPath,
                $progressPath ? ' --progress-file "' . $progressPath . '"' : ''
            );

            session_write_close();
            $cmdOutput = [];
            exec($command, $cmdOutput, $returnVar);

            if (is_file($progressPath)) {
                @unlink($progressPath);
            }

            if ($returnVar === 0 && is_file($fineTunedPath)) {
                $qaData = json_decode(file_get_contents($fineTunedPath), true);
                echo json_encode([
                    'success' => true,
                    'qa_pairs' => $qaData
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Fine tuning failed (exit code ' . $returnVar . '): ' . implode("\n", $cmdOutput)
                ], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        if ($action === 'generate_embeddings_global') {
            $filename = isset($payload['filename']) ? trim((string) $payload['filename']) : '';
            $activeFile = $_SESSION['current_pdf'] ?? '';
            $loadedFilename = $activeFile ? rawurldecode(basename($activeFile)) : ($filename ? rawurldecode(basename($filename)) : '');

            if (!$loadedFilename) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No active file loaded.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $fineTunedPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json';
            if (!is_file($fineTunedPath)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Fine-Tuning Q&A dataset not found. Please click "Fine Tuning" first.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $embeddingsPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'embeddings' . DIRECTORY_SEPARATOR . 'embeddings.json';
            $progressPath = getDocumentContextDir($loadedFilename) . DIRECTORY_SEPARATOR . 'progress.json';
            if (is_file($progressPath)) {
                @unlink($progressPath);
            }

            $pythonCmd = get_python_executable();
            $embeddingsDir = dirname($embeddingsPath);
            $command = sprintf(
                '"%s" -m agentic_model.document_manager_agent.embeddings_agent.cli "%s" -o "%s" --output-dir "%s"%s 2>&1',
                $pythonCmd,
                $fineTunedPath,
                $embeddingsPath,
                $embeddingsDir,
                $progressPath ? ' --progress-file "' . $progressPath . '"' : ''
            );

            session_write_close();
            $cmdOutput = [];
            exec($command, $cmdOutput, $returnVar);

            if (is_file($progressPath)) {
                @unlink($progressPath);
            }

            if ($returnVar === 0 && is_file($embeddingsPath)) {
                $embeddingsData = json_decode(file_get_contents($embeddingsPath), true);
                echo json_encode([
                    'success' => true,
                    'embeddings' => $embeddingsData
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Embeddings generation failed (exit code ' . $returnVar . '): ' . implode("\n", $cmdOutput)
                ], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        if ($action === 'save_page') {
            $filename = isset($payload['filename']) ? trim((string) $payload['filename']) : '';
            $pageIndex = isset($payload['page_index']) ? (int) $payload['page_index'] : -1;
            $page = $payload['page'] ?? null;

            if ($filename === '' || $pageIndex < 0 || !is_array($page)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid save page payload.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $safeName = basename($filename);
            $contextDir = getDocumentContextDir($safeName);

            $pageNum = isset($page['page_number']) ? (int) $page['page_number'] : ($pageIndex + 1);
            $sourcePage = isset($page['source_page']) ? (int) $page['source_page'] : ($pageIndex + 1);
            $width = isset($page['width']) ? (float) $page['width'] : 0.0;
            $height = isset($page['height']) ? (float) $page['height'] : 0.0;
            $blocks = $page['blocks'] ?? [];
            $fixedBlocks = $page['fixed_blocks'] ?? [];
            $qaPairs = $page['qa_pairs'] ?? [];

            // 1. Save extracted/page_000N.json
            $extractedPageData = [
                'page_number' => $pageNum,
                'source_page' => $sourcePage,
                'width' => (int) round($width),
                'height' => (int) round($height),
                'blocks' => $blocks
            ];
            $extractedPagePath = $contextDir . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            @file_put_contents($extractedPagePath, json_encode($extractedPageData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // 2. Save sentence_fixer/page_000N.json
            $fixedPageData = [
                'page_number' => $pageNum,
                'source_page' => $sourcePage,
                'width' => (int) round($width),
                'height' => (int) round($height),
                'blocks' => $blocks,
                'fixed_blocks' => $fixedBlocks
            ];
            $fixedPagePath = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            @file_put_contents($fixedPagePath, json_encode($fixedPageData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // 3. Update global extracted/context.json
            $contextPath = $contextDir . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . 'context.json';
            $contextPages = [];
            if (is_file($contextPath)) {
                $contextPages = json_decode(file_get_contents($contextPath), true) ?: [];
            }
            $found = false;
            foreach ($contextPages as &$cp) {
                if ((int) ($cp['page_number'] ?? -1) === $pageNum) {
                    $cp = $extractedPageData;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $contextPages[] = $extractedPageData;
            }
            usort($contextPages, function ($a, $b) {
                return ($a['page_number'] ?? 0) <=> ($b['page_number'] ?? 0);
            });
            @file_put_contents($contextPath, json_encode($contextPages, JSON_UNESCAPED_UNICODE));

            // 4. Update global sentence_fixer/fixed.json
            $fixedPath = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . 'fixed.json';
            $fixedPages = [];
            if (is_file($fixedPath)) {
                $fixedPages = json_decode(file_get_contents($fixedPath), true) ?: [];
            }
            $found = false;
            foreach ($fixedPages as &$fp) {
                if ((int) ($fp['page_number'] ?? -1) === $pageNum) {
                    $fp = $fixedPageData;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $fixedPages[] = $fixedPageData;
            }
            usort($fixedPages, function ($a, $b) {
                return ($a['page_number'] ?? 0) <=> ($b['page_number'] ?? 0);
            });
            @file_put_contents($fixedPath, json_encode($fixedPages, JSON_UNESCAPED_UNICODE));

            // 5. Update global fine_tuning/fine_tuned.json
            $fineTunedPath = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json';
            $fineTunedPairs = [];
            if (is_file($fineTunedPath)) {
                $fineTunedPairs = json_decode(file_get_contents($fineTunedPath), true) ?: [];
            }
            $fineTunedPairs = array_filter($fineTunedPairs, function ($qa) use ($pageNum) {
                return (int) ($qa['page_number'] ?? -1) !== $pageNum;
            });
            $formattedPagePairs = [];
            foreach ($qaPairs as $qa) {
                $formattedPair = [
                    'page_number' => $pageNum,
                    'context' => '',
                    'question' => trim((string) $qa['question']),
                    'answer' => trim((string) $qa['answer'])
                ];
                $fineTunedPairs[] = $formattedPair;
                $formattedPagePairs[] = $formattedPair;
            }
            usort($fineTunedPairs, function ($a, $b) {
                return ($a['page_number'] ?? 0) <=> ($b['page_number'] ?? 0);
            });
            $fineTunedPairs = array_values($fineTunedPairs);
            @file_put_contents($fineTunedPath, json_encode($fineTunedPairs, JSON_UNESCAPED_UNICODE));

            // Save fine_tuning/page_000N.json
            $fineTunedPageData = [
                'page_number' => $pageNum,
                'source_page' => $sourcePage,
                'width' => (int) round($width),
                'height' => (int) round($height),
                'blocks' => $blocks,
                'fixed_blocks' => $fixedBlocks,
                'qa_pairs' => $formattedPagePairs
            ];
            $fineTunedPagePath = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            @mkdir(dirname($fineTunedPagePath), 0755, true);
            @file_put_contents($fineTunedPagePath, json_encode($fineTunedPageData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            echo json_encode(['success' => true, 'message' => 'Page context saved.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'delete_page') {
            $filename = isset($payload['filename']) ? trim((string) $payload['filename']) : '';
            $pageNum = isset($payload['page_number']) ? (int) $payload['page_number'] : -1;

            if ($filename === '' || $pageNum < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid delete page payload.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $safeName = basename($filename);
            $contextDir = getDocumentContextDir($safeName);

            // 1. Delete extracted/page_000N.json
            $extractedPagePath = $contextDir . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            if (is_file($extractedPagePath)) {
                @unlink($extractedPagePath);
            }

            // 2. Delete sentence_fixer/page_000N.json
            $fixedPagePath = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            if (is_file($fixedPagePath)) {
                @unlink($fixedPagePath);
            }

            // 2.b Delete fine_tuning/page_000N.json
            $fineTunedPagePath = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            if (is_file($fineTunedPagePath)) {
                @unlink($fineTunedPagePath);
            }

            // 2.c Delete embeddings/page_000N.json
            $embeddingsPagePath = $contextDir . DIRECTORY_SEPARATOR . 'embeddings' . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            if (is_file($embeddingsPagePath)) {
                @unlink($embeddingsPagePath);
            }

            // 3. Update global extracted/context.json
            $contextPath = $contextDir . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . 'context.json';
            if (is_file($contextPath)) {
                $contextPages = json_decode(file_get_contents($contextPath), true) ?: [];
                $contextPages = array_filter($contextPages, function ($cp) use ($pageNum) {
                    return (int) ($cp['page_number'] ?? -1) !== $pageNum;
                });
                $contextPages = array_values($contextPages);
                @file_put_contents($contextPath, json_encode($contextPages, JSON_UNESCAPED_UNICODE));
            }

            // 4. Update global sentence_fixer/fixed.json
            $fixedPath = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . 'fixed.json';
            if (is_file($fixedPath)) {
                $fixedPages = json_decode(file_get_contents($fixedPath), true) ?: [];
                $fixedPages = array_filter($fixedPages, function ($fp) use ($pageNum) {
                    return (int) ($fp['page_number'] ?? -1) !== $pageNum;
                });
                $fixedPages = array_values($fixedPages);
                @file_put_contents($fixedPath, json_encode($fixedPages, JSON_UNESCAPED_UNICODE));
            }

            // 5. Update global fine_tuning/fine_tuned.json
            $fineTunedPath = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json';
            if (is_file($fineTunedPath)) {
                $fineTunedPairs = json_decode(file_get_contents($fineTunedPath), true) ?: [];
                $fineTunedPairs = array_filter($fineTunedPairs, function ($qa) use ($pageNum) {
                    return (int) ($qa['page_number'] ?? -1) !== $pageNum;
                });
                $fineTunedPairs = array_values($fineTunedPairs);
                @file_put_contents($fineTunedPath, json_encode($fineTunedPairs, JSON_UNESCAPED_UNICODE));
            }

            // 6. Update global embeddings/embeddings.json
            $embeddingsPath = $contextDir . DIRECTORY_SEPARATOR . 'embeddings' . DIRECTORY_SEPARATOR . 'embeddings.json';
            if (is_file($embeddingsPath)) {
                $embeddingsData = json_decode(file_get_contents($embeddingsPath), true) ?: [];
                $embeddingsData = array_filter($embeddingsData, function ($emb) use ($pageNum) {
                    return (int) ($emb['page_number'] ?? -1) !== $pageNum;
                });
                $embeddingsData = array_values($embeddingsData);
                @file_put_contents($embeddingsPath, json_encode($embeddingsData, JSON_UNESCAPED_UNICODE));
            }

            echo json_encode(['success' => true, 'message' => 'Page deleted.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action !== 'save_context') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid save request.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pages = $payload['pages'] ?? null;
        $filename = isset($payload['filename']) ? trim((string) $payload['filename']) : '';

        if ($filename === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing filename for save.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!is_array($pages)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid pages payload.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $normalizedPages = [];
        $fixedPages = [];
        $fineTunedPairs = [];
        foreach ($pages as $pageIndex => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageNumber = isset($page['page_number']) ? (int) $page['page_number'] : ($pageIndex + 1);
            if ($pageNumber < 1) {
                $pageNumber = $pageIndex + 1;
            }

            $width = isset($page['width']) ? (float) $page['width'] : 0.0;
            $height = isset($page['height']) ? (float) $page['height'] : 0.0;
            $sourcePage = isset($page['source_page']) ? (int) $page['source_page'] : ($pageIndex + 1);
            if ($sourcePage < 1) {
                $sourcePage = $pageIndex + 1;
            }

            $blocks = $page['blocks'] ?? [];
            $normalizedBlocks = [];
            if (is_array($blocks)) {
                foreach ($blocks as $block) {
                    if (!is_array($block)) {
                        continue;
                    }

                    $text = trim((string) ($block['text'] ?? ''));
                    if ($text === '') {
                        continue;
                    }

                    $kind = trim((string) ($block['kind'] ?? 'Text'));
                    if ($kind === '') {
                        $kind = 'Text';
                    }

                    $normalizedBlock = [
                        'kind' => $kind,
                        'x' => isset($block['x']) ? (float) $block['x'] : 0.0,
                        'y' => isset($block['y']) ? (float) $block['y'] : 0.0,
                        'w' => isset($block['w']) ? (float) $block['w'] : 0.0,
                        'h' => isset($block['h']) ? (float) $block['h'] : 0.0,
                        'text' => $text
                    ];


                    $normalizedBlocks[] = $normalizedBlock;
                }
            }

            usort($normalizedBlocks, function (array $a, array $b): int {
                $ay = isset($a['y']) ? (float) $a['y'] : 0.0;
                $by = isset($b['y']) ? (float) $b['y'] : 0.0;
                if (abs($ay - $by) > 0.5) {
                    return $ay <=> $by;
                }
                $ax = isset($a['x']) ? (float) $a['x'] : 0.0;
                $bx = isset($b['x']) ? (float) $b['x'] : 0.0;
                return $ax <=> $bx;
            });

            $fixedBlocks = $page['fixed_blocks'] ?? [];
            $normalizedFixedBlocks = [];
            if (is_array($fixedBlocks)) {
                foreach ($fixedBlocks as $block) {
                    if (!is_array($block)) continue;
                    $text = trim((string) ($block['text'] ?? ''));
                    if ($text === '') continue;
                    $normalizedFixedBlocks[] = [
                        'kind' => trim((string) ($block['kind'] ?? 'Text')) ?: 'Text',
                        'text' => $text
                    ];
                }
            }

            $pageQaPairs = $page['qa_pairs'] ?? [];
            if (is_array($pageQaPairs)) {
                foreach ($pageQaPairs as $qa) {
                    if (!is_array($qa)) continue;
                    $q = trim((string)($qa['question'] ?? ''));
                    $a = trim((string)($qa['answer'] ?? ''));
                    if ($q !== '' && $a !== '') {
                        $fineTunedPairs[] = [
                            'page_number' => $pageNumber,
                            'context' => '',
                            'question' => $q,
                            'answer' => $a
                        ];
                    }
                }
            }

            $normalizedPages[] = [
                'page_number' => $pageNumber,
                'source_page' => $sourcePage,
                'width' => (int) round($width),
                'height' => (int) round($height),
                'blocks' => $normalizedBlocks
            ];
            
            $fixedPages[] = [
                'page_number' => $pageNumber,
                'source_page' => $sourcePage,
                'width' => (int) round($width),
                'height' => (int) round($height),
                'blocks' => $normalizedBlocks,
                'fixed_blocks' => $normalizedFixedBlocks
            ];
        }

        $encoded = json_encode($normalizedPages, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to encode context JSON.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Write to per-file context folder
        $safeName = basename($filename);
        $contextDir = ensureDocumentContextDir($safeName);
        $contextOutPath = $contextDir . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . 'context.json';
        $written = @file_put_contents($contextOutPath, $encoded);
        $pagesWritten = writePageJsonFiles($safeName, $normalizedPages);
        
        $fixedOutPath = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer' . DIRECTORY_SEPARATOR . 'fixed.json';
        $fineTunedPath = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json';
        @file_put_contents($fixedOutPath, json_encode($fixedPages, JSON_UNESCAPED_UNICODE));
        @file_put_contents($fineTunedPath, json_encode($fineTunedPairs, JSON_UNESCAPED_UNICODE));

        // Save per-page sentence fixer files
        $fixerDir = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer';
        @mkdir($fixerDir, 0755, true);
        foreach ($fixedPages as $fp) {
            $pageNum = $fp['page_number'];
            $fpPath = $fixerDir . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            @file_put_contents($fpPath, json_encode($fp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        // Save per-page fine tuning files
        $fineTuningDir = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning';
        @mkdir($fineTuningDir, 0755, true);
        $qaByPage = [];
        foreach ($fineTunedPairs as $qa) {
            $pNum = (int)($qa['page_number'] ?? 1);
            $qaByPage[$pNum][] = $qa;
        }
        foreach ($fixedPages as $fp) {
            $pageNum = $fp['page_number'];
            $ftpPath = $fineTuningDir . DIRECTORY_SEPARATOR . sprintf('page_%04d.json', $pageNum);
            $ftpPageData = $fp;
            $ftpPageData['qa_pairs'] = $qaByPage[$pageNum] ?? [];
            @file_put_contents($ftpPath, json_encode($ftpPageData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        if ($written === false || !$pagesWritten) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to write extracted context files.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Context saved.',
            'saved_pages' => count($normalizedPages)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Handle Folder Upload and Reconstruction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files']) && isset($_POST['paths'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $files = $_FILES['files'];
    $paths = $_POST['paths'];
    
    if (!is_array($paths) || !isset($files['name']) || !is_array($files['name'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid upload payload.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Find the PDF file in the upload paths to determine the actual document name
    $pdfFileIndex = -1;
    $pdfFileName = '';
    for ($i = 0; $i < count($paths); $i++) {
        $pathLower = strtolower($paths[$i]);
        if (substr($pathLower, -4) === '.pdf') {
            $pdfFileIndex = $i;
            $pdfFileName = sanitize_filename(basename($paths[$i]));
            break;
        }
    }

    if ($pdfFileIndex === -1 || empty($pdfFileName)) {
        echo json_encode(['success' => false, 'error' => 'No PDF file found in the selected folder.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rootFolder = $pdfFileName; // E.g. Policies_on_Faculty_Workload.pdf
    $targetRootDir = CONTEXT_DIR . DIRECTORY_SEPARATOR . $rootFolder;
    if (!is_dir($targetRootDir)) {
        @mkdir($targetRootDir, 0755, true);
    }

    // Clear target folder if overwriting
    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';
    if ($overwrite) {
        clearDirectoryFiles($targetRootDir);
        clearDirectoryFiles($targetRootDir . DIRECTORY_SEPARATOR . 'extracted');
        clearDirectoryFiles($targetRootDir . DIRECTORY_SEPARATOR . 'sentence_fixer');
        clearDirectoryFiles($targetRootDir . DIRECTORY_SEPARATOR . 'fine_tuning');
        clearDirectoryFiles($targetRootDir . DIRECTORY_SEPARATOR . 'embeddings');
    }

    $uploadedCount = 0;
    $errorCount = 0;

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errorCount++;
            continue;
        }

        $relPath = $paths[$i];
        $cleanPath = preg_replace('/[^a-zA-Z0-9\/._-]/', '_', str_replace('\\', '/', $relPath));
        $cleanPath = ltrim($cleanPath, '/');
        $cleanPath = preg_replace('/\.\.+\//', '', $cleanPath);

        // Replace the original root directory of the path with our standardized $rootFolder
        $parts = explode('/', $cleanPath);
        if (count($parts) > 1) {
            array_shift($parts); // Remove the original local root folder name (e.g. "my_run")
            $cleanPath = $rootFolder . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        } else {
            // It's the PDF file itself at the root of the selected folder
            $cleanPath = $rootFolder . DIRECTORY_SEPARATOR . $rootFolder;
        }

        $targetPath = CONTEXT_DIR . DIRECTORY_SEPARATOR . $cleanPath;
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        if (move_uploaded_file($files['tmp_name'][$i], $targetPath)) {
            $uploadedCount++;
        } else {
            $errorCount++;
        }
    }

    $pdfPath = $targetRootDir . DIRECTORY_SEPARATOR . $rootFolder;
    if (is_file($pdfPath)) {
        $_SESSION['current_pdf'] = $rootFolder;
        echo json_encode([
            'success' => true,
            'filename' => $rootFolder,
            'uploaded' => $uploadedCount,
            'errors' => $errorCount
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to verify PDF file placement after upload.',
            'uploaded' => $uploadedCount,
            'errors' => $errorCount
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Handle File Upload and Background Parse Start
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    $file = $_FILES['pdf_file'];
    $validationErrors = validate_file_upload($file);
    if (!empty($validationErrors)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => implode("\n", $validationErrors)], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $filename = sanitize_filename($file['name']);
        $contextDir = ensureDocumentContextDir($filename);
        $targetPath = $contextDir . DIRECTORY_SEPARATOR . $filename;
        $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

        if (is_file($targetPath) && !$overwrite) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'File already exists. Confirm overwrite.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($overwrite && $filename) {
            clearDirectoryFiles($contextDir);
            clearDirectoryFiles($contextDir . DIRECTORY_SEPARATOR . 'extracted');
            clearDirectoryFiles($contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer');
            clearDirectoryFiles($contextDir . DIRECTORY_SEPARATOR . 'fine_tuning');
            clearDirectoryFiles($contextDir . DIRECTORY_SEPARATOR . 'embeddings');
        }

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['current_pdf'] = $filename;

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'filename' => $filename], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Handle Parse Start
if (isset($_GET['start_parse']) && isset($_GET['filename'])) {
    $filename = sanitize_filename(basename((string) $_GET['filename']));
    $contextDir = ensureDocumentContextDir($filename);
    $targetPath = $contextDir . DIRECTORY_SEPARATOR . $filename;
    
    $pythonCmd = get_python_executable();
    $contextOutPath = $contextDir . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . 'context.json';
    $progressPath = $contextDir . DIRECTORY_SEPARATOR . 'progress.json';
    $cancelPath = $contextDir . DIRECTORY_SEPARATOR . 'cancel.flag';
    $outputDir = $contextDir . DIRECTORY_SEPARATOR . 'extracted';
    $pidPath = $contextDir . DIRECTORY_SEPARATOR . 'parser.pid';

    if (is_file($progressPath)) {
        @unlink($progressPath);
    }
    if (is_file($cancelPath)) {
        @unlink($cancelPath);
    }

    checkAndKillPreviousProcess($pidPath);

    $command = sprintf(
        '"%s" -m agentic_model.document_manager_agent.document_parser_agent.cli "%s" --no-summary -o "%s" --progress-file "%s" --output-dir "%s" --cancel-file "%s" --pid-file "%s" > "%s" 2>&1',
        $pythonCmd,
        $targetPath,
        $contextOutPath,
        $progressPath,
        $outputDir,
        $cancelPath,
        $pidPath,
        $contextDir . DIRECTORY_SEPARATOR . 'parser.log'
    );

    startBackgroundProcess($command);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle Sentence Fixer Start
if (isset($_GET['start_sentence_fixer']) && isset($_GET['filename'])) {
    $filename   = sanitize_filename(basename((string) $_GET['filename']));
    $contextDir = ensureDocumentContextDir($filename);

    $pythonCmd    = get_python_executable();
    $progressPath = $contextDir . DIRECTORY_SEPARATOR . 'fixer_progress.json';
    $cancelPath   = $contextDir . DIRECTORY_SEPARATOR . 'cancel_fixer.flag';
    $inputDir     = $contextDir . DIRECTORY_SEPARATOR . 'extracted';
    $outputDir    = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer';
    $logPath      = $contextDir . DIRECTORY_SEPARATOR . 'fixer.log';
    $pidPath      = $contextDir . DIRECTORY_SEPARATOR . 'fixer.pid';

    // Clean up previous run artifacts
    if (is_file($progressPath))   @unlink($progressPath);
    if (is_file($cancelPath))     @unlink($cancelPath);
    @mkdir($outputDir, 0755, true);

    checkAndKillPreviousProcess($pidPath);

    $command = sprintf(
        '"%s" -m agentic_model.document_manager_agent.sentence_fixer_agent.cli --input-dir "%s" --output-dir "%s" --progress-file "%s" --cancel-file "%s" --pid-file "%s" > "%s" 2>&1',
        $pythonCmd, $inputDir, $outputDir, $progressPath, $cancelPath, $pidPath, $logPath
    );

    startBackgroundProcess($command);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle Fine Tuning Start
if (isset($_GET['start_fine_tuning']) && isset($_GET['filename'])) {
    $filename   = sanitize_filename(basename((string) $_GET['filename']));
    $contextDir = ensureDocumentContextDir($filename);

    $pythonCmd    = get_python_executable();
    $progressPath = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning_progress.json';
    $cancelPath   = $contextDir . DIRECTORY_SEPARATOR . 'cancel_fine_tuning.flag';
    
    // We read from the fixed sentence folder or extracted folder if fixed doesn't exist
    $fixedDir = $contextDir . DIRECTORY_SEPARATOR . 'sentence_fixer';
    $extractedDir = $contextDir . DIRECTORY_SEPARATOR . 'extracted';
    $inputDir = is_dir($fixedDir) ? $fixedDir : $extractedDir;
    
    $outputDir    = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning';
    $logPath      = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning.log';
    $globalOut    = $outputDir . DIRECTORY_SEPARATOR . 'fine_tuned.json';
    $pidPath      = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning.pid';

    // Clean up previous run artifacts
    if (is_file($progressPath))   @unlink($progressPath);
    if (is_file($cancelPath))     @unlink($cancelPath);
    @mkdir($outputDir, 0755, true);

    checkAndKillPreviousProcess($pidPath);

    $command = sprintf(
        '"%s" -m agentic_model.document_manager_agent.fine_tuning_agent.cli --input-dir "%s" --output-dir "%s" -o "%s" --progress-file "%s" --cancel-file "%s" --pid-file "%s" > "%s" 2>&1',
        $pythonCmd, $inputDir, $outputDir, $globalOut, $progressPath, $cancelPath, $pidPath, $logPath
    );

    startBackgroundProcess($command);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle Embeddings Start
if (isset($_GET['start_embeddings']) && isset($_GET['filename'])) {
    $filename   = sanitize_filename(basename((string) $_GET['filename']));
    $contextDir = ensureDocumentContextDir($filename);

    $pythonCmd    = get_python_executable();
    $progressPath = $contextDir . DIRECTORY_SEPARATOR . 'embeddings_progress.json';
    $cancelPath   = $contextDir . DIRECTORY_SEPARATOR . 'cancel_embeddings.flag';
    $fineTunedPath = $contextDir . DIRECTORY_SEPARATOR . 'fine_tuning' . DIRECTORY_SEPARATOR . 'fine_tuned.json';
    $embeddingsPath = $contextDir . DIRECTORY_SEPARATOR . 'embeddings' . DIRECTORY_SEPARATOR . 'embeddings.json';
    $logPath      = $contextDir . DIRECTORY_SEPARATOR . 'embeddings.log';
    $pidPath      = $contextDir . DIRECTORY_SEPARATOR . 'embeddings.pid';

    // Clean up previous run artifacts
    if (is_file($progressPath))   @unlink($progressPath);
    if (is_file($cancelPath))     @unlink($cancelPath);
    @mkdir(dirname($embeddingsPath), 0755, true);

    checkAndKillPreviousProcess($pidPath);

    $embeddingsDir = dirname($embeddingsPath);
    $command = sprintf(
        '"%s" -m agentic_model.document_manager_agent.embeddings_agent.cli "%s" -o "%s" --output-dir "%s" --progress-file "%s" --cancel-file "%s" --pid-file "%s" > "%s" 2>&1',
        $pythonCmd, $fineTunedPath, $embeddingsPath, $embeddingsDir, $progressPath, $cancelPath, $pidPath, $logPath
    );

    startBackgroundProcess($command);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load existing per-file context if a PDF was selected/requested
if ($pdfUrl) {
    $loadedFilename = rawurldecode(basename($pdfUrl));
    $contextDir = ensureDocumentContextDir($loadedFilename);
    $contextPath = $contextDir . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . 'context.json';

    $data = loadPageJsonFiles($loadedFilename);
    if (empty($data) && is_file($contextPath)) {
        $raw = file_get_contents($contextPath);
        $legacyData = json_decode($raw, true);
        if (is_array($legacyData)) {
            $data = $legacyData;
            writePageJsonFiles($loadedFilename, $legacyData);
            // Re-load to get all the merged data!
            $data = loadPageJsonFiles($loadedFilename);
        }
    }

    if (is_array($data) && !empty($data)) {
        $extractedPages = $data;
        $totalPages = count($extractedPages);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Layout Parser</title>
    <link rel="icon" type="image/png" href="public/images/favicon.png">
    <!-- Local Dependencies -->
    <link rel="stylesheet" href="public/css/bootstrap.min.css">
    <link rel="stylesheet" href="public/css/all.min.css">
    <link rel="stylesheet" href="public/css/style.css">
    <script src="public/js/pdf.min.js"></script>
</head>

<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-theme-header border-bottom shadow-sm sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center fw-bold text-white" href="#">
                <img src="public/images/favicon.png" alt="Logo" width="24" height="24"
                    class="me-2 rounded-circle shadow-sm">
                PDF Layout Parser
            </a>
            <div class="d-flex gap-2 align-items-center">
               <button type="button" class="btn btn-outline-primary btn-sm" id="embeddings-btn" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Generate vector representation for all pages" <?= !$pdfUrl ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-database me-1"></i> Embeddings
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm" id="train-ai-btn" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Generate fine-tuning Q&A dataset across all pages" <?= !$pdfUrl ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-sliders me-1"></i> Fine Tuning
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm" id="global-fix-btn-header" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Sentence Fixer (Fix layout columns, spaces, hyphens across all pages)" <?= !$pdfUrl ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Sentence Fixer
                </button>
                <form class="d-flex gap-2 upload-section" method="POST" enctype="multipart/form-data">
                    <input type="file" name="pdf_file" accept=".pdf" id="file-input" style="display:none">
                    <button type="button" class="btn btn-primary btn-sm" id="upload-parse-btn" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Upload a new PDF document and parse its layout context">
                        <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload & Parse
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="load-folder-btn" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Select and load existing parsed document directory" style="background: rgba(255, 255, 255, 0.12) !important;">
                        <i class="fa-solid fa-folder-open me-1"></i> Load Extracted
                    </button>
                    <button type="button" class="btn btn-sm" id="clear-btn" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Reset application state and close active document">
                        <i class="fa-solid fa-broom me-1"></i> Clear
                    </button>
                </form>
            </div>
        </div>
    </nav>

    <div id="toast-container" class="position-fixed top-0 end-0 p-3" style="z-index: 1060;"></div>

    <main class="container-fluid p-0">
        <input type="hidden" id="pdf-url" value="<?= htmlspecialchars($pdfUrl) ?>">

        <div class="row g-0 h-100-vh">
            <!-- Left Panel: PDF Viewer -->
            <section class="col-lg-5 border-end bg-secondary-subtle overflow-hidden d-flex flex-column">
                <div class="section-header d-flex justify-content-between align-items-center">
                    <span class="section-title">Original PDF</span>
                    <span
                        class="badge bg-light text-muted border"><?= $pdfUrl ? htmlspecialchars(urldecode(basename($pdfUrl))) : 'No file loaded' ?></span>
                </div>
                <div class="flex-grow-1 overflow-auto p-4" id="pdf-scroll-container">
                    <div id="pdf-viewer" class="position-relative">
                        <!-- Drag & drop overlay -->
                        <div id="drop-zone"
                            class="position-absolute top-0 start-0 w-100 h-100 bg-primary bg-opacity-10 border border-primary border-2 border-dashed rounded d-none align-items-center justify-content-center"
                            style="z-index: 100; pointer-events:none;">
                            <div class="text-primary fw-bold">Drop PDF to upload</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Right Panel: Extracted Context -->
            <section class="col-lg-7 d-flex flex-column overflow-hidden bg-light">
                <div class="section-header d-flex justify-content-between align-items-center">
                    <span class="section-title">Extracted Context</span>
                    <div class="d-flex align-items-center gap-3">
                        <span id="context-page-count"
                            class="small text-muted"><?= $totalPages > 0 ? "$totalPages pages" : '' ?></span>
                        <?php if (!empty($extractedPages)): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm px-3" id="global-fix-btn" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Sentence Fixer (Fix layout columns, spaces, hyphens across all pages)">
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Sentence Fixer
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex-grow-1 overflow-auto p-4" id="text-scroll-container">
                    <?php if (empty($extractedPages)): ?>
                        <div class="d-flex flex-column align-items-center justify-content-center h-100 text-muted">
                            <i class="fa-solid fa-file-import fa-4x mb-3 opacity-25"></i>
                            <h5 class="fw-bold">No document loaded</h5>
                            <p class="small">Upload a PDF file to begin extraction.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($extractedPages as $pageIndex => $page): ?>
                            <?php
                            $displayPageNum = $pageIndex + 1;
                            $pageNum = isset($page['page_number']) ? (int) $page['page_number'] : $displayPageNum;
                            if ($pageNum < 1)
                                $pageNum = $displayPageNum;
                            $sourcePageNum = isset($page['source_page']) ? (int) $page['source_page'] : $displayPageNum;
                            if ($sourcePageNum < 1)
                                $sourcePageNum = $displayPageNum;
                            $pageWidth = isset($page['width']) ? (float) $page['width'] : 0.0;
                            $pageHeight = isset($page['height']) ? (float) $page['height'] : 0.0;
                            $blocks = $page['blocks'] ?? [];
                            ?>
                            <div class="card shadow-sm mb-5 context-page mx-auto border-0" data-page="<?= $displayPageNum ?>"
                                data-source-page="<?= $sourcePageNum ?>" data-page-index="<?= $pageIndex ?>"
                                data-page-width="<?= htmlspecialchars((string) $pageWidth, ENT_QUOTES, 'UTF-8') ?>"
                                data-page-height="<?= htmlspecialchars((string) $pageHeight, ENT_QUOTES, 'UTF-8') ?>"
                                style="max-width: 850px;">

                                <div class="card-header bg-white border-bottom-0 pt-4 px-4 d-flex align-items-center">
                                    <div class="input-group rounded-pill overflow-hidden shadow-sm" style="width: 200px;">
                                        <span class="input-group-text border-0 bg-light text-muted fw-bold text-uppercase px-3" style="font-size: 0.65rem; letter-spacing: 1px;">Page</span>
                                        <input type="number" min="1" step="1"
                                            class="form-control border-0 fw-bold text-primary page-number-input"
                                            value="<?= $pageNum ?>" readonly>
                                    </div>
                                    
                                    <div class="ms-auto d-flex gap-2">
                                        <button type="button" class="btn page-action-btn page-save-btn text-success" data-bs-toggle="tooltip" data-bs-placement="top" title="Save changes" style="display:none;">
                                            <i class="fa-solid fa-floppy-disk"></i>
                                        </button>
                                        <button type="button" class="btn page-action-btn page-fix-btn text-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Re-execute Sentence Fixer for this page">
                                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                                        </button>
                                        <button type="button" class="btn page-action-btn page-tune-btn text-warning" data-bs-toggle="tooltip" data-bs-placement="top" title="Re-execute Fine Tuning for this page">
                                            <i class="fa-solid fa-sliders"></i>
                                        </button>
                                        <button type="button" class="btn page-action-btn page-embeddings-btn text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Re-execute Embeddings for this page">
                                            <i class="fa-solid fa-database"></i>
                                        </button>
                                        <button type="button" class="btn page-action-btn page-add-btn" data-bs-toggle="tooltip" data-bs-placement="top" title="Add page after">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                        <button type="button" class="btn page-action-btn page-edit-btn" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit this page">
                                            <i class="fa-solid fa-pen-to-square icon-edit"></i>
                                            <i class="fa-solid fa-check icon-done" style="display:none;"></i>
                                        </button>
                                        <button type="button" class="btn page-action-btn page-delete-btn" data-bs-toggle="tooltip" data-bs-placement="top" title="Remove this page">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="card-body px-4 pb-4">
                                    <ul class="nav nav-pills nav-fill mb-3 page-tabs" role="tablist">
                                        <li class="nav-item">
                                            <button class="nav-link active small py-1 tab-btn" data-tab="text"
                                                type="button">Extracted Context</button>
                                        </li>
                                        <li class="nav-item">
                                            <button class="nav-link small py-1 tab-btn" data-tab="fixed"
                                                type="button">Fixed Sentence</button>
                                        </li>
                                        <li class="nav-item">
                                            <button class="nav-link small py-1 tab-btn" data-tab="fine-tuning"
                                                type="button">Fine-Tuning</button>
                                        </li>
                                        <li class="nav-item">
                                            <button class="nav-link small py-1 tab-btn" data-tab="embeddings"
                                                type="button">Embeddings</button>
                                        </li>
                                    </ul>

                                    <div class="tab-content">
                                        <div class="tab-pane fade show active tab-content-inner text">
                                            <div class="page-editor rounded p-3 bg-light border" contenteditable="false"
                                                spellcheck="false">
                                                <?php echo renderContextBlocks($blocks); ?>
                                            </div>
                                        </div>

                                        <div class="tab-pane fade tab-content-inner fixed position-relative">
                                            <div class="d-flex justify-content-end mb-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary regen-btn" data-action="fix_sentences_page" data-bs-toggle="tooltip" data-bs-placement="top" title="Run sentence fixing algorithm on this page text">
                                                    <i class="fa-solid fa-arrows-rotate"></i> Regenerate Fixed Sentence
                                                </button>
                                            </div>
                                            <div class="fixed-editor rounded p-3 bg-light border" contenteditable="false"
                                                spellcheck="false">
                                                <?php 
                                                    $fixedBlocks = $page['fixed_blocks'] ?? [];
                                                    if (!empty($fixedBlocks)) {
                                                        echo renderContextBlocks($fixedBlocks);
                                                    } else {
                                                        echo '<div class="text-muted fst-italic">Click "Sentence Fixer" to process this text.</div>';
                                                    }
                                                ?>
                                            </div>
                                        </div>

                                        <div class="tab-pane fade tab-content-inner fine-tuning position-relative">
                                            <div class="d-flex justify-content-end mb-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary regen-btn" data-action="fine_tune_page" data-bs-toggle="tooltip" data-bs-placement="top" title="Generate Q&A dataset based on this page text">
                                                    <i class="fa-solid fa-arrows-rotate"></i> Regenerate Q&A Pairs
                                                </button>
                                            </div>
                                            <div class="qa-container">
                                                <?php 
                                                $pageQa = $page['qa_pairs'] ?? [];
                                                if (!empty($pageQa)): ?>
                                                    <div class="qa-list style-scrollbar" style="min-height: 300px; max-height: 100%; overflow-y: auto; padding-right: 5px;">
                                                        <div class="d-flex flex-column gap-3 pb-2">
                                                            <?php foreach ($pageQa as $qIdx => $qa): ?>
                                                                <div class="qa-row card shadow-sm border border-light bg-white rounded-3">
                                                                    <div class="card-body p-3 position-relative">
                                                                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 fw-bold">Q&A Pair <?= $qIdx + 1 ?></span>
                                                                            <button type="button" class="btn btn-sm btn-outline-danger qa-delete-btn" style="display:none;" data-bs-toggle="tooltip" data-bs-placement="top" title="Remove QA"><i class="fa-solid fa-trash-can"></i></button>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="fw-bold text-muted small text-uppercase mb-1 ms-1" style="letter-spacing: 0.5px;"><i class="fa-solid fa-circle-question me-1 text-primary"></i> Question</label>
                                                                            <div class="qa-q-text text-dark fw-medium p-2 bg-light rounded-3 border border-secondary border-opacity-10" contenteditable="false"><?= nl2br(htmlspecialchars((string)$qa['question'])) ?></div>
                                                                        </div>
                                                                        <div>
                                                                            <label class="fw-bold text-muted small text-uppercase mb-1 ms-1" style="letter-spacing: 0.5px;"><i class="fa-solid fa-comment-dots me-1 text-secondary"></i> Answer</label>
                                                                            <div class="qa-a-text text-secondary p-2 bg-light rounded-3 border border-secondary border-opacity-10" contenteditable="false"><?= nl2br(htmlspecialchars((string)$qa['answer'])) ?></div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center py-5 border rounded bg-light border-dashed">
                                                        <i class="fa-solid fa-sliders fa-3x text-muted mb-3 opacity-50"></i>
                                                        <h6 class="fw-bold">Fine-Tuning Dataset</h6>
                                                        <p class="small text-muted mb-0">Generate 20 Q&A pairs for this page by clicking "Fine Tuning" in the header.</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="tab-pane fade tab-content-inner embeddings position-relative">
                                            <div class="d-flex justify-content-end mb-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary regen-btn" data-action="generate_embeddings_page" data-bs-toggle="tooltip" data-bs-placement="top" title="Compute vector embeddings for this page context">
                                                    <i class="fa-solid fa-arrows-rotate"></i> Regenerate Embeddings
                                                </button>
                                            </div>
                                            <div class="embeddings-container">
                                                <?php 
                                                $pageEmbeddings = $page['embeddings_list'] ?? [];
                                                if (!empty($pageEmbeddings)): 
                                                    $pageEmb = $pageEmbeddings[0]; // Exactly one embedding per page!
                                                    $vector = $pageEmb['embeddings'] ?? [];
                                                ?>
                                                    <div class="p-3 border rounded bg-white shadow-sm" style="font-size: 0.82rem;">
                                                        <div class="position-relative">
                                                            <div class="position-absolute top-0 end-0 p-2 me-2 small text-white-50 font-monospace fw-bold" style="font-size: 0.65rem; z-index: 10; pointer-events: none; letter-spacing: 0.5px;">
                                                                SNOWFLAKE-ARCTIC-EMBED2
                                                            </div>
                                                            <div class="bg-dark p-3 rounded style-scrollbar mt-1" style="min-height: 300px; max-height: 600px; overflow-y: auto; border: 1px solid #343a40; box-shadow: inset 0 2px 5px rgba(0,0,0,0.2);">
                                                                <div class="d-flex flex-wrap gap-2 font-monospace" style="font-size: 0.7rem;">
                                                                    <?php foreach ($vector as $v): ?>
                                                                        <?php
                                                                        $val = (float)$v;
                                                                        // Cyan for positive, Pink for negative
                                                                        $color = $val >= 0 ? '#4cc9f0' : '#f72585';
                                                                        $bg = $val >= 0 ? 'rgba(76, 201, 240, 0.1)' : 'rgba(247, 37, 133, 0.1)';
                                                                        ?>
                                                                        <span class="px-2 py-1 rounded border border-secondary border-opacity-25" style="color: <?= $color ?>; background-color: <?= $bg ?>; min-width: 68px; text-align: center;">
                                                                            <?= number_format($val, 5) ?>
                                                                        </span>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center py-5 border rounded bg-light border-dashed">
                                                        <i class="fa-solid fa-code-branch fa-3x text-muted mb-3 opacity-50"></i>
                                                        <h6 class="fw-bold">Vector Representation</h6>
                                                        <p class="small text-muted mb-0">Generate a page-level vector embedding by clicking "Embeddings" in the header.</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <footer class="bg-theme-header text-white py-2 border-top border-white-10">
        <div class="container-fluid d-flex justify-content-center align-items-center gap-3">
            <span class="small">Copyright 2026 <strong>CODERSTATION</strong>. Alrights Reserved</span>
        </div>
    </footer>

    <!-- Progress Overlay -->
    <div id="progress-overlay"
        class="position-fixed top-0 start-0 w-100 h-100 d-none align-items-center justify-content-center bg-white bg-opacity-75"
        style="z-index: 2000; backdrop-filter: blur(4px);">
        <div class="text-center">
            <div class="progress-logo-container mb-4">
                <img src="public/images/favicon.png" alt="Progress Logo" width="80" height="80" class="progress-logo">
            </div>
            <h5 class="fw-bold text-primary d-flex align-items-center justify-content-center gap-2 mb-2">
                <span id="progress-icon" class="d-flex align-items-center"><i class="fa-solid fa-circle-notch fa-spin"></i></span>
                <span id="progress-title">Parsing Document</span>
            </h5>
            <p class="text-muted small mb-3" id="progress-status">Integrating document structure and layout context...</p>
            
            <div class="progress mt-4 mx-auto shadow-sm" style="width: 320px; height: 16px; border-radius: 8px; background-color: rgba(0,0,0,0.06); overflow: hidden; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%; transition: width 0.3s ease; border-radius: 8px;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div id="progress-percentage-text" class="text-primary fw-bold mt-2" style="font-size: 0.95rem; letter-spacing: 0.5px;">0% (Page 0/0)</div>
            
            <button id="cancel-parse-btn" class="btn btn-outline-danger mt-4 px-4 rounded-pill" data-bs-toggle="tooltip" data-bs-placement="top" title="Interrupt and cancel current active background process">
                <i class="fa-solid fa-xmark me-2"></i> Cancel
            </button>
        </div>
    </div>

    <!-- Template for new page cards (used by JS) -->
    <template id="page-card-template">
        <div class="card shadow-sm mb-5 context-page mx-auto border-0" data-page="" data-source-page="" data-page-index="" data-page-width="" data-page-height="" style="max-width: 850px; opacity: 0; transition: opacity 0.5s ease-in-out;">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 d-flex align-items-center">
                <div class="input-group rounded-pill overflow-hidden shadow-sm" style="width: 200px;">
                    <span class="input-group-text border-0 bg-light text-muted fw-bold text-uppercase px-3" style="font-size: 0.65rem; letter-spacing: 1px;">Page</span>
                    <input type="number" min="1" step="1" class="form-control border-0 fw-bold text-primary page-number-input" value="" readonly>
                </div>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn page-action-btn page-save-btn text-success" data-bs-toggle="tooltip" data-bs-placement="top" title="Save changes" style="display:none;">
                        <i class="fa-solid fa-floppy-disk"></i>
                    </button>
                    <button type="button" class="btn page-action-btn page-fix-btn text-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Re-execute Sentence Fixer for this page">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                    </button>
                    <button type="button" class="btn page-action-btn page-tune-btn text-warning" data-bs-toggle="tooltip" data-bs-placement="top" title="Re-execute Fine Tuning for this page">
                        <i class="fa-solid fa-sliders"></i>
                    </button>
                    <button type="button" class="btn page-action-btn page-embeddings-btn text-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Re-execute Embeddings for this page">
                        <i class="fa-solid fa-database"></i>
                    </button>
                    <button type="button" class="btn page-action-btn page-add-btn" data-bs-toggle="tooltip" data-bs-placement="top" title="Add page after"><i class="fa-solid fa-plus"></i></button>
                    <button type="button" class="btn page-action-btn page-edit-btn" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit this page">
                        <i class="fa-solid fa-pen-to-square icon-edit"></i>
                        <i class="fa-solid fa-check icon-done" style="display:none;"></i>
                    </button>
                    <button type="button" class="btn page-action-btn page-delete-btn" data-bs-toggle="tooltip" data-bs-placement="top" title="Remove this page"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </div>
            <div class="card-body px-4 pb-4">
                <ul class="nav nav-pills nav-fill mb-3 page-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active small py-1 tab-btn" data-tab="text" type="button">Extracted Context</button></li>
                    <li class="nav-item"><button class="nav-link small py-1 tab-btn" data-tab="fixed" type="button">Fixed Sentence</button></li>
                    <li class="nav-item"><button class="nav-link small py-1 tab-btn" data-tab="fine-tuning" type="button">Fine-Tuning</button></li>
                    <li class="nav-item"><button class="nav-link small py-1 tab-btn" data-tab="embeddings" type="button">Embeddings</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active tab-content-inner text">
                        <div class="page-editor rounded p-3 bg-light border" contenteditable="false" spellcheck="false"></div>
                    </div>
                    <div class="tab-pane fade tab-content-inner fixed position-relative">
                        <div class="d-flex justify-content-end mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary regen-btn" data-action="fix_sentences_page" data-bs-toggle="tooltip" data-bs-placement="top" title="Run sentence fixing algorithm on this page text"><i class="fa-solid fa-arrows-rotate"></i> Regenerate Fixed Sentence</button>
                        </div>
                        <div class="fixed-editor rounded p-3 bg-light border" contenteditable="false" spellcheck="false">
                            <div class="text-muted fst-italic">Click "Sentence Fixer" to process this text.</div>
                        </div>
                    </div>
                    <div class="tab-pane fade tab-content-inner fine-tuning position-relative">
                        <div class="d-flex justify-content-end mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary regen-btn" data-action="fine_tune_page" data-bs-toggle="tooltip" data-bs-placement="top" title="Generate Q&A dataset based on this page text"><i class="fa-solid fa-arrows-rotate"></i> Regenerate Q&A Pairs</button>
                        </div>
                        <div class="qa-container">
                            <div class="text-center py-5 border rounded bg-light border-dashed">
                                <i class="fa-solid fa-sliders fa-3x text-muted mb-3 opacity-50"></i>
                                <h6 class="fw-bold">Fine-Tuning Dataset</h6>
                                <p class="small text-muted mb-0">Generate 20 Q&A pairs for this page by clicking "Fine Tuning" in the header.</p>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade tab-content-inner embeddings position-relative">
                        <div class="d-flex justify-content-end mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary regen-btn" data-action="generate_embeddings_page" data-bs-toggle="tooltip" data-bs-placement="top" title="Compute vector embeddings for this page context"><i class="fa-solid fa-arrows-rotate"></i> Regenerate Embeddings</button>
                        </div>
                        <div class="embeddings-container">
                            <div class="text-center py-5 border rounded bg-light border-dashed">
                                <i class="fa-solid fa-code-branch fa-3x text-muted mb-3 opacity-50"></i>
                                <h6 class="fw-bold">Vector Representation</h6>
                                <p class="small text-muted mb-0">Generate a page-level vector embedding by clicking "Embeddings" in the header.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- Load Folder Modal -->
    <div class="modal fade" id="loadFolderModal" tabindex="-1" aria-labelledby="loadFolderModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content shadow-lg border-0" style="border-radius: 16px; overflow: hidden;">
                 <div class="modal-header text-white border-0 py-3 px-4" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);">
                    <h5 class="modal-title fw-bold d-flex align-items-center" id="loadFolderModalLabel">
                        <i class="fa-solid fa-folder-open me-2"></i> Load Existing Folder
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" data-bs-toggle="tooltip" data-bs-placement="top" title="Close modal"></button>
                </div>
                <div class="modal-body p-4" style="background-color: #f8fafc;">
                    <!-- Option A: Browse Local Folder -->
                    <div class="card mb-4 border-2 border-dashed p-4 text-center bg-white shadow-sm" id="local-folder-dropzone" style="border-color: #cbd5e1; border-radius: 12px; transition: var(--transition);">
                        <div class="py-2">
                            <i class="fa-solid fa-folder-plus fa-3x text-primary mb-3 opacity-75"></i>
                            <h6 class="fw-bold text-dark mb-1">Option 1: Upload a Local Extracted Folder</h6>
                            <p class="text-muted small mb-3">Select the folder on your computer that contains the PDF and its JSON context files.</p>
                            <button type="button" class="btn btn-primary btn-sm px-4 fw-bold" id="select-local-folder-btn" data-bs-toggle="tooltip" data-bs-placement="top" title="Select an extracted document folder from your computer">
                                <i class="fa-solid fa-folder-open me-1"></i> Browse Folder...
                            </button>
                            <input type="file" id="folder-input" webkitdirectory directory multiple style="display:none">
                        </div>
                    </div>
 
                    <!-- Option B: Select from Server -->
                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center">
                        <i class="fa-solid fa-server text-primary me-2"></i> Option 2: Select from Existing Server Folders
                    </h6>
                    <div class="list-group shadow-sm border" id="server-folders-list" style="max-height: 250px; overflow-y: auto; border-radius: 12px;">
                        <div class="text-center py-4 text-muted bg-white">
                            <i class="fa-solid fa-spinner fa-spin me-2"></i> Loading folders...
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 py-3">
                    <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal" data-bs-toggle="tooltip" data-bs-placement="top" title="Close modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="public/js/jquery.min.js"></script>
    <script src="public/js/bootstrap.bundle.min.js"></script>
    <script src="public/js/app.js"></script>
    <script>
        $(document).ready(function () {
            <?php if ($error): ?>
                if (window.showToast) showToast(<?= json_encode($error) ?>, 'danger');
            <?php endif; ?>
            <?php if ($success): ?>
                if (window.showToast) showToast(<?= json_encode($success) ?>, 'success');
            <?php endif; ?>
        });
    </script>
</body>

</html>
